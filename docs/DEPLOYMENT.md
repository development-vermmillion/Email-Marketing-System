# Deployment guide

Target: Ubuntu 22.04/24.04, PHP 8.3, MySQL 8, Redis, Nginx, Supervisor.

## 1. Server packages

```bash
sudo apt install nginx mysql-server redis-server supervisor git unzip \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath php8.3-redis
```
Install Composer from getcomposer.org. Bind Redis to localhost and set `requirepass`.

## 2. Database

```sql
CREATE DATABASE email_marketing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ems'@'localhost' IDENTIFIED BY 'a-strong-password';
GRANT ALL ON email_marketing.* TO 'ems'@'localhost';
```

## 3. Application

```bash
cd /var/www && git clone https://github.com/development-vermmillion/Email-Marketing-System email-marketing
cd email-marketing
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
# edit .env: APP_URL (https), DB_*, REDIS_*, ADMIN_EMAIL / ADMIN_PASSWORD, MAILING_*
php artisan migrate --force
php artisan db:seed --force          # creates the admin user and a starter template
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache
```
If `ADMIN_PASSWORD` is empty, the seeder generates one and prints it once. Change it after the first login.

**Back up `.env`.** SMTP credentials are encrypted with `APP_KEY`; losing it makes them unreadable.

## 4. Web server

Use `deploy/nginx-email-marketing.conf`. Serve over HTTPS only: tracking links, unsubscribe links and webhooks all rely on `APP_URL` being the public HTTPS address. If behind a load balancer or CDN, check the trusted proxy setting in `bootstrap/app.php`.

## 5. Queue workers (Supervisor)

```bash
sudo cp deploy/supervisor-email-marketing.conf /etc/supervisor/conf.d/ems.conf
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status
```
- `emails` queue: 4 workers by default. Raise `numprocs` only if your SMTP provider allows; the per-second / per-minute / daily limits are enforced across all workers.
- `imports` and `default` queue: 1 worker.
- `REDIS_QUEUE_RETRY_AFTER` (420) must stay greater than the job timeout (300).
- After every deploy run `php artisan queue:restart`.

## 6. Scheduler (cron)

```
* * * * * cd /var/www/email-marketing && php artisan schedule:run >> /dev/null 2>&1
```
The scheduler dispatches due scheduled campaigns, refreshes statistics, recovers recipients stuck in "sending" after a worker crash, purges campaigns trashed for more than 7 days, prunes old events/imports and failed jobs.

## 7. Backups

`deploy/backup.sh` (nightly cron) dumps MySQL and `.env`. Test a restore at least once. Redis only holds queue/cache data and can be rebuilt; if Redis is lost mid-send, run `php artisan campaigns:recover-stale` and resume the campaign: already-sent recipients are never re-sent.

## 8. Email authentication (required for inbox delivery)

Publish these DNS records for the domain in your From address:

| Record | Purpose | Example |
|---|---|---|
| SPF (TXT on the domain) | Authorise your relay | `v=spf1 include:<provider-spf> ~all` |
| DKIM (TXT, selector from the provider) | Sign messages | provider supplies the key |
| DMARC (TXT `_dmarc`) | Policy and reports | `v=DMARC1; p=none; rua=mailto:dmarc@example.com` then tighten to `quarantine` / `reject` once reports are clean |

Also: use a dedicated sending (sub)domain, warm up volume gradually, and keep the daily limit at the level your provider approves. Many providers will suspend accounts with complaint rates above about 0.1% or bounce rates above a few percent.

## 9. Webhooks (delivery, bounce, complaint events)

Add an SMTP configuration in the admin with the matching provider and webhook secret, then point the provider at `https://<APP_URL>/webhooks/<provider>`:

| Provider | URL | Secret to store | Verification |
|---|---|---|---|
| generic | `/webhooks/generic` | shared secret | `X-Signature: <hex HMAC-SHA256 of body>` |
| sendgrid | `/webhooks/sendgrid` | Signed Event Webhook public key | ECDSA |
| mailgun | `/webhooks/mailgun` | HTTP webhook signing key | HMAC |
| postmark | `/webhooks/postmark` | token you choose | `X-Postmark-Token` header |
| ses | `/webhooks/ses` | SNS TopicArn | SNS certificate check |

Events are matched to recipients with a per-recipient token the system adds to each message as provider metadata. Duplicate deliveries are ignored.

## 10. Monitoring

- `supervisorctl status`, `storage/logs/laravel.log`, and Settings > System status (queue sizes, failed jobs).
- `php artisan queue:failed` and `php artisan queue:retry all`.
- Alert on: workers not running, `emails` queue growing without sending, failed jobs rising, a campaign auto-paused (activity log shows why).

## Known limits

- "Sent" means accepted by the SMTP relay, not delivered. Delivery, bounce and complaint figures need provider webhooks.
- Open tracking is approximate (mail clients prefetch or block images).
- Bootstrap, Chart.js and CodeMirror load from a CDN; self-host them if the admin must work without external network access.
- A relay that is down pauses the campaign automatically; resume manually after fixing it.
