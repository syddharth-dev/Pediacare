<?php

declare(strict_types=1);

namespace Pediacare\Controllers\Admin;

use InvalidArgumentException;
use OutOfBoundsException;
use Pediacare\Core\Csrf;
use Pediacare\Core\Flash;
use Pediacare\Core\Request;
use Pediacare\Core\Response;
use Pediacare\Core\View;
use Pediacare\Services\AuthService;
use Pediacare\Services\VaccineScheduleService;

final class ScheduleController
{
    public function __construct(
        private readonly VaccineScheduleService $schedule,
        private readonly AuthService $auth,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly View $view
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->page('admin/schedule', ['vaccines' => $this->schedule->all(), 'values' => ['active' => '1']]);
    }

    public function vaccine(Request $request, string $id): Response
    {
        try {
            return $this->vaccinePage($id);
        } catch (InvalidArgumentException | OutOfBoundsException) {
            return $this->missing();
        }
    }

    public function dose(Request $request, string $id): Response
    {
        try {
            return $this->dosePage($id);
        } catch (InvalidArgumentException | OutOfBoundsException) {
            return $this->missing();
        }
    }

    public function createVaccine(Request $request): Response
    {
        try {
            $id = $this->schedule->saveVaccine(null, $request->post, (int) $this->auth->currentUser()['id']);
        } catch (InvalidArgumentException $error) {
            return $this->page('admin/schedule', ['vaccines' => $this->schedule->all(),
                'values' => $this->strings($request->post)], $error->getMessage());
        }

        $this->flash->add('Vaccine added.');

        return Response::redirect('/admin/schedule/vaccines/' . $id);
    }

    public function updateVaccine(Request $request, string $id): Response
    {
        try {
            $this->schedule->saveVaccine($id, $request->post, (int) $this->auth->currentUser()['id']);
        } catch (OutOfBoundsException) {
            return $this->missing();
        } catch (InvalidArgumentException $error) {
            try {
                return $this->vaccinePage($id, $error->getMessage(), $request->post);
            } catch (InvalidArgumentException | OutOfBoundsException) {
                return $this->missing();
            }
        }

        $this->flash->add('Vaccine settings saved.');

        return Response::redirect('/admin/schedule/vaccines/' . $id);
    }

    public function createDose(Request $request, string $id): Response
    {
        try {
            $this->schedule->saveDose(null, $id, $request->post, (int) $this->auth->currentUser()['id']);
        } catch (OutOfBoundsException) {
            return $this->missing();
        } catch (InvalidArgumentException $error) {
            try {
                return $this->vaccinePage($id, $error->getMessage(), [], $request->post);
            } catch (InvalidArgumentException | OutOfBoundsException) {
                return $this->missing();
            }
        }

        $this->flash->add('Dose added.');

        return Response::redirect('/admin/schedule');
    }

    public function updateDose(Request $request, string $id): Response
    {
        try {
            $this->schedule->saveDose($id, null, $request->post, (int) $this->auth->currentUser()['id']);
        } catch (OutOfBoundsException) {
            return $this->missing();
        } catch (InvalidArgumentException $error) {
            try {
                return $this->dosePage($id, $error->getMessage(), $request->post);
            } catch (InvalidArgumentException | OutOfBoundsException) {
                return $this->missing();
            }
        }

        $this->flash->add('Dose settings saved.');

        return Response::redirect('/admin/schedule');
    }

    private function vaccinePage(string $id, ?string $error = null, array $input = [], array $doseInput = []): Response
    {
        $vaccine = $this->schedule->vaccine($id);

        return $this->page('admin/schedule-vaccine', ['vaccine' => $vaccine,
            'values' => $input === [] ? $vaccine : $this->strings($input),
            'doseValues' => $this->strings($doseInput) + $this->doseDefaults()], $error);
    }

    private function dosePage(string $id, ?string $error = null, array $input = []): Response
    {
        $dose = $this->schedule->dose($id);
        $values = $dose + [
            'age_value' => $dose['recommended_age_days'] ?? $dose['recommended_age_months'],
            'age_unit' => $dose['recommended_age_days'] === null ? 'months' : 'days',
            'interval_value' => $dose['min_interval_days'] ?? $dose['min_interval_months'],
            'interval_unit' => $dose['min_interval_days'] === null ? 'months' : 'days',
        ];

        return $this->page('admin/schedule-dose', ['dose' => $dose,
            'doseValues' => $input === [] ? $values : $this->strings($input) + $this->doseDefaults()], $error);
    }

    private function doseDefaults(): array
    {
        return ['dose_number' => '1', 'age_value' => '0', 'age_unit' => 'days', 'grace_days' => '0',
            'interval_value' => '0', 'interval_unit' => 'days', 'counts_toward_fic' => '0', 'sort_order' => '0'];
    }

    private function strings(array $input): array
    {
        return array_filter($input, static fn (mixed $value): bool => is_string($value));
    }

    private function page(string $template, array $data, ?string $error = null): Response
    {
        return new Response($this->view->render($template, $data + [
            'title' => 'Manage vaccine schedule', 'userName' => $this->auth->currentUser()['name'] ?? null,
            'navigation' => [['label' => 'Staff account', 'href' => '/login'],
                ['label' => 'Children', 'href' => '/children'], ['label' => 'Manage users', 'href' => '/admin/users'],
                ['label' => 'Manage puroks', 'href' => '/admin/puroks'],
                ['label' => 'Vaccine schedule', 'href' => '/admin/schedule', 'current' => true],
                ['label' => 'Audit log', 'href' => '/admin/audit']],
            'csrfToken' => $this->csrf->token(), 'messages' => $this->flash->pull(), 'error' => $error,
        ]), $error === null ? 200 : 422, ['Cache-Control' => 'no-store', 'Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function missing(): Response
    {
        return new Response('Schedule record not found.', 404, ['Cache-Control' => 'no-store', 'Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
