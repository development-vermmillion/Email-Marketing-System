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
