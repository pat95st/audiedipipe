<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Unit\Http;

use AudiEdiPipe\Http\Router;
use AudiEdiPipe\Http\Routes;
use AudiEdiPipe\Tests\Support\TestCase;

/**
 * Every registered route must point to a loadable controller class with a
 * public action method. Loading the class also catches fatal declaration
 * errors (e.g. an action that clashes with a base controller method).
 */
final class RoutesTest extends TestCase
{
    public function test_every_route_points_to_a_public_controller_action(): void
    {
        $router = new Router();
        Routes::register($router);

        self::assertNotEmpty($router->routes());
        foreach ($router->routes() as $route) {
            self::assertTrue(class_exists($route->controller), $route->controller . ' cannot be loaded');
            $reflection = new \ReflectionClass($route->controller);
            self::assertTrue($reflection->hasMethod($route->action), sprintf('%s::%s() is missing (%s %s)', $route->controller, $route->action, $route->method, $route->pattern));
            self::assertTrue($reflection->getMethod($route->action)->isPublic(), sprintf('%s::%s() must be public', $route->controller, $route->action));
        }
    }
}
