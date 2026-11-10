<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http;

/**
 * Session backed by PHP's native session handling with hardened cookie flags.
 */
final class NativeSession extends ArraySession
{
    public function __construct(bool $secure)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('AEPSESSID');
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            ini_set('session.use_strict_mode', '1');
            session_start();
        }
        // Bind the session superglobal by reference so PHP persists our changes.
        $this->data = &$_SESSION;
    }

    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        $this->data = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
