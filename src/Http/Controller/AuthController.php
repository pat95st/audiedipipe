<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http\Controller;

use AudiEdiPipe\Application\Auth\AuthService;
use AudiEdiPipe\Http\Response;
use AudiEdiPipe\Infrastructure\I18n\Translator;
use AudiEdiPipe\Infrastructure\Persistence\SettingsRepository;
use AudiEdiPipe\Infrastructure\Security\WebAuthn\WebAuthnVerifier;
use Throwable;

final class AuthController extends Controller
{
    public function loginForm(): Response
    {
        if ($this->user !== null) {
            return $this->redirect('/');
        }

        return Response::html($this->view->render('auth/login', ['title' => $this->view->t('auth.login')], 'layout_guest'));
    }

    public function login(): Response
    {
        $auth = $this->app->get(AuthService::class);
        $identifier = $this->request->string('username');
        if ($auth->isLockedOut($identifier, $this->request->clientIp())) {
            $this->flash('danger', 'auth.error.locked', ['minutes' => AuthService::LOCK_MINUTES]);

            return $this->redirect('/login');
        }
        $user = $auth->attemptPassword($identifier, (string) $this->request->input('password', ''), $this->request->clientIp());
        if ($user === null) {
            $this->flash('danger', 'auth.error.invalid');

            return $this->redirect('/login');
        }
        if ($auth->needsSecondFactor($user)) {
            $auth->startSecondFactor($this->session, $user);
            if (!(bool) $user['totp_enabled'] && (bool) $user['email_2fa_enabled']) {
                $auth->sendEmailCode($user);
                $this->session->set('2fa_method', 'email');
            } else {
                $this->session->set('2fa_method', 'totp');
            }

            return $this->redirect('/login/2fa');
        }

        return $this->completeLogin($user);
    }

    public function twoFactorForm(): Response
    {
        $pending = $this->app->get(AuthService::class)->pendingUser($this->session);
        if ($pending === null) {
            return $this->redirect('/login');
        }

        $method = (string) $this->session->get('2fa_method', 'totp');
        $requested = (string) ($this->request->query['m'] ?? '');
        if ($requested === 'totp' && (bool) $pending['totp_enabled']) {
            $method = 'totp';
            $this->session->set('2fa_method', 'totp');
        }

        return Response::html($this->view->render('auth/two_factor', [
            'title' => $this->view->t('auth.two_factor'),
            'method' => $method,
            'hasTotp' => (bool) $pending['totp_enabled'],
            'hasEmail' => (bool) $pending['email_2fa_enabled'],
        ], 'layout_guest'));
    }

    public function twoFactor(): Response
    {
        $auth = $this->app->get(AuthService::class);
        $pending = $auth->pendingUser($this->session);
        if ($pending === null) {
            return $this->redirect('/login');
        }
        $method = $this->request->string('method', 'totp');
        if (!$auth->verifySecondFactor($pending, $method, $this->request->string('code'))) {
            $this->session->set('2fa_method', $method);
            $this->flash('danger', 'auth.error.invalid_code');

            return $this->redirect('/login/2fa');
        }

        return $this->completeLogin($pending);
    }

    public function sendEmailCode(): Response
    {
        $auth = $this->app->get(AuthService::class);
        $pending = $auth->pendingUser($this->session);
        if ($pending === null || !(bool) $pending['email_2fa_enabled']) {
            return $this->redirect('/login');
        }
        $last = (int) $this->session->get('2fa_mail_sent', 0);
        if ($last > time() - 30) {
            $this->flash('warning', 'auth.code_wait');
        } elseif ($auth->sendEmailCode($pending)) {
            $this->session->set('2fa_mail_sent', time());
            $this->flash('success', 'auth.code_sent');
        } else {
            $this->flash('danger', 'auth.error.mail_failed');
        }
        $this->session->set('2fa_method', 'email');

        return $this->redirect('/login/2fa');
    }

    public function passkeyOptions(): Response
    {
        return Response::json($this->app->get(AuthService::class)->passkeyLoginOptions($this->session, $this->webAuthn()));
    }

    public function passkeyLogin(): Response
    {
        $auth = $this->app->get(AuthService::class);
        try {
            $user = $auth->verifyPasskeyLogin($this->session, $this->webAuthn(), $this->request->json());
        } catch (Throwable $e) {
            return Response::json(['error' => $this->view->t('auth.error.passkey') . ' (' . $e->getMessage() . ')'], 400);
        }
        $auth->login($this->session, $user);

        return Response::json(['redirect' => $this->intended()]);
    }

    public function logout(): Response
    {
        $this->app->get(AuthService::class)->logout($this->session);

        return $this->redirect('/login');
    }

    public function setLocale(): Response
    {
        $code = $this->request->attribute('code');
        if ($this->app->get(Translator::class)->supports($code)) {
            $this->session->set('locale', $code);
        }
        $referer = $this->request->header('referer');

        return Response::redirect($referer !== '' && str_starts_with($referer, $this->request->origin()) ? $referer : $this->view->url('/'));
    }

    /**
     * @param array<string, mixed> $user
     */
    private function completeLogin(array $user): Response
    {
        $intended = $this->intended();
        $this->app->get(AuthService::class)->login($this->session, $user);

        return Response::redirect($intended);
    }

    private function intended(): string
    {
        $intended = (string) $this->session->get('intended', '/');
        $this->session->remove('intended');
        if (!str_starts_with($intended, '/') || str_starts_with($intended, '//')) {
            $intended = '/';
        }

        return $this->view->url($intended);
    }

    private function webAuthn(): WebAuthnVerifier
    {
        $host = (string) parse_url($this->app->url(), PHP_URL_HOST);
        $origin = $this->request->origin();
        $requestHost = (string) parse_url($origin, PHP_URL_HOST);

        return new WebAuthnVerifier(
            $requestHost !== '' ? $requestHost : $host,
            $this->app->get(SettingsRepository::class)->string('app_name'),
            $origin,
        );
    }
}
