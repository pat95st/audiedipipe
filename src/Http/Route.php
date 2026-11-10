<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http;

final class Route
{
    private string $regex;

    /**
     * @param list<string> $roles
     */
    public function __construct(
        public readonly string $method,
        public readonly string $pattern,
        public readonly string $controller,
        public readonly string $action,
        public readonly bool $auth,
        public readonly array $roles,
        public readonly bool $csrf,
        public readonly ?string $name,
    ) {
        $quoted = preg_quote($pattern, '#');
        $regex = (string) preg_replace('#\\\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\\\}#', '(?P<$1>[^/]+)', $quoted);
        $this->regex = '#^' . $regex . '$#';
    }

    /**
     * @return array<string, string>|null
     */
    public function matches(string $path): ?array
    {
        if (preg_match($this->regex, $path, $matches) !== 1) {
            return null;
        }
        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = rawurldecode($value);
            }
        }

        return $params;
    }
}
