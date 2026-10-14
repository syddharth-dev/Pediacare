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
use Pediacare\Services\PurokService;

final class PurokController
{
    public function __construct(
        private readonly PurokService $puroks,
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
            $this->puroks->create($request->post);
        } catch (InvalidArgumentException $error) {
            return $this->page($error->getMessage(), $request->post);
        }

        $this->flash->add('Purok or sitio added.');

        return Response::redirect('/admin/puroks');
    }

    public function rename(Request $request, string $id): Response
    {
        try {
            $this->puroks->rename($id, $request->post);
        } catch (InvalidArgumentException $error) {
            return $this->page($error->getMessage(), $request->post, $id);
        }

        $this->flash->add('Purok or sitio renamed.');

        return Response::redirect('/admin/puroks');
    }

    private function page(?string $error = null, array $input = [], ?string $editId = null): Response
    {
        return new Response($this->view->render('admin/puroks', [
            'title' => 'Manage puroks and sitios', 'userName' => $this->auth->currentUser()['name'] ?? null,
            'navigation' => [['label' => 'Staff account', 'href' => '/login'],
                ['label' => 'Register child', 'href' => '/children/new'],
                ['label' => 'Children', 'href' => '/children'],
                ['label' => 'Manage users', 'href' => '/admin/users'],
                ['label' => 'Manage puroks', 'href' => '/admin/puroks', 'current' => true],
                ['label' => 'Vaccine schedule', 'href' => '/admin/schedule'],
                ['label' => 'Audit log', 'href' => '/admin/audit']],
            'puroks' => $this->puroks->all(), 'csrfToken' => $this->csrf->token(),
            'messages' => $this->flash->pull(), 'error' => $error, 'editId' => $editId,
            'name' => is_string($input['name'] ?? null) ? $input['name'] : '',
        ]), $error === null ? 200 : 422);
    }
}
