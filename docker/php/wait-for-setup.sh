#!/bin/sh
# Used by the queue and scheduler services: wait until setup.php has installed vendor/,
# written .env and run the migrations (migrate:status only succeeds then), then exec the
# given command. Avoids crash-looping on a fresh clone.
until php artisan migrate:status --no-interaction >/dev/null 2>&1; do
  echo "waiting for setup: run  docker compose exec app php setup.php"
  sleep 10
done
exec "$@"
