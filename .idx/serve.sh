#!/usr/bin/env bash
# Preview command: waits for the one-time setup, then starts Postgres, the queue worker and the app server.
set -euo pipefail
cd "$(dirname "$0")/.."

PORT="${1:-8000}"

until [ -f .idx/.setup-done ]; do
    echo "Aguardando o setup do workspace (.idx/setup.sh)..."
    sleep 5
done

bash .idx/postgres.sh

php artisan queue:work --tries=3 >storage/logs/queue.log 2>&1 &
php artisan schedule:work >storage/logs/schedule.log 2>&1 &
trap 'kill $(jobs -p) 2>/dev/null || true' EXIT

php artisan serve --host=0.0.0.0 --port="${PORT}"
