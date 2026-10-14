<?php

declare(strict_types=1);

use Pediacare\Core\Container;
use Pediacare\Core\Request;
use Pediacare\Core\Response;
use Pediacare\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->container->register(T04Controller::class, static fn (Container $container): object => new T04Controller());
    }

    public function test_T04_dispatches_parameters_in_path_order(): void
    {
        $router = new Router($this->container, [$this->route('GET', '/children/{child}/measurements/{measurement}', 'show')]);

        $response = $router->dispatch(new Request('GET', '/children/123/measurements/456'));

        self::assertSame(200, $response->status);
        self::assertSame('123:456', $response->body);
    }

    public function test_T04_literal_path_characters_are_not_regular_expressions(): void
    {
        $router = new Router($this->container, [$this->route('GET', '/reports.csv')]);

        self::assertSame(200, $router->dispatch(new Request('GET', '/reports.csv'))->status);
        self::assertSame(404, $router->dispatch(new Request('GET', '/reportsXcsv'))->status);
    }

    public function test_T04_unknown_path_and_missing_parameter_return_404(): void
    {
        $router = new Router($this->container, [$this->route('GET', '/children/{child}', 'child')]);

        foreach (['/unknown', '/children/', '/children/1/extra'] as $path) {
            self::assertSame(404, $router->dispatch(new Request('GET', $path))->status);
        }
    }

    public function test_T04_wrong_method_returns_405_with_allow_header(): void
    {
        $router = new Router($this->container, [
            $this->route('GET', '/children'),
            $this->route('POST', '/children'),
        ]);

        $response = $router->dispatch(new Request('DELETE', '/children'));

        self::assertSame(405, $response->status);
        self::assertSame('GET, HEAD, POST', $response->headers['Allow']);
    }

    public function test_T04_get_and_post_select_different_handlers(): void
    {
        $router = new Router($this->container, [
            $this->route('GET', '/children', 'index'),
            $this->route('POST', '/children', 'store'),
        ]);

        self::assertSame('index', $router->dispatch(new Request('GET', '/children'))->body);
        self::assertSame('saved', $router->dispatch(new Request('POST', '/children'))->body);
    }

    public function test_T04_head_uses_get_unless_an_explicit_head_handler_exists(): void
    {
        $get = $this->route('GET', '/children', 'index');
        $head = $this->route('HEAD', '/children', 'store');

        self::assertSame('index', (new Router($this->container, [$get]))->dispatch(new Request('HEAD', '/children'))->body);
        self::assertSame('saved', (new Router($this->container, [$get, $head]))->dispatch(new Request('HEAD', '/children'))->body);
    }

    public function test_T04_middleware_runs_in_declared_order_and_receives_roles(): void
    {
        $trace = new ArrayObject();
        $this->container->register(T04Middleware::class, static fn (Container $container): object => new T04Middleware($trace, 'outer'));
        $this->container->register(T04InnerMiddleware::class, static fn (Container $container): object => new T04InnerMiddleware($trace, 'inner'));
        $route = $this->route('GET', '/children');
        $route['middleware'] = [T04Middleware::class, T04InnerMiddleware::class];
        $route['roles'] = ['STAFF'];

        $response = (new Router($this->container, [$route]))->dispatch(new Request('GET', '/children'));

        self::assertSame('index', $response->body);
        self::assertSame(['before:outer:STAFF', 'before:inner:STAFF', 'after:inner', 'after:outer'], $trace->getArrayCopy());
    }

    public function test_T04_middleware_can_block_before_controller_resolution(): void
    {
        $container = new Container();
        $container->register(T04BlockMiddleware::class, static fn (Container $container): object => new T04BlockMiddleware());
        $route = $this->route('GET', '/children');
        $route['middleware'] = [T04BlockMiddleware::class];

        $response = (new Router($container, [$route]))->dispatch(new Request('GET', '/children'));

        self::assertSame(403, $response->status);
    }

    private function route(string $method, string $path, string $action = 'index'): array
    {
        return ['method' => $method, 'path' => $path, 'handler' => [T04Controller::class, $action]];
    }
}

final class T04Controller
{
    public function index(Request $request): Response
    {
        return new Response('index');
    }

    public function store(Request $request): Response
    {
        return new Response('saved');
    }

    public function show(Request $request, string $child, string $measurement): Response
    {
        return new Response($child . ':' . $measurement);
    }

    public function child(Request $request, string $child): Response
    {
        return new Response($child);
    }
}

class T04Middleware
{
    public function __construct(private readonly ArrayObject $trace, private readonly string $name)
    {
    }

    public function handle(Request $request, Closure $next, array $roles): Response
    {
        $this->trace->append('before:' . $this->name . ':' . implode(',', $roles));
        $response = $next($request);
        $this->trace->append('after:' . $this->name);

        return $response;
    }
}

final class T04InnerMiddleware extends T04Middleware
{
}

final class T04BlockMiddleware
{
    public function handle(Request $request, Closure $next, array $roles): Response
    {
        return new Response('Forbidden.', 403);
    }
}
