#!/usr/bin/env bash
# One-time workspace setup: dependencies, .env, database with demo data and the asset build.
set -euo pipefail
cd "$(dirname "$0")/.."

rm -f .idx/.setup-done

bash .idx/postgres.sh

if [ ! -f .env ]; then
    cp .env.example .env
fi

set_env() {
    if grep -qE "^#? ?$1=" .env; then
        sed -i -E "s|^#? ?$1=.*|$1=$2|" .env
    else
        echo "$1=$2" >> .env
    fi
}

set_env APP_ENV local
set_env DB_HOST 127.0.0.1
set_env DB_PORT 5432
set_env DB_DATABASE fidelity
set_env DB_USERNAME postgres
set_env DB_PASSWORD ""
set_env ADMIN_EMAIL admin@demo.test
set_env ADMIN_PASSWORD password
# The preview is served over HTTPS through Google's proxy, inside an iframe.
set_env TRUSTED_PROXIES "*"
set_env SESSION_SECURE_COOKIE true
set_env SESSION_SAME_SITE none

composer install --no-interaction --prefer-dist

if ! grep -qE "^APP_KEY=.+" .env; then
    php artisan key:generate --force
fi

php artisan migrate:fresh --seed --force
php artisan storage:link || true

npm ci
rm -f public/hot
npm run build

touch .idx/.setup-done
echo "Setup concluído. Login: admin@demo.test / password"
