<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http;

/**
 * In-memory session (tests, CLI).
 */
class ArraySession implements Session
{
    /** @var array<string, mixed> */
    protected array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerate(): void
    {
    }

    public function destroy(): void
    {
        $this->data = [];
    }

    public function flash(string $type, string $message): void
    {
        $flashes = $this->get('_flashes', []);
        $flashes = is_array($flashes) ? $flashes : [];
        $flashes[] = ['type' => $type, 'message' => $message];
        $this->set('_flashes', $flashes);
    }

    public function takeFlashes(): array
    {
        $flashes = $this->get('_flashes', []);
        $this->remove('_flashes');
        if (!is_array($flashes)) {
            return [];
        }
        $result = [];
        foreach ($flashes as $flash) {
            if (is_array($flash)) {
                $result[] = ['type' => (string) ($flash['type'] ?? 'info'), 'message' => (string) ($flash['message'] ?? '')];
            }
        }

        return $result;
    }
}
