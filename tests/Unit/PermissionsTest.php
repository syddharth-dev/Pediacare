<?php

declare(strict_types=1);

use Pediacare\Support\Permissions;
use PHPUnit\Framework\TestCase;

final class PermissionsTest extends TestCase
{
    public function test_AC02_bhw_can_record_but_cannot_archive_or_manage_accounts_or_schedule(): void
    {
        foreach (['children.read', 'children.create', 'measurements.create', 'visits.create'] as $action) {
            self::assertTrue(Permissions::allows('BHW', $action));
        }

        foreach (['children.archive', 'children.restore', 'children.update', 'users.manage',
            'schedule.manage', 'immunizations.create', 'immunizations.update'] as $action) {
            self::assertFalse(Permissions::allows('BHW', $action));
        }
    }

    public function test_AC03_viewer_only_has_aggregate_reports_and_logout_permissions(): void
    {
        self::assertTrue(Permissions::allows('VIEWER', 'reports.aggregate'));
        self::assertTrue(Permissions::allows('VIEWER', 'session.logout'));

        foreach (['children.read', 'children.create', 'children.update', 'children.archive',
            'measurements.create', 'immunizations.create', 'visits.create', 'users.manage',
            'puroks.manage', 'schedule.manage', 'audit.read'] as $action) {
            self::assertFalse(Permissions::allows('VIEWER', $action));
        }
    }

    public function test_T10_staff_manage_health_records_but_not_admin_configuration(): void
    {
        foreach (['children.read', 'children.create', 'children.update', 'children.archive', 'children.restore',
            'measurements.create', 'measurements.update', 'immunizations.create', 'immunizations.update',
            'visits.create', 'visits.update', 'reports.aggregate'] as $action) {
            self::assertTrue(Permissions::allows('STAFF', $action));
            self::assertTrue(Permissions::allows('ADMIN', $action));
        }

        foreach (['users.manage', 'puroks.manage', 'schedule.manage', 'audit.read'] as $action) {
            self::assertFalse(Permissions::allows('STAFF', $action));
            self::assertTrue(Permissions::allows('ADMIN', $action));
        }
    }

    public function test_T10_unknown_roles_and_actions_have_no_permission(): void
    {
        self::assertSame([], Permissions::roles('FAKE.unknown'));
        self::assertFalse(Permissions::allows('ADMIN', 'FAKE.unknown'));
        self::assertFalse(Permissions::allows('FAKE', 'children.read'));
        self::assertFalse(Permissions::allows('admin', 'users.manage'));
    }

    public function test_T10_every_non_login_route_declares_auth_role_and_known_allowed_roles(): void
    {
        $routes = require dirname(__DIR__, 2) . '/src/routes.php';

        foreach ($routes as $route) {
            if ($route['path'] === '/login') {
                self::assertContains($route['method'], ['GET', 'POST']);
                continue;
            }

            self::assertSame([
                Pediacare\Middleware\AuthMiddleware::class, Pediacare\Middleware\RoleMiddleware::class,
            ], array_slice($route['middleware'], 0, 2));
            self::assertNotEmpty($route['roles']);

            foreach ($route['roles'] as $role) {
                self::assertTrue(Permissions::allows($role, 'session.logout'));
            }
        }
    }
}
