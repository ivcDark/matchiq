#!/bin/sh
set -e

cd /var/www/html

if [ ! -d vendor ]; then
  composer install --no-interaction --prefer-dist
fi

if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then
  echo "APP_KEY is empty; generating..."
  php artisan key:generate --force --no-interaction || true
fi

# Wait for Postgres if configured; воркеры (RUN_MIGRATIONS=0) стартуют после healthy app и миграции не гоняют
if [ -n "$DB_HOST" ] && [ "$DB_CONNECTION" = "pgsql" ] && [ "${RUN_MIGRATIONS:-1}" != "0" ]; then
  echo "Waiting for Postgres at ${DB_HOST}:${DB_PORT:-5432}..."
  i=0
  until php -r "try { new PDO('pgsql:host='.getenv('DB_HOST').';port='.(getenv('DB_PORT')?:'5432').';dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD')); exit(0);} catch (Throwable \$e) { exit(1); }" 2>/dev/null; do
    i=$((i + 1))
    if [ "$i" -ge 60 ]; then
      echo "Postgres is not ready after 60s"
      break
    fi
    sleep 1
  done
  php artisan migrate --force --no-interaction || true
fi

exec "$@"
