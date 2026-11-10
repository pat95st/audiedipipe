<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http;

/**
 * Small regex based router. Route patterns use {name} placeholders.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /**
     * @param class-string    $controller
     * @param list<string>    $roles  empty = any authenticated user
     */
    public function add(
        string $method,
        string $pattern,
        string $controller,
        string $action,
        bool $auth = true,
        array $roles = [],
        bool $csrf = true,
        ?string $name = null,
    ): void {
        $this->routes[] = new Route(strtoupper($method), $pattern, $controller, $action, $auth, $roles, $csrf, $name);
    }

    /**
     * @param class-string $controller
     * @param list<string> $roles
     */
    public function get(string $pattern, string $controller, string $action, bool $auth = true, array $roles = []): void
    {
        $this->add('GET', $pattern, $controller, $action, $auth, $roles);
    }

    /**
     * @param class-string $controller
     * @param list<string> $roles
     */
    public function post(string $pattern, string $controller, string $action, bool $auth = true, array $roles = [], bool $csrf = true): void
    {
        $this->add('POST', $pattern, $controller, $action, $auth, $roles, $csrf);
    }

    /**
     * @return array{0: Route, 1: array<string, string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $methodAllowed = false;
        foreach ($this->routes as $route) {
            $params = $route->matches($path);
            if ($params === null) {
                continue;
            }
            $methodAllowed = true;
            if ($route->method === $method || ($method === 'HEAD' && $route->method === 'GET')) {
                return [$route, $params];
            }
        }
        if ($methodAllowed) {
            return [new Route('*', $path, '', '__method_not_allowed', false, [], false, null), []];
        }

        return null;
    }

    /**
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }
}
