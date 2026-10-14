<?php

declare(strict_types=1);

namespace Pediacare\Core;

final class Router
{
    /**
     * @param list<array{method: string, path: string, handler: array{class-string, string}, middleware?: list<class-string>, roles?: list<string>}> $routes
     */
    public function __construct(private readonly Container $container, private readonly array $routes)
    {
    }

    public function dispatch(Request $request): Response
    {
        $matches = [];

        foreach ($this->routes as $route) {
            $pattern = $this->pattern($route['path']);

            if (preg_match($pattern, $request->path, $parameters) !== 1) {
                continue;
            }

            $matches[] = [$route, $parameters];
        }

        $methods = $request->method === 'HEAD' ? ['HEAD', 'GET'] : [$request->method];

        foreach ($methods as $method) {
            foreach ($matches as [$route, $parameters]) {
                if ($route['method'] === $method) {
                    $arguments = [];

                    foreach ($parameters as $name => $value) {
                        if (is_string($name)) {
                            $arguments[] = rawurldecode($value);
                        }
                    }

                    return $this->invoke($route, $request, $arguments);
                }
            }
        }

        if ($matches !== []) {
            $allowed = array_unique(array_map(static fn (array $match): string => $match[0]['method'], $matches));

            if (in_array('GET', $allowed, true) && !in_array('HEAD', $allowed, true)) {
                $allowed[] = 'HEAD';
            }

            sort($allowed);

            return new Response('Method not allowed.', 405, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Allow' => implode(', ', $allowed),
            ]);
        }

        return new Response('Page not found.', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function pattern(string $path): string
    {
        $segments = explode('/', $path);

        foreach ($segments as &$segment) {
            if (preg_match('/\A\{([A-Za-z][A-Za-z0-9_]*)\}\z/', $segment, $match) === 1) {
                $segment = '(?P<' . $match[1] . '>[^/]+)';
            } else {
                $segment = preg_quote($segment, '~');
            }
        }

        return '~\A' . implode('/', $segments) . '\z~';
    }

    /** @param list<string> $arguments */
    private function invoke(array $route, Request $request, array $arguments): Response
    {
        $next = function (Request $request) use ($route, $arguments): Response {
            [$class, $method] = $route['handler'];
            $controller = $this->container->get($class);

            return $controller->$method($request, ...$arguments);
        };

        foreach (array_reverse($route['middleware'] ?? []) as $class) {
            $following = $next;
            $next = function (Request $request) use ($class, $following, $route): Response {
                $middleware = $this->container->get($class);

                return $middleware->handle($request, $following, $route['roles'] ?? []);
            };
        }

        return $next($request);
    }
}
