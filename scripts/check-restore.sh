#!/bin/sh
set -eu
: "${1:?Pass the PostgreSQL custom-format dump}"
target="cclub_restore_$(date -u +%Y%m%d%H%M%S)"
docker compose exec -T postgres sh -c 'createdb -U "$POSTGRES_USER" "$1"' sh "$target"
docker compose exec -T postgres sh -c 'pg_restore -U "$POSTGRES_USER" -d "$1" --exit-on-error' sh "$target" < "$1"
docker compose exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d "$1" -c "SELECT count(*) AS migrations FROM migrations; SELECT count(*) AS equipment FROM equipment;"' sh "$target"
printf '%s\n' "Restored into separate database: $target. Inspect then remove manually."
