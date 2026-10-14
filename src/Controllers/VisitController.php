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
use Pediacare\Services\VisitService;
use Pediacare\Validation\VisitValidator;

final class VisitController
{
    public function __construct(
        private readonly VisitService $visits,
        private readonly ChildService $children,
        private readonly AuthService $auth,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly View $view
    ) {
    }

    public function create(Request $request, string $id): Response
    {
        return $this->page($id, ['visit_date' => date('Y-m-d'), 'referred' => '0']);
    }

    public function store(Request $request, string $id): Response
    {
        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }
        try {
            $this->visits->record($id, $request->post, (int) $user['id']);
        } catch (OutOfBoundsException) {
            return new Response('Child record not found.', 404, ['Cache-Control' => 'no-store']);
        } catch (InvalidArgumentException $error) {
            return $this->page($id, $request->post, $error->getMessage());
        }
        $this->flash->add('Visit recorded.');

        return Response::redirect('/children/' . (int) $id . '?tab=visits');
    }

    private function page(string $id, array $input, ?string $error = null): Response
    {
        $headers = ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store'];
        $child = $this->children->profile($id);
        if ($child === null) {
            return new Response('Child record not found.', 404, $headers);
        }
        if ($child['archived_at'] !== null) {
            return new Response('Restore this child record before recording a visit.', 409, $headers);
        }
        $values = [];
        foreach (['visit_date', 'reason', 'findings', 'actions', 'referred', 'referred_to', 'notes'] as $field) {
            $values[$field] = is_string($input[$field] ?? null) ? $input[$field] : '';
        }
        $rows = is_array($input['supplements'] ?? null) ? $input['supplements'] : [];
        foreach (VisitValidator::SUPPLEMENTS as $type => $label) {
            $row = is_array($rows[$type] ?? null) ? $rows[$type] : [];
            foreach (['dose', 'date_given'] as $field) {
                $values['supplements'][$type][$field] = is_string($row[$field] ?? null) ? $row[$field] : '';
            }
        }

        return new Response($this->view->render('visits/create', [
            'title' => 'Record visit', 'userName' => $this->auth->currentUser()['name'] ?? null,
            'navigation' => [['label' => 'Children', 'href' => '/children']], 'child' => $child,
            'values' => $values, 'supplementTypes' => VisitValidator::SUPPLEMENTS,
            'today' => date('Y-m-d'), 'csrfToken' => $this->csrf->token(), 'error' => $error,
        ]), $error === null ? 200 : 422, $headers);
    }
}
