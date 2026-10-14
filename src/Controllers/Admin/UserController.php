<?php

declare(strict_types=1);

namespace Pediacare\Controllers\Admin;

use InvalidArgumentException;
use Pediacare\Core\Csrf;
use Pediacare\Core\Flash;
use Pediacare\Core\Request;
use Pediacare\Core\Response;
use Pediacare\Core\View;
use Pediacare\Services\AuthService;
use Pediacare\Services\UserService;
use Pediacare\Support\Permissions;

final class UserController
{
    public function __construct(
        private readonly UserService $users,
        private readonly AuthService $auth,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly View $view
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->page();
    }

    public function create(Request $request): Response
    {
        try {
            $this->users->create($request->post);
        } catch (InvalidArgumentException $error) {
            return $this->page($error->getMessage(), $request->post);
        }

        $this->flash->add('Account created.');

        return Response::redirect('/admin/users');
    }

    public function deactivate(Request $request, string $id): Response
    {
        try {
            $this->users->deactivate($id);
        } catch (InvalidArgumentException $error) {
            return $this->page($error->getMessage());
        }

        $this->flash->add('Account deactivated.');

        return Response::redirect('/admin/users');
    }

    public function changeRole(Request $request, string $id): Response
    {
        try {
            $this->users->changeRole($id, $request->post);
        } catch (InvalidArgumentException $error) {
            return $this->page($error->getMessage());
        }

        $this->flash->add('Role updated.');

        return Response::redirect('/admin/users');
    }

    private function page(?string $error = null, array $input = []): Response
    {
        $values = [];

        foreach (['name', 'email', 'role'] as $field) {
            $values[$field] = is_string($input[$field] ?? null) ? $input[$field] : '';
        }

        $user = $this->auth->currentUser();

        return new Response($this->view->render('admin/users', [
            'title' => 'Manage users', 'userName' => $user['name'] ?? null,
            'navigation' => [['label' => 'Staff account', 'href' => '/login'],
                ['label' => 'Register child', 'href' => '/children/new'],
                ['label' => 'Children', 'href' => '/children'],
                ['label' => 'Manage users', 'href' => '/admin/users', 'current' => true],
                ['label' => 'Manage puroks', 'href' => '/admin/puroks'],
                ['label' => 'Vaccine schedule', 'href' => '/admin/schedule'],
                ['label' => 'Audit log', 'href' => '/admin/audit']],
            'users' => $this->users->all(), 'roles' => Permissions::roles('session.logout'),
            'roleLabels' => ['ADMIN' => 'Admin', 'STAFF' => 'Health Staff', 'BHW' => 'BHW', 'VIEWER' => 'Viewer'],
            'csrfToken' => $this->csrf->token(), 'messages' => $this->flash->pull(),
            'error' => $error, 'values' => $values,
        ]), $error === null ? 200 : 422);
    }
}
