<?php

declare(strict_types=1);

namespace Pediacare\Controllers\Admin;

use InvalidArgumentException;
use Pediacare\Core\Request;
use Pediacare\Core\Response;
use Pediacare\Core\View;
use Pediacare\Services\AuditService;
use Pediacare\Services\AuthService;
use Pediacare\Services\UserService;
use Pediacare\Validation\AuditFilterValidator;

final class AuditController
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly UserService $users,
        private readonly AuthService $auth,
        private readonly AuditFilterValidator $validator,
        private readonly View $view
    ) {
    }

    public function index(Request $request): Response
    {
        $error = null;
        $filters = array_fill_keys(['user_id', 'entity', 'from', 'to'], '');
        $entries = [];
        try {
            $filters = $this->validator->validate($request->query);
            $entries = $this->audit->search($filters);
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        }

        return new Response($this->view->render('admin/audit', [
            'title' => 'Audit log', 'userName' => $this->auth->currentUser()['name'] ?? null,
            'navigation' => [['label' => 'Staff account', 'href' => '/login'],
                ['label' => 'Children', 'href' => '/children'],
                ['label' => 'Register child', 'href' => '/children/new'],
                ['label' => 'Manage users', 'href' => '/admin/users'],
                ['label' => 'Manage puroks', 'href' => '/admin/puroks'],
                ['label' => 'Vaccine schedule', 'href' => '/admin/schedule'],
                ['label' => 'Audit log', 'href' => '/admin/audit', 'current' => true]],
            'users' => $this->users->all(), 'entities' => $this->audit->entities(),
            'filters' => $filters, 'entries' => $entries, 'error' => $error,
        ]), $error === null ? 200 : 422);
    }
}
