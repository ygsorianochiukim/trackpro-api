#!/usr/bin/env bash
# Pull, build and restart one TrackPro environment. Run as the deploy user:
#   bash /var/www/trackpro/<env>/api/deploy/deploy.sh <production|staging>
set -euo pipefail

ENV_NAME="${1:?env: production or staging}"
PHP_VERSION="${PHP_VERSION:-8.5}"
ROOT="${DEPLOY_ROOT:-/var/www/trackpro}/$ENV_NAME"
PHP="php$PHP_VERSION"

log() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }

update() {
  local dir=$1 branch
  branch="$(git -C "$dir" rev-parse --abbrev-ref HEAD)"
  git -C "$dir" fetch --prune origin "$branch"
  git -C "$dir" reset --hard "origin/$branch"
  echo "$(basename "$dir"): $branch @ $(git -C "$dir" rev-parse --short HEAD)"
}

log "API"
update "$ROOT/api"
cd "$ROOT/api"
composer install --no-dev --optimize-autoloader --no-interaction -q
"$PHP" artisan migrate --force --no-interaction
"$PHP" artisan optimize:clear -q
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache
"$PHP" artisan event:cache
sudo /usr/bin/systemctl reload "php$PHP_VERSION-fpm"

log "Web"
update "$ROOT/web"
cd "$ROOT/web"
npm ci --no-audit --no-fund
npm run build
sudo /usr/bin/systemctl restart "trackpro-web-$ENV_NAME"

log "Smoke test"
api_url="$(grep '^APP_URL=' "$ROOT/api/.env" | cut -d= -f2-)"
sleep 3
curl -fsS -o /dev/null -w "API  $api_url/up -> %{http_code}\n" "$api_url/up" \
  || echo "API not reachable over HTTPS yet (Caddy/DNS?)"
systemctl is-active --quiet "trackpro-web-$ENV_NAME" && echo "web service running" \
  || { echo "web service failed:"; journalctl -u "trackpro-web-$ENV_NAME" -n 20 --no-pager; exit 1; }
