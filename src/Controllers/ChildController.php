<?php

declare(strict_types=1);

namespace Pediacare\Controllers;

use InvalidArgumentException;
use OutOfBoundsException;
use Pediacare\Core\Csrf;
use Pediacare\Core\Flash;
use Pediacare\Core\Request;
use Pediacare\Core\Response;
use Pediacare\Core\View;
use Pediacare\Services\AuthService;
use Pediacare\Services\ChildService;
use Pediacare\Services\GrowthHistoryService;
use Pediacare\Services\PurokService;
use Pediacare\Support\DuplicateConfirmation;
use Pediacare\Support\Permissions;
use Pediacare\Validation\ChildFilterValidator;
use Pediacare\Validation\ChildValidator;

final class ChildController
{
    public function __construct(
        private readonly ChildService $children,
        private readonly PurokService $puroks,
        private readonly AuthService $auth,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly DuplicateConfirmation $confirmation,
        private readonly View $view,
        private readonly ChildFilterValidator $filters,
        private readonly GrowthHistoryService $growth,
        private readonly \Pediacare\Services\ImmunizationService $immunizations,
        private readonly \Pediacare\Services\HealthTimelineService $timeline
    ) {
    }

    public function index(Request $request): Response
    {
        $error = null;
        $filters = $this->filters->validate([]);
        $results = ['children' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'from' => 0, 'to' => 0];
        try {
            $filters = $this->filters->validate($request->query);
            $results = $this->children->search($filters);
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        }
        $pageUrl = static fn (int $page): string => '/children?' . http_build_query(
            array_replace($filters, ['page' => $page]), '', '&', PHP_QUERY_RFC3986
        );

        return new Response($this->view->render('children/index', [
            'title' => 'Children', 'userName' => $this->auth->currentUser()['name'] ?? null,
            'navigation' => $this->navigation('/children'), 'filters' => $filters,
            'ageGroups' => ChildFilterValidator::AGE_GROUPS, 'puroks' => $this->puroks->all(),
            'results' => $results, 'error' => $error,
            'previousUrl' => $results['page'] > 1 ? $pageUrl($results['page'] - 1) : null,
            'nextUrl' => $results['page'] < $results['pages'] ? $pageUrl($results['page'] + 1) : null,
        ]), $error === null ? 200 : 422, [
            'Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store',
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->page();
    }

    public function show(Request $request, string $id, ?string $error = null, array $input = []): Response
    {
        $tabs = ['growth' => 'Growth', 'immunization' => 'Immunization', 'visits' => 'Visits'];
        $tab = $request->query['tab'] ?? 'growth';
        $headers = ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store'];
        if (!is_string($tab) || !isset($tabs[$tab])) {
            return new Response('Choose Growth, Immunization, or Visits.', 400, $headers);
        }
        $child = $this->children->profile($id);
        if ($child === null) {
            return new Response('Child record not found.', 404, $headers);
        }

        try {
            $timeline = $tab === 'visits' ? $this->timeline->forChild((int) $child['id'], $request->query) : null;
        } catch (InvalidArgumentException $exception) {
            return new Response($exception->getMessage(), 422, $headers);
        }
        $timelineUrl = static fn (int $page): string => '/children/' . (int) $child['id'] . '?tab=visits&page=' . $page;

        return new Response($this->view->render('children/profile', [
            'title' => 'Child profile', 'userName' => $this->auth->currentUser()['name'] ?? null,
            'navigation' => $this->navigation('/children'), 'child' => $child,
            'tabs' => $tabs, 'tab' => $tab, 'messages' => $this->flash->pull(),
            'timeline' => $timeline,
            'previousTimelineUrl' => $timeline !== null && $timeline['page'] > 1 ? $timelineUrl($timeline['page'] - 1) : null,
            'nextTimelineUrl' => $timeline !== null && $timeline['page'] < $timeline['pages'] ? $timelineUrl($timeline['page'] + 1) : null,
            'growth' => $tab === 'growth' ? $this->growth->forChild($child) : null,
            'immunization' => $tab === 'immunization' ? $this->immunizations->forChild($child) : null,
            'canImmunize' => Permissions::allows($this->auth->currentUser()['role'] ?? '', 'immunizations.create'),
            'growthIndicators' => ['wfa' => 'Weight-for-age', 'hfa' => 'Length/height-for-age',
                'wfh' => 'Weight-for-length/height', 'bmi' => 'BMI-for-age'],
            'canEdit' => Permissions::allows($this->auth->currentUser()['role'] ?? '', 'children.update'),
            'canArchive' => Permissions::allows($this->auth->currentUser()['role'] ?? '', 'children.archive'),
            'canRestore' => Permissions::allows($this->auth->currentUser()['role'] ?? '', 'children.restore'),
            'canMeasure' => Permissions::allows($this->auth->currentUser()['role'] ?? '', 'measurements.create'),
            'canVisit' => Permissions::allows($this->auth->currentUser()['role'] ?? '', 'visits.create'),
            'csrfToken' => $this->csrf->token(), 'error' => $error,
            'reason' => is_string($input['reason'] ?? null) ? $input['reason'] : '',
            'sections' => [
                'Child details' => ['sex' => 'Sex', 'date_of_birth' => 'Date of birth',
                    'age_months' => 'Age (completed months)', 'place_of_birth' => 'Place of birth'],
                'Home and family' => ['purok_name' => 'Purok or sitio', 'address' => 'Address',
                    'mother_name' => "Mother’s name", 'father_name' => "Father’s name",
                    'guardian_name' => 'Guardian name', 'guardian_relation' => 'Guardian relationship',
                    'contact_no' => 'Contact number', 'philhealth_no' => 'PhilHealth number'],
                'Birth measurements' => ['birth_weight_kg' => 'Birth weight (kg)', 'birth_length_cm' => 'Birth length (cm)'],
                'Notes' => ['notes' => 'Notes'],
            ],
            'tabMessages' => [
                'growth' => 'Record weight and length or height to monitor growth.',
                'immunization' => 'Dose status follows the configured recommended age. Earliest dates also respect the previous dose interval.',
                'visits' => 'Record health visits, referrals, supplements, and preventive services. Review all recorded health events in the timeline below.',
            ],
        ]), $error === null ? 200 : 422, $headers);
    }

    public function edit(Request $request, string $id, ?string $error = null, ?array $input = null): Response
    {
        $child = $this->children->profile($id);
        if ($child === null) {
            return new Response('Child record not found.', 404, [
                'Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store',
            ]);
        }
        $input ??= array_map(static fn (string|int|float|null $value): string => (string) $value, $child);

        return $this->page($input, $error, [], $child);
    }

    public function update(Request $request, string $id): Response
    {
        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }
        try {
            $this->children->update($id, $request->post, (int) $user['id']);
        } catch (OutOfBoundsException $error) {
            return new Response('Child record not found.', 404, [
                'Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store',
            ]);
        } catch (InvalidArgumentException $error) {
            return $this->edit($request, $id, $error->getMessage(), $request->post);
        }
        $this->flash->add('Child details saved.');

        return Response::redirect('/children/' . (int) $id);
    }

    public function archive(Request $request, string $id): Response
    {
        return $this->changeArchive($request, $id, false);
    }

    public function restore(Request $request, string $id): Response
    {
        return $this->changeArchive($request, $id, true);
    }

    private function changeArchive(Request $request, string $id, bool $restore): Response
    {
        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }
        try {
            if ($restore) {
                $this->children->restore($id, $request->post, (int) $user['id']);
            } else {
                $this->children->archive($id, $request->post, (int) $user['id']);
            }
        } catch (OutOfBoundsException $error) {
            return new Response('Child record not found.', 404, [
                'Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store',
            ]);
        } catch (InvalidArgumentException $error) {
            return $this->show($request, $id, $error->getMessage(), $request->post);
        }
        $this->flash->add($restore ? 'Child record restored.' : 'Child record archived.');

        return Response::redirect('/children/' . (int) $id);
    }

