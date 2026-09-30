#!/usr/bin/env bash
# Pull, build and restart one TrackPro environment. Run as the deploy user:
#   bash /var/www/trackpro/<env>/api/deploy/deploy.sh <production|staging>
set -euo pipefail

ENV_NAME="${1:?env: production or staging}"
PHP_VERSION="${PHP_VERSION:-8.5}"
ROOT="${DEPLOY_ROOT:-/var/www/trackpro}/$ENV_NAME"
PHP="php$PHP_VERSION"

log() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }

# Merge KEY=VALUE lines from $1 into env file $2: replace existing keys in
# place, append new ones, leave every other line alone. Logs names only.
apply_overrides() {
  local src=$1 dest=$2 tmp
  [[ -n "$src" && -s "$src" ]] || return 0
  tmp="$(mktemp "$dest.XXXXXX")"
  awk '
    function key(line) { return match(line, /^[A-Za-z_][A-Za-z0-9_]*=/) ? substr(line, 1, RLENGTH - 1) : "" }
    NR == FNR { k = key($0); if (k != "") { if (!(k in val)) order[++n] = k; val[k] = $0 }; next }
    { k = key($0) }
    k != "" && (k in val) { if (!(k in done)) { print val[k]; done[k] = 1 }; next }
    { print }
    END { for (i = 1; i <= n; i++) if (!(order[i] in done)) print val[order[i]] }
  ' "$src" "$dest" > "$tmp"
  if cmp -s "$tmp" "$dest"; then
    echo "$(basename "$dest") already up to date"
  else
    diff "$dest" "$tmp" | sed -nE 's/^> ([A-Za-z_][A-Za-z0-9_]*)=.*/  changed \1/p' | sort -u || true
    cp -p "$dest" "$dest.bak"
    cat "$tmp" > "$dest"
  fi
  rm -f "$tmp"
}

if [[ -n "${API_OVERRIDES:-}${WEB_OVERRIDES:-}" ]]; then
  log "Applying .env overrides from GitHub"
  apply_overrides "${API_OVERRIDES:-}" "$ROOT/api/.env"
  apply_overrides "${WEB_OVERRIDES:-}" "$ROOT/web/.env.local"
fi

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
