# audiEdiPipe

Pipeline-based audio editor. New audio files are picked up automatically from a
storage location and run through a configurable pipeline of processing stages —
much like a GitLab CI pipeline, but for audio. Built for podcast production:
clean up, level, (optionally) cut and tag, then publish.

Pure PHP 8.2+, **no runtime Composer dependencies**, so it runs on a managed
server, on shared hosting/webspace without root, or on a Raspberry Pi. ffmpeg
does the audio heavy lifting.

## Features

### Processing pipeline
Ordered stages, configured per pipeline in the web UI:

- **Effects** (ffmpeg filter chains, each with a console-style GUI):
  - Noise reduction
  - Low cut (Butterworth high-pass, selectable 12–48 dB/oct)
  - Parametric EQ with **6 bands** (bell, shelves, pass filters, notch)
  - Compressor
  - Dynamic EQ
  - Gate
  - Expander
  - Master leveler (loudness normalisation)
- **Manual edit** stage: in-browser cutting and setting of metadata, with
  reusable **metadata templates**
- **Review** stage: a reviewer (“Prüfer”) approves or rejects before export
- **Notify** and **Publish** stages

### Sources (storage watchers)
Automatic scanning of: **local folder, WebDAV, Nextcloud, FTP, SFTP, SCP,
Samba/CIFS**. Per-source filtering by filename glob, regex, extension, size and
minimum age. You can also upload a file manually in the UI to test a pipeline.

### Publishing
Podcast platform upload after processing: **Buzzsprout, Podbean, Spreaker** and
a generated **RSS feed**.

### Notifications
**Authorised SMTP** (STARTTLS/SSL with login) e-mail notifications, e.g. when a
new file arrives or a job needs review.

### Users & authentication
- Roles: **Administrator, Cutter, Reviewer (Prüfer)**
- **2FA**: time-based one-time passwords (**TOTP**) and **e-mail codes**
- **Passkeys** (WebAuthn), implemented without external libraries
- Stored secrets (storage passwords, API tokens, TOTP secrets) are encrypted at
  rest with libsodium

### UI
- **Bootstrap**-based, responsive, **light & dark mode**
- Installable **PWA** (web manifest + service worker, offline shell)
- **i18n**: German and English included; add a language by dropping a
  `lang/<code>.json` file in
- **Branding**: custom instance name, logo and colour scheme

### Architecture & quality
- **CQRS**: every write is a `Command`, every read a `Query`, dispatched over
  in-process buses; commands run inside a database transaction
- Clean layering: `Domain`, `Application`, `Infrastructure`, `Http`, `Kernel`
- Database: **MariaDB/MySQL** or **PostgreSQL** (SQLite for tests/dev)
- **PHPStan** (level 8) + **PHP-CS-Fixer** static analysis
- **PHPUnit** tests split into unit / database / integration / acceptance suites
- **Automatic installer** (CLI and web) with a system requirements check

## Requirements

- PHP **8.2+** with `pdo`, `mbstring`, `json`, `openssl`, `sodium`, `curl`, `dom`
- `ffmpeg` / `ffprobe` on the `PATH` (or configured paths)
- A database: MariaDB/MySQL or PostgreSQL (SQLite works for local testing)
- Optional: `ext-ftp`, `ext-ssh2` (or `phpseclib`), `smbclient` for the
  corresponding storage adapters; `ext-gd`, `ext-intl`

## Installation

### Web installer
Point your web server’s document root at `public/` (or drop the project on a
webspace — the root `.htaccess` routes requests into `public/` and blocks the
application directories). Open the site in a browser and follow the installer,
which runs a requirements check and creates the first administrator account.

### CLI installer
```bash
php bin/console check        # verify system requirements
php bin/console install      # interactive, or:
php bin/console install --db-driver=mysql --no-interaction \
    --admin-username=admin --admin-email=admin@example.org --admin-password=...
```

### Processing files automatically
Add a cron job (every minute) to scan sources and advance jobs:
```cron
* * * * * php /path/to/audiedipipe/bin/console cron >/dev/null 2>&1
```
Or run a long-lived worker (e.g. under systemd):
```bash
php bin/console worker:loop --sleep=10
```
If you have no cron at all, the web cron endpoint `/cron?token=…` and an
optional pseudo-cron (run after page requests) are available.

## Development

```bash
composer install            # dev tools: phpunit, phpstan, php-cs-fixer
composer test               # all PHPUnit suites
composer test:unit          # or: test:database / test:integration / test:acceptance
composer stan               # PHPStan level 8
composer cs                 # PHP-CS-Fixer (dry-run); cs:fix to apply
composer check              # cs + stan + test
```

The test suites use an isolated SQLite database and a temporary application root,
so they never touch your real configuration or storage.

### Project layout
```
bin/console            CLI (install, migrate, cron, worker, user management)
public/                web entry point, assets, service worker, manifest
src/
  Domain/              core model: audio effects, pipeline, user, job
  Application/         CQRS handlers, pipeline engine & stages, publishing
  Infrastructure/      persistence, storage adapters, security, mail, i18n, ffmpeg
  Http/                kernel, routing, controllers, views, sessions
  Kernel/              composition root, container, config, installer, worker
migrations/            database schema migrations
templates/             server-rendered views
lang/                  translation catalogues (de, en)
tests/                 Unit / Database / Integration / Acceptance
```

## Configuration

The installer writes `config/config.php` (see `config/config.example.php` for all
options: app key, database, storage paths, ffmpeg paths, worker time budget and
cron token). Secrets added later via the UI are encrypted with the app key.

## License

Proprietary (see `composer.json`). To be decided by the repository owner.
