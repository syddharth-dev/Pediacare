<?php

declare(strict_types=1);

namespace Pediacare\Controllers;

use Pediacare\Core\Csrf;
use Pediacare\Core\Request;
use Pediacare\Core\Response;
use Pediacare\Core\View;
use Pediacare\Services\AuthService;
use Pediacare\Support\Permissions;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly Csrf $csrf,
        private readonly View $view
    ) {
    }

    public function form(Request $request): Response
    {
        $user = $this->auth->currentUser();
        $navigation = Permissions::allows($user['role'] ?? '', 'children.create')
            ? [['label' => 'Children', 'href' => '/children'],
                ['label' => 'Register child', 'href' => '/children/new']] : [];
        if (Permissions::allows($user['role'] ?? '', 'children.read')) {
            $navigation[] = ['label' => 'Vaccine defaulters', 'href' => '/immunizations/defaulters'];
            $navigation[] = ['label' => 'Malnutrition list', 'href' => '/reports/malnutrition'];
        }
        if (Permissions::allows($user['role'] ?? '', 'users.manage')) {
            $navigation = [...$navigation, ['label' => 'Manage users', 'href' => '/admin/users'],
                ['label' => 'Manage puroks', 'href' => '/admin/puroks'],
                ['label' => 'Vaccine schedule', 'href' => '/admin/schedule'], ['label' => 'Audit log', 'href' => '/admin/audit']];
        }

        if (Permissions::allows($user['role'] ?? '', 'reports.aggregate')) {
            $navigation[] = ['label' => 'Nutritional summary', 'href' => '/reports/nutrition'];
            $navigation[] = ['label' => 'Immunization summary', 'href' => '/reports/immunization'];
        }

        return new Response($this->view->render('auth/login', [
            'title' => $user !== null ? 'Workspace' : 'Staff login', 'userName' => $user['name'] ?? null,
            'signedIn' => $user !== null, 'csrfToken' => $this->csrf->token(), 'error' => null,
            'navigation' => $navigation,
        ]));
    }

    public function login(Request $request): Response
    {
        $email = $request->post['email'] ?? null;
        $password = $request->post['password'] ?? null;

        if (is_string($email) && is_string($password)
            && $this->auth->login($email, $password, $request->ipAddress)
        ) {
            return Response::redirect('/login');
        }

        return new Response($this->view->render('auth/login', [
            'title' => 'Staff login', 'signedIn' => false, 'csrfToken' => $this->csrf->token(),
            'error' => 'Unable to sign in. Check your email and password, or try again in 15 minutes.',
        ]), 401);
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout();

        return Response::redirect('/login');
    }
}
