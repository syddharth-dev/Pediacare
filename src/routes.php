<?php

declare(strict_types=1);

use Pediacare\Controllers\AuthController;
use Pediacare\Controllers\ChildController;
use Pediacare\Controllers\MeasurementController;
use Pediacare\Controllers\Admin\UserController;
use Pediacare\Controllers\Admin\AuditController;
use Pediacare\Controllers\Admin\PurokController;
use Pediacare\Middleware\AuthMiddleware;
use Pediacare\Middleware\RoleMiddleware;
use Pediacare\Support\Permissions;

// POST routes are protected by the front controller's CSRF middleware.
return [
    ['method' => 'GET', 'path' => '/reports/malnutrition', 'handler' => [\Pediacare\Controllers\ReportController::class, 'malnutrition'],
        'middleware' => [AuthMiddleware::class, RoleMiddleware::class], 'roles' => Permissions::roles('children.read')],
    ['method' => 'GET', 'path' => '/reports/immunization', 'handler' => [\Pediacare\Controllers\ReportController::class, 'immunization'],
        'middleware' => [AuthMiddleware::class, RoleMiddleware::class], 'roles' => Permissions::roles('reports.aggregate')],
    ['method' => 'GET', 'path' => '/reports/nutrition', 'handler' => [\Pediacare\Controllers\ReportController::class, 'nutritional'],
        'middleware' => [AuthMiddleware::class, RoleMiddleware::class], 'roles' => Permissions::roles('reports.aggregate')],
    ...array_map(static fn (array $route): array => $route + [
        'middleware' => [AuthMiddleware::class, RoleMiddleware::class],
        'roles' => Permissions::roles('visits.create'),
    ], [
        ['method' => 'GET', 'path' => '/children/{id}/visits/new', 'handler' => [\Pediacare\Controllers\VisitController::class, 'create']],
        ['method' => 'POST', 'path' => '/children/{id}/visits', 'handler' => [\Pediacare\Controllers\VisitController::class, 'store']],
    ]),
    ['method' => 'GET', 'path' => '/immunizations/defaulters', 'handler' => [\Pediacare\Controllers\DefaulterController::class, 'index'],
        'middleware' => [AuthMiddleware::class, RoleMiddleware::class], 'roles' => Permissions::roles('children.read')],
    ...array_map(static fn (array $route): array => $route + [
        'middleware' => [AuthMiddleware::class, RoleMiddleware::class],
        'roles' => Permissions::roles('immunizations.create'),
    ], [
        ['method' => 'GET', 'path' => '/children/{id}/immunizations/new', 'handler' => [\Pediacare\Controllers\ImmunizationController::class, 'create']],
        ['method' => 'POST', 'path' => '/children/{id}/immunizations', 'handler' => [\Pediacare\Controllers\ImmunizationController::class, 'store']],
    ]),
    ...array_map(static fn (array $route): array => $route + [
        'middleware' => [AuthMiddleware::class, RoleMiddleware::class],
        'roles' => Permissions::roles('schedule.manage'),
    ], [
        ['method' => 'GET', 'path' => '/admin/schedule', 'handler' => [\Pediacare\Controllers\Admin\ScheduleController::class, 'index']],
        ['method' => 'GET', 'path' => '/admin/schedule/vaccines/{id}', 'handler' => [\Pediacare\Controllers\Admin\ScheduleController::class, 'vaccine']],
        ['method' => 'GET', 'path' => '/admin/schedule/doses/{id}', 'handler' => [\Pediacare\Controllers\Admin\ScheduleController::class, 'dose']],
        ['method' => 'POST', 'path' => '/admin/schedule/vaccines', 'handler' => [\Pediacare\Controllers\Admin\ScheduleController::class, 'createVaccine']],
        ['method' => 'POST', 'path' => '/admin/schedule/vaccines/{id}', 'handler' => [\Pediacare\Controllers\Admin\ScheduleController::class, 'updateVaccine']],
        ['method' => 'POST', 'path' => '/admin/schedule/vaccines/{id}/doses', 'handler' => [\Pediacare\Controllers\Admin\ScheduleController::class, 'createDose']],
        ['method' => 'POST', 'path' => '/admin/schedule/doses/{id}', 'handler' => [\Pediacare\Controllers\Admin\ScheduleController::class, 'updateDose']],
    ]),
    ...array_map(static fn (array $route): array => $route + [
        'middleware' => [AuthMiddleware::class, RoleMiddleware::class],
        'roles' => Permissions::roles('measurements.create'),
    ], [
        ['method' => 'GET', 'path' => '/children/{id}/measurements/new', 'handler' => [MeasurementController::class, 'create']],
        ['method' => 'POST', 'path' => '/children/{id}/measurements', 'handler' => [MeasurementController::class, 'store']],
    ]),
    ...array_map(static fn (array $route): array => $route + [
        'middleware' => [AuthMiddleware::class, RoleMiddleware::class],
        'roles' => Permissions::roles('children.update'),
    ], [
        ['method' => 'GET', 'path' => '/children/{id}/edit', 'handler' => [ChildController::class, 'edit']],
        ['method' => 'POST', 'path' => '/children/{id}/update', 'handler' => [ChildController::class, 'update']],
    ]),
    ['method' => 'POST', 'path' => '/children/{id}/archive', 'handler' => [ChildController::class, 'archive'],
        'middleware' => [AuthMiddleware::class, RoleMiddleware::class],
        'roles' => Permissions::roles('children.archive')],
    ['method' => 'POST', 'path' => '/children/{id}/restore', 'handler' => [ChildController::class, 'restore'],
        'middleware' => [AuthMiddleware::class, RoleMiddleware::class],
        'roles' => Permissions::roles('children.restore')],
    ['method' => 'GET', 'path' => '/children', 'handler' => [ChildController::class, 'index'],
        'middleware' => [AuthMiddleware::class, RoleMiddleware::class],
        'roles' => Permissions::roles('children.read')],
    ...array_map(static fn (array $route): array => $route + [
        'middleware' => [AuthMiddleware::class, RoleMiddleware::class],
        'roles' => Permissions::roles('children.create'),
    ], [
        ['method' => 'GET', 'path' => '/children/new', 'handler' => [ChildController::class, 'create']],
        ['method' => 'POST', 'path' => '/children', 'handler' => [ChildController::class, 'store']],
    ]),
    ['method' => 'GET', 'path' => '/children/{id}', 'handler' => [ChildController::class, 'show'],
        'middleware' => [AuthMiddleware::class, RoleMiddleware::class],
        'roles' => Permissions::roles('children.read')],
    ...array_map(static fn (array $route): array => $route + [
        'middleware' => [AuthMiddleware::class, RoleMiddleware::class],
        'roles' => Permissions::roles('puroks.manage'),
    ], [
        ['method' => 'GET', 'path' => '/admin/puroks', 'handler' => [PurokController::class, 'index']],
        ['method' => 'POST', 'path' => '/admin/puroks', 'handler' => [PurokController::class, 'create']],
        ['method' => 'POST', 'path' => '/admin/puroks/{id}/rename', 'handler' => [PurokController::class, 'rename']],
    ]),
    ['method' => 'GET', 'path' => '/admin/audit', 'handler' => [AuditController::class, 'index'],
        'middleware' => [AuthMiddleware::class, RoleMiddleware::class],
        'roles' => Permissions::roles('audit.read')],
    ...array_map(static fn (array $route): array => $route + [
        'middleware' => [AuthMiddleware::class, RoleMiddleware::class],
        'roles' => Permissions::roles('users.manage'),
    ], [
        ['method' => 'GET', 'path' => '/admin/users', 'handler' => [UserController::class, 'index']],
        ['method' => 'POST', 'path' => '/admin/users', 'handler' => [UserController::class, 'create']],
        ['method' => 'POST', 'path' => '/admin/users/{id}/deactivate', 'handler' => [UserController::class, 'deactivate']],
        ['method' => 'POST', 'path' => '/admin/users/{id}/role', 'handler' => [UserController::class, 'changeRole']],
    ]),
    ['method' => 'GET', 'path' => '/login', 'handler' => [AuthController::class, 'form']],
    ['method' => 'POST', 'path' => '/login', 'handler' => [AuthController::class, 'login']],
    [
        'method' => 'POST', 'path' => '/logout', 'handler' => [AuthController::class, 'logout'],
        'middleware' => [AuthMiddleware::class, RoleMiddleware::class],
        'roles' => Permissions::roles('session.logout'),
    ],
];
