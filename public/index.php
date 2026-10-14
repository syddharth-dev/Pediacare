<?php

declare(strict_types=1);

use Pediacare\Core\Request;
use Pediacare\Core\Router;
use Pediacare\Core\Response;
use Pediacare\Core\Session;
use Pediacare\Middleware\CsrfMiddleware;
use Pediacare\Middleware\AuthMiddleware;

$container = require dirname(__DIR__) . '/src/bootstrap.php';
$routes = require dirname(__DIR__) . '/src/routes.php';
$request = Request::fromGlobals();

$https = isset($_SERVER['HTTPS']) && in_array(strtolower((string) $_SERVER['HTTPS']), ['on', '1'], true);
$container->get(Session::class)->start($https);
$router = new Router($container, $routes);
$container->get(CsrfMiddleware::class)->handle(
    $request,
    static fn (Request $request): Response => $container->get(AuthMiddleware::class)->handle(
        $request,
        static fn (Request $request): Response => $router->dispatch($request)
    )
)->send($request->method === 'HEAD');
