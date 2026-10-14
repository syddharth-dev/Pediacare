<?php

declare(strict_types=1);

namespace Pediacare\Controllers;

use InvalidArgumentException;
use Pediacare\Core\Csrf;
use Pediacare\Core\Request;
use Pediacare\Core\Response;
use Pediacare\Core\View;
use Pediacare\Services\AuthService;
use Pediacare\Services\ImmunizationService;
use Pediacare\Services\PurokService;
use Pediacare\Support\ReportOutput;

final class DefaulterController
{
    public function __construct(
        private readonly ImmunizationService $immunizations,
        private readonly PurokService $puroks,
        private readonly AuthService $auth,
        private readonly Csrf $csrf,
        private readonly View $view
    ) {
    }

    public function index(Request $request): Response
    {
        $error = null;
        $results = ['children' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'from' => 0, 'to' => 0,
            'filters' => ['purok_id' => '', 'vaccine_id' => '', 'page' => '1'], 'vaccines' => []];
        try {
            $format = ReportOutput::format($request->query);
            $results = $this->immunizations->defaulters($request->query, null, $format !== 'html');
            if ($format === 'csv') {
                return ReportOutput::csv('defaulters', $results);
            }
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        }
        $pageUrl = static fn (int $page): string => '/immunizations/defaulters?' . http_build_query(
            array_replace($results['filters'], ['page' => $page]), '', '&', PHP_QUERY_RFC3986
        );

        return new Response($this->view->render('immunizations/defaulters', [
            'title' => 'Vaccine defaulter list', 'userName' => $this->auth->currentUser()['name'] ?? null,
            'navigation' => [['label' => 'Children', 'href' => '/children'],
                ['label' => 'Vaccine defaulters', 'href' => '/immunizations/defaulters', 'current' => true]],
            'results' => $results, 'puroks' => $this->puroks->all(), 'error' => $error,
            'csrfToken' => $this->csrf->token(),
            'previousUrl' => $results['page'] > 1 ? $pageUrl($results['page'] - 1) : null,
            'nextUrl' => $results['page'] < $results['pages'] ? $pageUrl($results['page'] + 1) : null,
            'isReport' => true, 'isPrint' => $error === null && ($format ?? 'html') === 'print',
            'outputLinks' => $error !== null ? [] : ReportOutput::links('/immunizations/defaulters', $results['filters']),
        ]), $error === null ? 200 : 422, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
