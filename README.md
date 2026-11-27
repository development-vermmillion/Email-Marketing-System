# Email Marketing System

Laravel 12 / PHP 8.2+ bulk email platform: audiences with CSV import, groups, campaigns, HTML templates, SMTP relay settings, a queue-based idempotent sending engine, reports, webhooks, unsubscribe and suppression handling.

## Features

- Admin login, dashboard with charts and live campaign progress
- Audiences: manual add, background CSV import (chunked, with downloadable skipped/failed rows), groups, bulk actions, soft delete
- Campaigns: draft, schedule, send now, pause, resume, cancel, duplicate, 7-day trash with automatic purge; estimate and recipient snapshot; the same contact in several groups is emailed once
- Templates: CodeMirror editor, server-rendered desktop/mobile preview, test send, safe variables `{{name}}` `{{email}}` `{{company}}` `{{unsubscribe_url}}`
- SMTP: encrypted credentials, per-second/minute/day limits, connection and test-email checks, provider-specific webhook adapters
- Sending engine: atomic per-recipient claim (a recipient is never sent twice, even if a job runs twice), temporary / permanent / systemic failure handling with automatic pause on auth or connection errors
- Reports with CSV export; tracking via signed links and open pixel
- Suppression list (append-only, survives contact deletion), RFC 8058 one-click unsubscribe headers
- Email validation (Audiences > Validate emails): background checks of format, domain mail records (MX/A), disposable domains, shared mailboxes (info@, support@) and common domain typos. Results: valid / risky / invalid, shown on each contact; invalid addresses are left out of campaigns and can be exported or deactivated. A single address can be tested instantly. An optional mailbox check (`MAILING_VALIDATION_SMTP_PROBE=true`) asks the recipient's mail server whether it accepts the address, without sending any email, to catch addresses that look fine but don't exist. It needs outbound port 25 and a server with a proper host name; see docs/DEPLOYMENT.md. Real bounces are still caught from provider webhooks.
- Activity log

## Local setup

```bash
composer install
cp .env.example .env && php artisan key:generate
# set DB_* (MySQL) and REDIS_*; for a quick trial use DB_CONNECTION=sqlite and QUEUE_CONNECTION=database
php artisan migrate --seed
php artisan serve
php artisan queue:work --queue=emails,imports,default
```
Login with `ADMIN_EMAIL` / `ADMIN_PASSWORD` from `.env` (a password is generated and printed if left empty).

### Local XAMPP (Windows, no Redis)

XAMPP has no Redis server or `phpredis` extension, so use the database for queue and cache in your local `.env`:

```
DB_CONNECTION=mysql        # create the database in phpMyAdmin first
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
```
Then `php artisan config:clear`, `php artisan migrate --seed`, and keep a worker running in a second terminal: `php artisan queue:work --queue=emails,imports,default`. Without the worker, imports and campaigns stay queued. Production should use Redis (the Redis client defaults to the pure-PHP `predis` package, so no PHP extension is needed there either).

## Tests

```bash
php artisan test
```
Tests use in-memory SQLite and a fake mail transport; no SMTP server is contacted.

## Production

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) (Supervisor, cron, Nginx, backups, SPF/DKIM/DMARC, webhooks) and the files in `deploy/`.

## Architecture notes

- `app/Services/CampaignSender.php` is the sending core; `CampaignService` owns the status state machine (compare-and-set transitions).
- Campaign counters are recomputed from `campaign_recipients`, never incremented, so they cannot drift.
- Configuration lives in `config/mailing.php` (rates, limits, retention, queue names).
