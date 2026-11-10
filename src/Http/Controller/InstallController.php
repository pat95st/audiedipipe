<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http\Controller;

use AudiEdiPipe\Http\Response;
use AudiEdiPipe\Infrastructure\I18n\Translator;
use AudiEdiPipe\Kernel\Installer;
use Throwable;

/**
 * Web installer wizard (only reachable while the app is not installed).
 */
final class InstallController extends Controller
{
    public function show(): Response
    {
        $installer = new Installer($this->app->root);
        $checks = $installer->requirements((string) ($this->session->get('install_ffmpeg') ?: 'ffmpeg'));
        $old = $this->session->get('install_old', []);

        return Response::html($this->view->render('install/index', [
            'title' => $this->view->t('install.title'),
            'checks' => $checks,
            'ok' => Installer::requirementsMet($checks),
            'old' => is_array($old) ? $old : [],
            'error' => (string) $this->session->get('install_error', ''),
            'defaultUrl' => $this->request->origin() . rtrim(dirname((string) ($this->request->server['SCRIPT_NAME'] ?? '/index.php')), '/'),
            'languages' => $this->app->get(Translator::class)->available(),
            'drivers' => array_values(array_filter(['mysql', 'pgsql'], static fn (string $d): bool => extension_loaded('pdo_' . $d))),
        ], 'layout_guest'));
    }

    public function check(): Response
    {
        $installer = new Installer($this->app->root);

        return Response::json($installer->requirements($this->request->string('ffmpeg', 'ffmpeg')));
    }

    public function install(): Response
    {
        $input = $this->request->post;
        unset($input['_csrf']);
        $old = $input;
        unset($old['db_password'], $old['admin_password'], $old['admin_password_confirm']);
        $this->session->set('install_old', $old);
        $this->session->set('install_ffmpeg', (string) ($input['ffmpeg'] ?? 'ffmpeg'));
        try {
            if (($input['admin_password'] ?? '') !== ($input['admin_password_confirm'] ?? '')) {
                throw new \InvalidArgumentException($this->view->t('account.error.password_mismatch'));
            }
            if (($input['db_driver'] ?? '') === 'sqlite') {
                throw new \InvalidArgumentException('SQLite is only supported for tests.');
            }
            (new Installer($this->app->root))->install($input);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if ($e instanceof \AudiEdiPipe\Application\ValidationException) {
                $message = $this->view->t($e->getMessage(), $e->params);
            }
            $this->session->set('install_error', $message);

            return $this->redirect('/install');
        }
        $this->session->remove('install_old');
        $this->session->remove('install_error');
        $this->session->flash('success', $this->view->t('install.done'));

        return $this->redirect('/login');
    }
}
