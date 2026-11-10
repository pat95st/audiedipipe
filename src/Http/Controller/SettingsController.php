<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http\Controller;

use AudiEdiPipe\Application\Pipeline\Engine\PipelineRunner;
use AudiEdiPipe\Application\Settings\Command\SaveSettings;
use AudiEdiPipe\Application\Settings\Query\GetSettings;
use AudiEdiPipe\Application\Source\SourceScanner;
use AudiEdiPipe\Application\ValidationException;
use AudiEdiPipe\Http\Response;
use AudiEdiPipe\Infrastructure\Audio\Ffmpeg;
use AudiEdiPipe\Infrastructure\I18n\Translator;
use AudiEdiPipe\Infrastructure\Mail\ConfiguredMailer;
use AudiEdiPipe\Infrastructure\Mail\Mailer;
use AudiEdiPipe\Infrastructure\Mail\Message;
use AudiEdiPipe\Infrastructure\Mail\SmtpMailer;
use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Storage\SftpStorage;
use AudiEdiPipe\Kernel\App;
use Throwable;

final class SettingsController extends Controller
{
    public function show(): Response
    {
        $ffmpeg = $this->app->get(Ffmpeg::class)->version();
        $extensions = [];
        foreach (['pdo_mysql', 'pdo_pgsql', 'curl', 'mbstring', 'sodium', 'openssl', 'ftp', 'ssh2', 'gd', 'intl', 'zip'] as $ext) {
            $extensions[$ext] = extension_loaded($ext);
        }

        return $this->render('settings/show', [
            'title' => $this->view->t('nav.settings'),
            's' => $this->query(new GetSettings()),
            'languages' => $this->app->get(Translator::class)->available(),
            'system' => [
                'version' => App::VERSION,
                'php' => PHP_VERSION,
                'db' => $this->app->get(Database::class)->driver(),
                'ffmpeg' => $ffmpeg,
                'sftp' => SftpStorage::backend(),
                'extensions' => $extensions,
                'cron_url' => $this->app->url() . '/cron?token=' . rawurlencode($this->app->config->string('worker.cron_token')),
                'cron_cli' => '* * * * * php ' . $this->app->root . '/bin/console cron >/dev/null 2>&1',
                'last_cron' => @file_get_contents($this->app->storagePath('cache/last_cron')) ?: null,
            ],
        ]);
    }

    public function save(): Response
    {
        $values = [];
        foreach (['app_name', 'primary_color', 'secondary_color', 'default_locale', 'default_theme', 'upload_max_mb', 'keep_work_files_days'] as $key) {
            if ($this->request->input($key) !== null) {
                $values[$key] = $this->request->input($key);
            }
        }
        if (is_array($this->request->input('smtp'))) {
            $values['smtp'] = $this->request->input('smtp');
        }
        if ($this->request->bool('remove_logo')) {
            $values['logo'] = '';
        }
        $this->command(new SaveSettings($values));
        $this->flash('success', 'common.saved');

        return $this->redirect('/settings');
    }

    public function uploadLogo(): Response
    {
        $file = $this->request->file('logo');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            throw new ValidationException('upload.error.failed', ['code' => $file['error'] ?? UPLOAD_ERR_NO_FILE]);
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $isSvg = $ext === 'svg' && str_contains((string) file_get_contents($file['tmp_name'], false, null, 0, 1000), '<svg');
        $info = $isSvg ? false : @getimagesize($file['tmp_name']);
        if (!$isSvg && ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true))) {
            throw new ValidationException('upload.error.image');
        }
        if ($isSvg && preg_match('/<script|on\w+\s*=|javascript:/i', (string) file_get_contents($file['tmp_name'])) === 1) {
            throw new ValidationException('upload.error.image');
        }
        $extension = $isSvg ? 'svg' : image_type_to_extension((int) ($info[2] ?? IMAGETYPE_PNG), false);
        $dir = $this->app->root . '/public/uploads/branding';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $name = 'logo-' . bin2hex(random_bytes(4)) . '.' . $extension;
        $moved = is_uploaded_file($file['tmp_name']) ? move_uploaded_file($file['tmp_name'], $dir . '/' . $name) : @rename($file['tmp_name'], $dir . '/' . $name);
        if (!$moved) {
            throw new ValidationException('upload.error.failed', ['code' => 'move']);
        }
        $this->command(new SaveSettings(['logo' => 'uploads/branding/' . $name]));
        $this->flash('success', 'settings.logo_saved');

        return $this->redirect('/settings');
    }

    public function testMail(): Response
    {
        $to = $this->request->string('to') ?: (string) ($this->user['email'] ?? '');
        $mailer = $this->app->get(Mailer::class);
        $resolved = $mailer instanceof ConfiguredMailer ? $mailer->resolve() : $mailer;
        try {
            $resolved->send(new Message([$to], $this->view->t('settings.test_mail_subject'), $this->view->t('settings.test_mail_body')));
            $this->flash('success', $resolved instanceof SmtpMailer ? 'settings.test_mail_sent' : 'settings.test_mail_logged', ['to' => $to]);
        } catch (Throwable $e) {
            $log = $resolved instanceof SmtpMailer ? ' | ' . implode(' | ', array_slice($resolved->transcript(), -4)) : '';
            $this->session->flash('danger', $e->getMessage() . $log);
        }

        return $this->redirect('/settings#mail');
    }

    public function runWorker(): Response
    {
        $scan = $this->app->get(SourceScanner::class)->scanDue();
        $stages = $this->app->get(PipelineRunner::class)->tick(min(25, $this->app->config->int('worker.time_budget', 50)));
        $imported = array_sum(array_map(static fn (array $r): int => $r['imported'], $scan));
        $this->flash('success', 'settings.worker_done', ['stages' => $stages, 'imported' => $imported]);

        return $this->redirect('/settings#system');
    }
}