    public function store(Request $request): Response
    {
        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }
        $token = $request->post['duplicate_token'] ?? null;
        $confirmed = ($request->post['confirm_duplicate'] ?? null) === '1'
            && $this->confirmation->verify($this->values($request->post), is_string($token) ? $token : null);
        try {
            $result = $this->children->register($request->post, (int) $user['id'], $confirmed);
        } catch (InvalidArgumentException $error) {
            return $this->page($request->post, $error->getMessage());
        }
        if ($result->recordNumber === null) {
            return $this->page($request->post, null, $result->duplicates);
        }
        $this->confirmation->clear();
        $this->flash->add('Child registered. Record number: ' . $result->recordNumber . '.');

        return Response::redirect('/children/' . $result->childId);
    }

    private function values(array $input): array
    {
        $values = [];
        foreach ([...array_keys(ChildValidator::TEXT_FIELDS), 'sex', 'date_of_birth', 'purok_id',
            'birth_weight_kg', 'birth_length_cm'] as $field) {
            $values[$field] = is_string($input[$field] ?? null) ? $input[$field] : '';
        }

        return $values;
    }

    private function page(array $input = [], ?string $error = null, array $duplicates = [], ?array $child = null): Response
    {
        $user = $this->auth->currentUser();
        $values = $this->values($input);
        return new Response($this->view->render('children/register', [
            'title' => $child === null ? 'Register child' : 'Edit child', 'userName' => $user['name'] ?? null,
            'navigation' => $this->navigation($child === null ? '/children/new' : '/children'),
            'formAction' => $child === null ? '/children' : '/children/' . $child['id'] . '/update',
            'intro' => $child === null ? 'Register a child aged 0–59 months. Required fields are marked “required”.'
                : 'Edit child details. Required fields are marked “required”. Record number: ' . $child['record_no'] . '.',
            'submitLabel' => $child === null ? 'Register child' : 'Save changes',
            'cancelUrl' => $child === null ? null : '/children/' . $child['id'],
            'puroks' => $this->puroks->all(), 'values' => $values, 'fields' => ChildValidator::TEXT_FIELDS,
            'csrfToken' => $this->csrf->token(), 'messages' => $this->flash->pull(),
            'error' => $error, 'duplicates' => $duplicates,
            'duplicateToken' => $duplicates === [] ? null : $this->confirmation->issue($values),
            'today' => date('Y-m-d'),
            'sections' => [
                'Child details' => ['first_name', 'middle_name', 'last_name', 'suffix', 'place_of_birth'],
                'Home and family' => ['address', 'mother_name', 'father_name', 'guardian_name', 'guardian_relation', 'contact_no', 'philhealth_no'],
                'Additional notes' => ['notes'],
            ],
        ]), $error !== null ? 422 : ($duplicates !== [] ? 409 : 200), [
            'Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store',
        ]);
    }

    private function navigation(string $current): array
    {
        $user = $this->auth->currentUser();
        $navigation = [['label' => 'Staff account', 'href' => '/login'],
            ['label' => 'Malnutrition list', 'href' => '/reports/malnutrition'],
            ['label' => 'Vaccine defaulters', 'href' => '/immunizations/defaulters'],
            ['label' => 'Children', 'href' => '/children', 'current' => $current === '/children'],
            ['label' => 'Register child', 'href' => '/children/new', 'current' => $current === '/children/new']];
        if (Permissions::allows($user['role'] ?? '', 'users.manage')) {
            $navigation = [...$navigation, ['label' => 'Manage users', 'href' => '/admin/users'],
                ['label' => 'Manage puroks', 'href' => '/admin/puroks'],
                ['label' => 'Vaccine schedule', 'href' => '/admin/schedule'], ['label' => 'Audit log', 'href' => '/admin/audit']];
        }

        if (Permissions::allows($user['role'] ?? '', 'reports.aggregate')) {
            $navigation[] = ['label' => 'Nutritional summary', 'href' => '/reports/nutrition'];
            $navigation[] = ['label' => 'Immunization summary', 'href' => '/reports/immunization'];
        }

        return $navigation;
    }
}
