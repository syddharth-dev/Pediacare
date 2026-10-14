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
use Pediacare\Services\ImmunizationService;

final class ImmunizationController
{
    public function __construct(
        private readonly ImmunizationService $immunizations,
        private readonly ChildService $children,
        private readonly AuthService $auth,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly View $view
    ) {
    }

    public function create(Request $request, string $id): Response
    {
        return $this->page($id, ['schedule_dose_id' => is_string($request->query['dose'] ?? null) ? $request->query['dose'] : '',
            'date_given' => date('Y-m-d'), 'given_elsewhere' => '0']);
    }

    public function store(Request $request, string $id): Response
    {
        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }
        try {
            $this->immunizations->record($id, $request->post, (int) $user['id']);
        } catch (OutOfBoundsException) {
            return new Response('Child record not found.', 404, ['Cache-Control' => 'no-store']);
        } catch (InvalidArgumentException $error) {
            return $this->page($id, $request->post, $error->getMessage());
        }
        $this->flash->add('Vaccine recorded.');

        return Response::redirect('/children/' . (int) $id . '?tab=immunization');
    }

    private function page(string $id, array $input, ?string $error = null): Response
    {
        $headers = ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store'];
        $child = $this->children->profile($id);
        if ($child === null) {
            return new Response('Child record not found.', 404, $headers);
        }
        if ($child['archived_at'] !== null) {
            return new Response('Restore this child record before recording a vaccine.', 409, $headers);
        }
        $values = [];
        foreach (['schedule_dose_id', 'date_given', 'lot_number', 'site', 'given_elsewhere', 'place_given'] as $field) {
            $values[$field] = is_string($input[$field] ?? null) ? $input[$field] : '';
        }

        return new Response($this->view->render('immunizations/create', [
            'title' => 'Record vaccine', 'userName' => $this->auth->currentUser()['name'] ?? null,
            'navigation' => [['label' => 'Children', 'href' => '/children']], 'child' => $child,
            'immunization' => $this->immunizations->forChild($child), 'values' => $values,
            'today' => date('Y-m-d'), 'csrfToken' => $this->csrf->token(), 'error' => $error,
        ]), $error === null ? 200 : 422, $headers);
    }
}
