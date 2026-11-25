#!/usr/bin/env bash
# Nightly MySQL backup. Cron: 15 2 * * * /var/www/email-marketing/deploy/backup.sh
# Reads DB_* from .env. Keeps 14 days. Copy the dumps off the server as well.
set -euo pipefail
APP=/var/www/email-marketing
DEST=${BACKUP_DIR:-/var/backups/email-marketing}
mkdir -p "$DEST"
get() { grep -E "^$1=" "$APP/.env" | head -1 | cut -d= -f2- | tr -d '"'; }
umask 077
MYSQL_PWD="$(get DB_PASSWORD)" mysqldump --single-transaction --quick --routines \
  -h "$(get DB_HOST)" -P "$(get DB_PORT)" -u "$(get DB_USERNAME)" "$(get DB_DATABASE)" \
  | gzip > "$DEST/ems-$(date +%F).sql.gz"
find "$DEST" -name 'ems-*.sql.gz' -mtime +14 -delete
# Also back up .env (it holds APP_KEY, without which encrypted SMTP credentials cannot be read).
cp "$APP/.env" "$DEST/env-$(date +%F).bak"; find "$DEST" -name 'env-*.bak' -mtime +14 -delete
