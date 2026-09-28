#!/bin/sh
set -eu
# Run from the host, requires only Docker Compose and a POSIX shell.
# BACKUP_DIR must be a protected directory OUTSIDE Docker's data volumes.
: "${BACKUP_DIR:?Set BACKUP_DIR to an off-volume backup directory}"
mkdir -p "$BACKUP_DIR"
stamp=$(date -u +%Y%m%dT%H%M%SZ)
docker compose exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$BACKUP_DIR/$stamp.dump"
docker compose exec -T app tar -C storage/app/private -czf - . > "$BACKUP_DIR/$stamp.uploads.tar.gz"
printf '%s\n' "Backup created: $stamp. Replicate encrypted off-host; protect APP_KEY separately."
