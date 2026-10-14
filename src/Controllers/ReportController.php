<?php

declare(strict_types=1);

namespace Pediacare\Controllers;

use InvalidArgumentException;
use Pediacare\Core\Csrf;
use Pediacare\Core\Request;
use Pediacare\Core\Response;
use Pediacare\Core\View;
use Pediacare\Services\AuthService;
use Pediacare\Services\ReportService;
use Pediacare\Support\Permissions;
use Pediacare\Support\ReportOutput;

final class ReportController
{
    public function __construct(private readonly ReportService $reports, private readonly AuthService $auth,
        private readonly Csrf $csrf, private readonly View $view)
    {
    }

    public function malnutrition(Request $request): Response
    {
        $user = $this->auth->currentUser();
        $results = null;
        $error = null;
        try {
            $format = ReportOutput::format($request->query);
            $results = $this->reports->malnutrition($request->query, $user['role'] ?? '', $format !== 'html');
            if ($format === 'csv') {
                return ReportOutput::csv('malnutrition', $results);
            }
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        }
        $navigation = [['label' => 'Staff account', 'href' => '/login'],
            ['label' => 'Children', 'href' => '/children'],
            ['label' => 'Malnutrition list', 'href' => '/reports/malnutrition', 'current' => true]];
        if (Permissions::allows($user['role'] ?? '', 'reports.aggregate')) {
            $navigation[] = ['label' => 'Nutritional summary', 'href' => '/reports/nutrition'];
            $navigation[] = ['label' => 'Immunization summary', 'href' => '/reports/immunization'];
        }
        $pageUrl = static fn (int $page): string => '/reports/malnutrition?' . http_build_query(
            array_replace($results['filters'], ['page' => $page]), '', '&', PHP_QUERY_RFC3986);

        return new Response($this->view->render('reports/malnutrition', [
            'title' => 'Malnutrition list', 'userName' => $user['name'] ?? null,
            'navigation' => $navigation, 'csrfToken' => $this->csrf->token(), 'results' => $results,
            'includeArchived' => ($request->query['include_archived'] ?? '0') === '1', 'error' => $error,
            'previousUrl' => $results !== null && $results['page'] > 1 ? $pageUrl($results['page'] - 1) : null,
            'nextUrl' => $results !== null && $results['page'] < $results['pages'] ? $pageUrl($results['page'] + 1) : null,
            'indicators' => ReportService::INDICATORS,
            'isReport' => true, 'isPrint' => $error === null && ($format ?? 'html') === 'print',
            'outputLinks' => $results === null ? [] : ReportOutput::links('/reports/malnutrition', $results['filters']),
        ]), $error === null ? 200 : 422, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function immunization(Request $request): Response
    {
        $user = $this->auth->currentUser();
        $results = null;
        $error = null;
        try {
            $format = ReportOutput::format($request->query);
            $results = $this->reports->immunization($request->query, $user['role'] ?? '');
            if ($format === 'csv') {
                return ReportOutput::csv('immunization', $results);
            }
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        }
        $values = [];
        foreach (['month' => date('Y-m'), 'include_archived' => '0'] as $key => $default) {
            $values[$key] = is_string($request->query[$key] ?? null) ? $request->query[$key] : $default;
        }
        $navigation = [['label' => 'Staff account', 'href' => '/login'],
            ['label' => 'Nutritional summary', 'href' => '/reports/nutrition'],
            ['label' => 'Immunization summary', 'href' => '/reports/immunization', 'current' => true]];
        if (Permissions::allows($user['role'] ?? '', 'children.read')) {
            $navigation[] = ['label' => 'Children', 'href' => '/children'];
            $navigation[] = ['label' => 'Malnutrition list', 'href' => '/reports/malnutrition'];
        }

        return new Response($this->view->render('reports/immunization', [
            'title' => 'Monthly immunization summary', 'userName' => $user['name'] ?? null,
            'navigation' => $navigation, 'csrfToken' => $this->csrf->token(), 'results' => $results,
            'values' => $values, 'error' => $error,
            'isReport' => true, 'isPrint' => $error === null && ($format ?? 'html') === 'print',
            'outputLinks' => $results === null ? [] : ReportOutput::links('/reports/immunization', $results['filters']),
        ]), $error === null ? 200 : 422, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function nutritional(Request $request): Response
    {
        $user = $this->auth->currentUser();
        $results = null;
        $error = null;
        try {
            $format = ReportOutput::format($request->query);
            $results = $this->reports->nutritional($request->query, $user['role'] ?? '');
            if ($format === 'csv') {
                return ReportOutput::csv('nutrition', $results);
            }
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        }
        $values = [];
        foreach (['from' => date('Y-m-01'), 'to' => date('Y-m-d'), 'include_archived' => '0'] as $key => $default) {
            $values[$key] = is_string($request->query[$key] ?? null) ? $request->query[$key] : $default;
        }
        $navigation = [['label' => 'Staff account', 'href' => '/login'],
            ['label' => 'Immunization summary', 'href' => '/reports/immunization'],
            ['label' => 'Nutritional summary', 'href' => '/reports/nutrition', 'current' => true]];
        if (Permissions::allows($user['role'] ?? '', 'children.read')) {
            $navigation[] = ['label' => 'Children', 'href' => '/children'];
            $navigation[] = ['label' => 'Malnutrition list', 'href' => '/reports/malnutrition'];
        }

        return new Response($this->view->render('reports/nutrition', [
            'title' => 'Nutritional status summary', 'userName' => $user['name'] ?? null,
            'navigation' => $navigation, 'csrfToken' => $this->csrf->token(), 'results' => $results,
            'values' => $values, 'error' => $error, 'indicators' => ReportService::INDICATORS,
            'isReport' => true, 'isPrint' => $error === null && ($format ?? 'html') === 'print',
            'outputLinks' => $results === null ? [] : ReportOutput::links('/reports/nutrition', $results['filters']),
        ]), $error === null ? 200 : 422, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
