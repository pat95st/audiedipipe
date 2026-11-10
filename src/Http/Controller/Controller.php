<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http\Controller;

use AudiEdiPipe\Cqrs\Command;
use AudiEdiPipe\Cqrs\CommandBus;
use AudiEdiPipe\Cqrs\Query;
use AudiEdiPipe\Cqrs\QueryBus;
use AudiEdiPipe\Domain\User\Role;
use AudiEdiPipe\Http\Request;
use AudiEdiPipe\Http\Response;
use AudiEdiPipe\Http\Session;
use AudiEdiPipe\Http\View;
use AudiEdiPipe\Kernel\App;

/**
 * Base controller. Controllers only translate HTTP into commands/queries.
 */
abstract class Controller
{
    /**
     * @param array<string, mixed>|null $user
     */
    public function __construct(
        protected readonly App $app,
        protected readonly View $view,
        protected readonly Session $session,
        protected readonly Request $request,
        protected readonly ?array $user,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function render(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->view->render($template, $data), $status);
    }

    protected function redirect(string $path): Response
    {
        return Response::redirect($this->view->url($path));
    }

    protected function command(Command $command): mixed
    {
        return $this->app->get(CommandBus::class)->dispatch($command);
    }

    protected function query(Query $query): mixed
    {
        return $this->app->get(QueryBus::class)->ask($query);
    }

    /**
     * @param array<string, string|int|float> $params
     */
    protected function flash(string $type, string $key, array $params = []): void
    {
        $this->session->flash($type, $this->view->t($key, $params));
    }

    protected function userId(): int
    {
        return (int) ($this->user['id'] ?? 0);
    }

    protected function can(string $permission): bool
    {
        return $this->user !== null && Role::fromString((string) $this->user['role'])->can($permission);
    }

    /**
     * @return array<mixed>
     */
    protected function arrayInput(string $key): array
    {
        $value = $this->request->input($key, []);

        return is_array($value) ? $value : [];
    }

    protected function nullableInt(string $key): ?int
    {
        $value = $this->request->input($key);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
