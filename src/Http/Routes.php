<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http;

use AudiEdiPipe\Http\Controller\AccountController;
use AudiEdiPipe\Http\Controller\AssetController;
use AudiEdiPipe\Http\Controller\AuthController;
use AudiEdiPipe\Http\Controller\CronController;
use AudiEdiPipe\Http\Controller\DashboardController;
use AudiEdiPipe\Http\Controller\FeedController;
use AudiEdiPipe\Http\Controller\JobController;
use AudiEdiPipe\Http\Controller\PipelineController;
use AudiEdiPipe\Http\Controller\PreviewController;
use AudiEdiPipe\Http\Controller\PublishController;
use AudiEdiPipe\Http\Controller\SettingsController;
use AudiEdiPipe\Http\Controller\SourceController;
use AudiEdiPipe\Http\Controller\TemplateController;
use AudiEdiPipe\Http\Controller\UserController;

final class Routes
{
    public static function register(Router $r): void
    {
        // Public
        $r->get('/login', AuthController::class, 'loginForm', false);
        $r->post('/login', AuthController::class, 'login', false);
        $r->get('/login/2fa', AuthController::class, 'twoFactorForm', false);
        $r->post('/login/2fa', AuthController::class, 'twoFactor', false);
        $r->post('/login/2fa/email', AuthController::class, 'sendEmailCode', false);
        $r->post('/login/passkey/options', AuthController::class, 'passkeyOptions', false);
        $r->post('/login/passkey', AuthController::class, 'passkeyLogin', false);
        $r->post('/logout', AuthController::class, 'logout');
        $r->get('/locale/{code}', AuthController::class, 'setLocale', false);
        $r->get('/manifest.webmanifest', AssetController::class, 'manifest', false);
        $r->get('/theme.css', AssetController::class, 'themeCss', false);
        $r->get('/i18n/{locale}', AssetController::class, 'translations', false);
        $r->get('/offline', AssetController::class, 'offline', false);
        $r->get('/feed/{slug}', FeedController::class, 'feed', false);
        $r->get('/media/{slug}/{file}', FeedController::class, 'media', false);
        $r->get('/cron', CronController::class, 'run', false);
        $r->add('POST', '/cron', CronController::class, 'run', false, [], false);

        // Dashboard & account
        $r->get('/', DashboardController::class, 'index');
        $r->get('/account', AccountController::class, 'show');
        $r->post('/account/profile', AccountController::class, 'updateProfile');
        $r->post('/account/password', AccountController::class, 'changePassword');
        $r->post('/account/totp/start', AccountController::class, 'totpStart');
        $r->post('/account/totp/enable', AccountController::class, 'totpEnable');
        $r->post('/account/totp/disable', AccountController::class, 'totpDisable');
        $r->post('/account/email-2fa', AccountController::class, 'emailTwoFactor');
        $r->post('/account/passkeys/options', AccountController::class, 'passkeyOptions');
        $r->post('/account/passkeys', AccountController::class, 'passkeyRegister');
        $r->post('/account/passkeys/{id}/delete', AccountController::class, 'passkeyDelete');

        // Jobs
        $r->get('/jobs', JobController::class, 'index', true, ['jobs.view']);
        $r->get('/jobs/status', JobController::class, 'statusFeed', true, ['jobs.view']);
        $r->get('/upload', JobController::class, 'uploadForm', true, ['jobs.upload']);
        $r->post('/upload', JobController::class, 'upload', true, ['jobs.upload']);
        $r->get('/jobs/{id}', JobController::class, 'show', true, ['jobs.view']);
        $r->get('/jobs/{id}/audio/{which}', JobController::class, 'audio', true, ['jobs.view']);
        $r->get('/jobs/{id}/peaks/{which}', JobController::class, 'peaks', true, ['jobs.view']);
        $r->get('/jobs/{id}/download', JobController::class, 'download', true, ['jobs.view']);
        $r->get('/jobs/{id}/edit', JobController::class, 'editForm', true, ['jobs.edit']);
        $r->post('/jobs/{id}/edit', JobController::class, 'submitEdit', true, ['jobs.edit']);
        $r->post('/jobs/{id}/review', JobController::class, 'review', true, ['jobs.review']);
        $r->post('/jobs/{id}/retry', JobController::class, 'retry', true, ['jobs.retry']);
        $r->post('/jobs/{id}/cancel', JobController::class, 'cancel', true, ['jobs.retry']);
        $r->post('/jobs/{id}/delete', JobController::class, 'delete', true, ['jobs.delete']);

        // Pipelines
        $r->get('/pipelines', PipelineController::class, 'index', true, ['pipelines.manage']);
        $r->get('/pipelines/new', PipelineController::class, 'create', true, ['pipelines.manage']);
        $r->post('/pipelines/save', PipelineController::class, 'save', true, ['pipelines.manage']);
        $r->get('/pipelines/{id}', PipelineController::class, 'edit', true, ['pipelines.manage']);
        $r->post('/pipelines/{id}/delete', PipelineController::class, 'delete', true, ['pipelines.manage']);
        $r->post('/pipelines/{id}/duplicate', PipelineController::class, 'duplicate', true, ['pipelines.manage']);

        // Effect previews with an own test file
        $r->post('/preview/upload', PreviewController::class, 'upload', true, ['jobs.upload', 'pipelines.manage']);
        $r->post('/preview/render', PreviewController::class, 'render', true, ['jobs.upload', 'pipelines.manage']);
        $r->get('/preview/{token}/{file}', PreviewController::class, 'file', true, ['jobs.upload', 'pipelines.manage']);

        // Storage connections / sources
        $r->get('/sources', SourceController::class, 'index', true, ['sources.manage']);
        $r->get('/sources/new', SourceController::class, 'form', true, ['sources.manage']);
        $r->get('/sources/{id}', SourceController::class, 'form', true, ['sources.manage']);
        $r->post('/sources/save', SourceController::class, 'save', true, ['sources.manage']);
        $r->post('/sources/{id}/delete', SourceController::class, 'delete', true, ['sources.manage']);
        $r->post('/sources/{id}/test', SourceController::class, 'test', true, ['sources.manage']);
        $r->post('/sources/{id}/scan', SourceController::class, 'scan', true, ['sources.manage']);

        // Metadata templates & effect presets
        $r->get('/templates', TemplateController::class, 'index', true, ['templates.manage']);
        $r->post('/templates/save', TemplateController::class, 'save', true, ['templates.manage']);
        $r->post('/templates/{id}/delete', TemplateController::class, 'delete', true, ['templates.manage']);
        $r->post('/templates/cover', TemplateController::class, 'uploadCover', true, ['templates.manage']);
        $r->get('/presets', TemplateController::class, 'presets', true, ['pipelines.manage']);
        $r->post('/presets', TemplateController::class, 'savePreset', true, ['pipelines.manage']);
        $r->post('/presets/{id}/delete', TemplateController::class, 'deletePreset', true, ['pipelines.manage']);

        // Publishing
        $r->get('/publish', PublishController::class, 'index', true, ['publish.manage']);
        $r->get('/publish/new', PublishController::class, 'form', true, ['publish.manage']);
        $r->get('/publish/{id}', PublishController::class, 'form', true, ['publish.manage']);
        $r->post('/publish/save', PublishController::class, 'save', true, ['publish.manage']);
        $r->post('/publish/{id}/delete', PublishController::class, 'delete', true, ['publish.manage']);

        // Administration
        $r->get('/users', UserController::class, 'index', true, ['users.manage']);
        $r->get('/users/new', UserController::class, 'form', true, ['users.manage']);
        $r->get('/users/{id}', UserController::class, 'form', true, ['users.manage']);
        $r->post('/users/save', UserController::class, 'save', true, ['users.manage']);
        $r->post('/users/{id}/delete', UserController::class, 'delete', true, ['users.manage']);
        $r->get('/settings', SettingsController::class, 'show', true, ['settings.manage']);
        $r->post('/settings', SettingsController::class, 'save', true, ['settings.manage']);
        $r->post('/settings/logo', SettingsController::class, 'uploadLogo', true, ['settings.manage']);
        $r->post('/settings/test-mail', SettingsController::class, 'testMail', true, ['settings.manage']);
        $r->post('/settings/worker', SettingsController::class, 'runWorker', true, ['settings.manage']);
    }
}
