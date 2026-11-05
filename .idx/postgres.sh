#!/usr/bin/env bash
# Starts a local PostgreSQL (trust auth on 127.0.0.1:5432) and ensures the app database exists.
set -euo pipefail

PGDATA="${HOME}/.local/share/fidelity-pgdata"
DB_NAME="fidelity"

if [ ! -f "${PGDATA}/PG_VERSION" ]; then
    mkdir -p "${PGDATA}"
    initdb -D "${PGDATA}" -U postgres --auth=trust --encoding=UTF8 --no-locale >/dev/null
fi

if ! pg_ctl -D "${PGDATA}" status >/dev/null 2>&1; then
    pg_ctl -D "${PGDATA}" -l "${PGDATA}/server.log" -w \
        -o "-c listen_addresses=127.0.0.1 -c port=5432 -k ${PGDATA}" start
fi

if ! psql -h 127.0.0.1 -U postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '${DB_NAME}'" | grep -q 1; then
    createdb -h 127.0.0.1 -U postgres "${DB_NAME}"
fi
