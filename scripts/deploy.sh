#!/usr/bin/env bash
# C+CLub deployment, executed on the VDS by GitHub Actions:
#   ssh root@VDS "bash -s -- <staging|production> <commit-sha>" < scripts/deploy.sh
#
# staging    — isolated stack in /root/cplusclub-staging (own DB/Redis, demo data, fake Telegram),
#              served at https://staging.cplusclub.tech through the production Caddy.
# production — fast-forwards /root/cplusclub to the commit after a database + uploads backup.
#              The private compose.override.yaml and .env on the VDS are never overwritten.
set -Eeuo pipefail

TARGET="${1:?usage: deploy.sh <staging|production> <sha>}"
SHA="${2:?usage: deploy.sh <staging|production> <sha>}"
[[ "$SHA" =~ ^[0-9a-f]{40}$ ]] || { echo "Invalid commit SHA" >&2; exit 2; }

REPO_URL="https://github.com/ngixsystem/cplusclub.git"
EDGE_NETWORK="cclub-edge"   # shared only by Caddy and the staging web server
log() { printf '\n==> %s\n' "$*"; }

ensure_edge_network() {
  docker network inspect "$EDGE_NETWORK" >/dev/null 2>&1 || docker network create "$EDGE_NETWORK" >/dev/null
  # Caddy lives in the production project; attach it to the edge network so it can reach staging.
  local caddy
  caddy="$(docker ps --filter 'label=com.docker.compose.project=cclub' --filter 'label=com.docker.compose.service=caddy' --format '{{.Names}}' | head -n1)"
  if [[ -n "$caddy" ]] && ! docker inspect -f '{{json .NetworkSettings.Networks}}' "$caddy" | grep -q "\"$EDGE_NETWORK\""; then
    docker network connect "$EDGE_NETWORK" "$caddy"
  fi
}

reload_caddy() {
  local caddy
  caddy="$(docker ps --filter 'label=com.docker.compose.project=cclub' --filter 'label=com.docker.compose.service=caddy' --format '{{.Names}}' | head -n1)"
  [[ -n "$caddy" ]] && docker exec "$caddy" caddy reload --config /etc/caddy/Caddyfile || true
}

deploy_staging() {
  local dir=/root/cplusclub-staging
  local dc=(docker compose -p cclub-staging -f compose.staging.yaml)

  log "Checkout $SHA into $dir"
  if [[ ! -d "$dir/.git" ]]; then git clone --quiet "$REPO_URL" "$dir"; fi
  cd "$dir"
  git fetch --quiet origin "$SHA"
  git checkout --quiet --force --detach "$SHA"
  git clean -fdq -e .env -e vendor -e node_modules -e storage

  if [[ ! -f .env ]]; then
    log "First staging run: creating .env with random secrets"
    cp .env.example .env
    sed -i \
      -e "s|^APP_ENV=.*|APP_ENV=staging|" \
      -e "s|^APP_URL=.*|APP_URL=https://staging.cplusclub.tech|" \
      -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$(openssl rand -hex 24)|" \
      -e "s|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=true|" \
      -e "s|^TELEGRAM_TRANSPORT=.*|TELEGRAM_TRANSPORT=fake|" .env
    printf 'STAGING_DEMO_PASSWORD=%s\n' "$(openssl rand -base64 18 | tr -d '/+=')" >> .env
    chmod 600 .env
  fi
  chown -R 33:33 "$dir"

  ensure_edge_network
  log "Starting database and cache"
  "${dc[@]}" up -d --wait postgres redis

  log "Installing dependencies and building assets"
  "${dc[@]}" run --rm --no-deps app composer install --no-interaction --prefer-dist
  grep -q '^APP_KEY=base64:' .env || "${dc[@]}" run --rm --no-deps app php artisan key:generate --force
  "${dc[@]}" run --rm --no-deps node sh -c 'npm ci --no-audit --no-fund && npm run build'
  chown -R 33:33 "$dir"

  log "Migrating and seeding demo data"
  "${dc[@]}" run --rm app php artisan migrate --force
  "${dc[@]}" run --rm app php artisan db:seed --class=StagingSeeder --force
  "${dc[@]}" run --rm app php artisan optimize:clear

  log "Starting web stack"
  "${dc[@]}" up -d --wait app web
  reload_caddy
  "${dc[@]}" exec -T web curl -fsS http://localhost/health/ready
  log "Staging is live at https://staging.cplusclub.tech ($SHA)"
}

deploy_production() {
  local dir=/root/cplusclub
  cd "$dir"
  local dc=(docker compose)

  log "Fetching $SHA"
  git fetch --quiet origin main
  git merge-base --is-ancestor HEAD "$SHA" || { echo "Refusing non fast-forward deploy" >&2; exit 3; }

  log "Backup before deployment"
  BACKUP_DIR=/root/backups/cclub sh scripts/backup.sh
  ls -1t /root/backups/cclub/*.dump 2>/dev/null | tail -n +15 | while read -r old; do
    rm -f "$old" "${old%.dump}.uploads.tar.gz"
  done

  "${dc[@]}" exec -T app php artisan down --retry=30
  trap '"${dc[@]}" exec -T app php artisan up || true' EXIT

  log "Updating code"
  git merge --ff-only --quiet "$SHA"

  log "Installing dependencies and building assets"
  "${dc[@]}" run --rm --no-deps app composer install --no-interaction --prefer-dist
  "${dc[@]}" run --rm --no-deps node sh -c 'npm ci --no-audit --no-fund && npm run build'

  log "Migrating"
  "${dc[@]}" exec -T app php artisan migrate --force
  "${dc[@]}" exec -T app php artisan optimize:clear
  "${dc[@]}" exec -T app php artisan horizon:terminate || true

  ensure_edge_network
  reload_caddy
  "${dc[@]}" exec -T app php artisan up
  trap - EXIT

  log "Health check"
  for attempt in 1 2 3 4 5 6; do
    curl -fsS http://127.0.0.1:8080/health/ready && break
    [[ $attempt -eq 6 ]] && { echo "Health check failed" >&2; exit 4; }
    sleep 5
  done
  log "Production updated to $SHA"
}

case "$TARGET" in
  staging) deploy_staging ;;
  production) deploy_production ;;
  *) echo "Unknown target: $TARGET" >&2; exit 2 ;;
esac
