<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http\Controller;

use AudiEdiPipe\Application\Auth\AuthService;
use AudiEdiPipe\Application\User\Command\ChangePassword;
use AudiEdiPipe\Application\User\Command\DeletePasskey;
use AudiEdiPipe\Application\User\Command\DisableTotp;
use AudiEdiPipe\Application\User\Command\EnableTotp;
use AudiEdiPipe\Application\User\Command\RegisterPasskey;
use AudiEdiPipe\Application\User\Command\SetEmailTwoFactor;
use AudiEdiPipe\Application\User\Command\UpdateProfile;
use AudiEdiPipe\Application\User\Query\ListPasskeys;
use AudiEdiPipe\Http\Response;
use AudiEdiPipe\Infrastructure\I18n\Translator;
use AudiEdiPipe\Infrastructure\Persistence\SettingsRepository;
use AudiEdiPipe\Infrastructure\Security\Totp;
use AudiEdiPipe\Infrastructure\Security\WebAuthn\WebAuthnVerifier;
use Throwable;

final class AccountController extends Controller
{
    public function show(): Response
    {
        $pendingSecret = (string) $this->session->get('totp_pending_secret', '');
        $settings = $this->app->get(SettingsRepository::class);

        return $this->render('account/show', [
            'title' => $this->view->t('account.title'),
            'passkeys' => $this->query(new ListPasskeys($this->userId())),
            'languages' => $this->app->get(Translator::class)->available(),
            'totpSecret' => $pendingSecret,
            'totpUri' => $pendingSecret !== ''
                ? (new Totp())->provisioningUri($pendingSecret, (string) ($this->user['email'] ?? ''), $settings->string('app_name'))
                : '',
        ]);
    }

    public function updateProfile(): Response
    {
        $locale = $this->request->string('locale');
        $this->command(new UpdateProfile(
            $this->userId(),
            $this->request->string('email'),
            $this->request->string('display_name') ?: null,
            $locale !== '' ? $locale : null,
            $this->request->string('theme', 'auto'),
        ));
        $this->flash('success', 'common.saved');

        return $this->redirect('/account');
    }

    public function changePassword(): Response
    {
        $new = (string) $this->request->input('new_password', '');
        if ($new !== (string) $this->request->input('new_password_confirm', '')) {
            $this->flash('danger', 'account.error.password_mismatch');

            return $this->redirect('/account');
        }
        $this->command(new ChangePassword($this->userId(), (string) $this->request->input('current_password', ''), $new));
        $this->flash('success', 'account.password_changed');

        return $this->redirect('/account');
    }

    public function totpStart(): Response
    {
        $this->session->set('totp_pending_secret', Totp::generateSecret());

        return Response::redirect($this->view->url('/account') . '#security');
    }

    public function totpEnable(): Response
    {
        $secret = (string) $this->session->get('totp_pending_secret', '');
        if ($secret === '') {
            return $this->redirect('/account');
        }
        $this->command(new EnableTotp($this->userId(), $secret, $this->request->string('code')));
        $this->session->remove('totp_pending_secret');
        $this->flash('success', 'account.totp_enabled');

        return Response::redirect($this->view->url('/account') . '#security');
    }

    public function totpDisable(): Response
    {
        $this->command(new DisableTotp($this->userId()));
        $this->flash('success', 'account.totp_disabled');

        return Response::redirect($this->view->url('/account') . '#security');
    }

    public function emailTwoFactor(): Response
    {
        $enabled = $this->request->bool('enabled');
        $this->command(new SetEmailTwoFactor($this->userId(), $enabled));
        $this->flash('success', $enabled ? 'account.email_2fa_enabled' : 'account.email_2fa_disabled');

        return Response::redirect($this->view->url('/account') . '#security');
    }

    public function passkeyOptions(): Response
    {
        if ($this->user === null) {
            return Response::json(['error' => 'unauthenticated'], 401);
        }

        return Response::json($this->app->get(AuthService::class)->passkeyRegistrationOptions($this->session, $this->webAuthn(), $this->user));
    }

    public function passkeyRegister(): Response
    {
        $data = $this->request->json();

        try {
            $result = $this->app->get(AuthService::class)->verifyPasskeyRegistration($this->session, $this->webAuthn(), $data);
        } catch (Throwable $e) {
            return Response::json(['error' => $this->view->t('account.error.passkey') . ' (' . $e->getMessage() . ')'], 400);
        }
        $this->command(new RegisterPasskey(
            $this->userId(),
            is_string($data['name'] ?? null) ? $data['name'] : 'Passkey',
            $result['credentialId'],
            $result['publicKeyPem'],
            $result['signCount'],
        ));
        $this->flash('success', 'account.passkey_added');

        return Response::json(['ok' => true]);
    }

    public function passkeyDelete(): Response
    {
        $this->command(new DeletePasskey($this->userId(), $this->request->intAttribute('id')));
        $this->flash('success', 'account.passkey_removed');

        return Response::redirect($this->view->url('/account') . '#security');
    }

    private function webAuthn(): WebAuthnVerifier
    {
        $origin = $this->request->origin();

        return new WebAuthnVerifier(
            (string) parse_url($origin, PHP_URL_HOST),
            $this->app->get(SettingsRepository::class)->string('app_name'),
            $origin,
        );
    }
}
