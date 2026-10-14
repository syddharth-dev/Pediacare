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
use Pediacare\Domain\MeasurementResult;
use Pediacare\Services\AuthService;
use Pediacare\Services\ChildService;
use Pediacare\Services\MeasurementService;
use Pediacare\Validation\MeasurementValidator;

final class MeasurementController
{
    public function __construct(
        private readonly MeasurementService $measurements,
        private readonly ChildService $children,
        private readonly AuthService $auth,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly View $view,
    ) {
    }

    public function create(Request $request, string $id): Response
    {
        return $this->page($id);
    }

    public function store(Request $request, string $id): Response
    {
        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }
        try {
            $result = $this->measurements->record($id, $request->post, (int) $user['id']);
        } catch (OutOfBoundsException $error) {
            return new Response('Child record not found.', 404, ['Cache-Control' => 'no-store']);
        } catch (InvalidArgumentException $error) {
            return $this->page($id, $request->post, $error->getMessage());
        }
        if ($result->id === null) {
            return $this->page($id, $request->post, null, $result);
        }
        $this->flash->add('Measurement saved.');

        return Response::redirect('/children/' . (int) $id . '?tab=growth');
    }

    private function page(string $id, array $input = [], ?string $error = null, ?MeasurementResult $result = null): Response
    {
        $headers = ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store'];
        $child = $this->children->profile($id);
        if ($child === null) {
            return new Response('Child record not found.', 404, $headers);
        }
        if ($child['archived_at'] !== null) {
            return new Response('Restore this child record before adding a measurement.', 409, $headers);
        }
        $values = [];
        foreach (['measured_at', 'weight_kg', 'height_cm', 'muac_cm', 'position'] as $field) {
            $values[$field] = is_string($input[$field] ?? null) ? $input[$field] : '';
        }
        if ($input === []) {
            $values['measured_at'] = date('Y-m-d');
        }

        return new Response($this->view->render('measurements/create', [
            'title' => 'Add measurement', 'userName' => $this->auth->currentUser()['name'] ?? null,
            'navigation' => [['label' => 'Children', 'href' => '/children']],
            'child' => $child, 'values' => $values, 'fields' => MeasurementValidator::FIELDS,
            'error' => $error, 'result' => $result, 'today' => date('Y-m-d'),
            'csrfToken' => $this->csrf->token(),
            'indicatorLabels' => ['wfa_z' => 'Weight-for-age', 'hfa_z' => 'Length/height-for-age',
                'wfh_z' => 'Weight-for-length/height', 'bmi_z' => 'BMI-for-age'],
        ]), $error !== null ? 422 : ($result !== null ? 409 : 200), $headers);
    }
}
