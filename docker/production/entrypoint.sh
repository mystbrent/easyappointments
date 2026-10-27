#!/bin/sh
set -e

echo ">>> Setting storage directory permissions"
chown -R www-data:www-data /var/www/html/storage

# One-shot jobs (e.g. App Platform run_command): run the passed command instead
# of migrating and starting the web stack.
if [ "$#" -gt 0 ]; then
    exec "$@"
fi

echo ">>> Running database migrations"
cd /var/www/html
php index.php console migrate || echo ">>> Migration skipped (database may not be initialized yet)"

echo ">>> Starting supervisord"
exec /usr/bin/supervisord -c /etc/supervisord.conf
