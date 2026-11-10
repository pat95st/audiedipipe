<?php

declare(strict_types=1);

use AudiEdiPipe\Infrastructure\Persistence\Migrator;
use AudiEdiPipe\Infrastructure\Persistence\Schema\Table;

return static function (Migrator $m): void {
    $m->create('users', static function (Table $t): void {
        $t->id()
            ->string('username', 100)
            ->string('email', 190)
            ->string('display_name', 190, true)
            ->string('password_hash', 255)
            ->string('role', 20, false, 'cutter')
            ->string('locale', 10, true)
            ->string('theme', 10, false, 'auto')
            ->text('totp_secret')
            ->boolean('totp_enabled')
            ->bigInteger('totp_last_step', false, 0)
            ->boolean('email_2fa_enabled')
            ->boolean('active', true)
            ->datetime('last_login_at')
            ->timestamps()
            ->unique(['username'])
            ->unique(['email'])
        ;
    });

    $m->create('passkeys', static function (Table $t): void {
        $t->id()
            ->integer('user_id')
            ->string('credential_id', 512)
            ->text('public_key', false)
            ->bigInteger('sign_count', false, 0)
            ->string('name', 100)
            ->datetime('last_used_at')
            ->datetime('created_at', false)
            ->index(['user_id'])
            ->foreign('user_id', 'users')
        ;
    });

    $m->create('auth_codes', static function (Table $t): void {
        $t->id()
            ->integer('user_id')
            ->string('purpose', 30)
            ->string('code_hash', 255)
            ->integer('attempts', false, 0)
            ->datetime('expires_at', false)
            ->datetime('used_at')
            ->datetime('created_at', false)
            ->index(['user_id', 'purpose'])
            ->foreign('user_id', 'users')
        ;
    });

    $m->create('login_attempts', static function (Table $t): void {
        $t->id()
            ->string('identifier', 190)
            ->datetime('attempted_at', false)
            ->index(['identifier', 'attempted_at'])
        ;
    });

    $m->create('settings', static function (Table $t): void {
        $t->id()
            ->string('name', 100)
            ->text('value')
            ->unique(['name'])
        ;
    });

    $m->create('sources', static function (Table $t): void {
        $t->id()
            ->string('name', 190)
            ->string('type', 30)
            ->text('config')
            ->string('path', 1000, false, '/')
            ->text('filter')
            ->integer('poll_interval', false, 300)
            ->string('after_import', 20, false, 'keep')
            ->boolean('enabled', true)
            ->datetime('last_scan_at')
            ->text('last_error')
            ->timestamps()
        ;
    });

    $m->create('metadata_templates', static function (Table $t): void {
        $t->id()
            ->string('name', 190)
            ->text('fields')
            ->timestamps()
        ;
    });

    $m->create('publish_targets', static function (Table $t): void {
        $t->id()
            ->string('name', 190)
            ->string('type', 30)
            ->string('slug', 100, true)
            ->text('config')
            ->boolean('enabled', true)
            ->timestamps()
        ;
    });

    $m->create('pipelines', static function (Table $t): void {
        $t->id()
            ->string('name', 190)
            ->text('description')
            ->boolean('enabled', true)
            ->integer('source_id', true)
            ->integer('metadata_template_id', true)
            ->text('stages')
            ->text('notifications')
            ->integer('created_by', true)
            ->timestamps()
            ->foreign('source_id', 'sources', 'SET NULL')
            ->foreign('metadata_template_id', 'metadata_templates', 'SET NULL')
        ;
    });

    $m->create('effect_presets', static function (Table $t): void {
        $t->id()
            ->string('effect_type', 50)
            ->string('name', 190)
            ->text('params')
            ->boolean('builtin')
            ->timestamps()
            ->index(['effect_type'])
        ;
    });

    $m->create('seen_files', static function (Table $t): void {
        $t->id()
            ->integer('source_id')
            ->string('remote_path', 1000)
            ->string('fingerprint', 64)
            ->bigInteger('size', false, 0)
            ->datetime('seen_at', false)
            ->index(['source_id', 'fingerprint'], true)
            ->foreign('source_id', 'sources')
        ;
    });

    $m->create('jobs', static function (Table $t): void {
        $t->id()
            ->integer('pipeline_id')
            ->string('status', 30, false, 'queued')
            ->string('origin', 20, false, 'upload')
            ->integer('source_id', true)
            ->string('original_name', 255)
            ->string('input_path', 1000)
            ->string('current_file', 1000, true)
            ->string('output_path', 1000, true)
            ->integer('current_stage', false, 0)
            ->text('metadata')
            ->text('error')
            ->string('locked_by', 64, true)
            ->datetime('locked_at')
            ->integer('created_by', true)
            ->timestamps()
            ->datetime('finished_at')
            ->index(['status'])
            ->index(['pipeline_id'])
            ->foreign('pipeline_id', 'pipelines')
        ;
    });

    $m->create('job_stages', static function (Table $t): void {
        $t->id()
            ->integer('job_id')
            ->integer('position')
            ->string('stage_key', 64)
            ->string('type', 50)
            ->string('name', 190)
            ->string('status', 20, false, 'pending')
            ->text('params')
            ->text('log')
            ->string('output_path', 1000, true)
            ->integer('actor_id', true)
            ->datetime('started_at')
            ->datetime('finished_at')
            ->index(['job_id', 'position'])
            ->foreign('job_id', 'jobs')
        ;
    });

    $m->create('feed_items', static function (Table $t): void {
        $t->id()
            ->integer('target_id')
            ->integer('job_id', true)
            ->string('guid', 190)
            ->string('title', 255)
            ->text('description')
            ->string('file_url', 1000)
            ->bigInteger('file_size', false, 0)
            ->string('mime_type', 50)
            ->integer('duration', false, 0)
            ->string('image_url', 1000, true)
            ->datetime('published_at', false)
            ->index(['target_id', 'published_at'])
            ->foreign('target_id', 'publish_targets')
        ;
    });

    $m->create('audit_log', static function (Table $t): void {
        $t->id()
            ->integer('user_id', true)
            ->string('action', 100)
            ->text('details')
            ->datetime('created_at', false)
            ->index(['created_at'])
        ;
    });
};
