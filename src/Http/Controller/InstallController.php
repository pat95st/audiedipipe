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
        [$ffmpeg, $ffprobe] = $this->binaryPaths([
            'ffmpeg' => $this->session->get('install_ffmpeg'),
            'ffprobe' => $this->session->get('install_ffprobe'),
        ]);
        $checks = $installer->requirements($ffmpeg, $ffprobe);
        $old = $this->session->get('install_old', []);
        $old = is_array($old) ? $old : [];
        $old += ['ffmpeg' => $ffmpeg, 'ffprobe' => $ffprobe];

        return Response::html($this->view->render('install/index', [
            'title' => $this->view->t('install.title'),
            'checks' => $checks,
            'ok' => Installer::requirementsMet($checks),
            'old' => $old,
            'error' => (string) $this->session->get('install_error', ''),
            'defaultUrl' => $this->request->origin() . rtrim(dirname((string) ($this->request->server['SCRIPT_NAME'] ?? '/index.php')), '/'),
            'languages' => $this->app->get(Translator::class)->available(),
            'drivers' => array_values(array_filter(['mysql', 'pgsql'], static fn (string $d): bool => extension_loaded('pdo_' . $d))),
        ], 'layout_guest'));
    }

    /**
     * Re-runs the requirements check with the binary paths currently entered
     * in the form (called live by assets/js/install.js).
     */
    public function check(): Response
    {
        [$ffmpeg, $ffprobe] = $this->binaryPaths([
            'ffmpeg' => $this->request->string('ffmpeg'),
            'ffprobe' => $this->request->string('ffprobe'),
        ]);
        $this->session->set('install_ffmpeg', $ffmpeg);
        $this->session->set('install_ffprobe', $ffprobe);
        $checks = (new Installer($this->app->root))->requirements($ffmpeg, $ffprobe);

        return Response::json([
            'ok' => Installer::requirementsMet($checks),
            'checks' => $checks,
            'html' => $this->view->partial('install/_checks', ['checks' => $checks]),
        ]);
    }

    public function install(): Response
    {
        $input = $this->request->post;
        unset($input['_csrf']);
        $old = $input;
        unset($old['db_password'], $old['admin_password'], $old['admin_password_confirm']);
        $this->session->set('install_old', $old);
        [$ffmpeg, $ffprobe] = $this->binaryPaths($input);
        $input['ffmpeg'] = $ffmpeg;
        $input['ffprobe'] = $ffprobe;
        $this->session->set('install_ffmpeg', $ffmpeg);
        $this->session->set('install_ffprobe', $ffprobe);
        try {
            $installer = new Installer($this->app->root);
            if (!Installer::requirementsMet($installer->requirements($ffmpeg, $ffprobe))) {
                throw new \InvalidArgumentException($this->view->t('install.fix_requirements'));
            }
            if (($input['admin_password'] ?? '') !== ($input['admin_password_confirm'] ?? '')) {
                throw new \InvalidArgumentException($this->view->t('account.error.password_mismatch'));
            }
            if (($input['db_driver'] ?? '') === 'sqlite') {
                throw new \InvalidArgumentException('SQLite is only supported for tests.');
            }
            $installer->install($input);
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
        $this->session->remove('install_ffmpeg');
        $this->session->remove('install_ffprobe');
        $this->session->flash('success', $this->view->t('install.done'));

        return $this->redirect('/login');
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array{0: string, 1: string} ffmpeg and ffprobe path, defaulting to the PATH lookup
     */
    private function binaryPaths(array $source): array
    {
        $paths = [];
        foreach (['ffmpeg', 'ffprobe'] as $key) {
            $value = $source[$key] ?? '';
            $value = is_scalar($value) ? trim((string) $value) : '';
            $paths[] = $value !== '' ? $value : $key;
        }

        return [$paths[0], $paths[1]];
    }
}
