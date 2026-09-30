#!/usr/bin/env bash
# One-time setup of one TrackPro environment on the shared VPS (148.113.192.33).
# Lives beside Cargo Rush: its own folders, database, nginx vhost, ports and units.
#
#   provision.sh <production|staging> <api-host> <web-host> [api-branch] [web-branch]
#
# Run as root. Safe to re-run: existing .env files, database and vhost are kept.
set -euo pipefail

ENV_NAME="${1:?env: production or staging}"
API_HOST="${2:?api host, e.g. api.trackpro-gps.store}"
WEB_HOST="${3:?web host, e.g. app.trackpro-gps.store}"
API_BRANCH="${4:-main}"
WEB_BRANCH="${5:-master}"

case "$ENV_NAME" in
  production) API_PORT="${API_PORT:-3030}"; WEB_PORT="${WEB_PORT:-3031}" ;;
  staging)    API_PORT="${API_PORT:-3040}"; WEB_PORT="${WEB_PORT:-3041}" ;;
  *) echo "env must be production or staging" >&2; exit 1 ;;
esac

PHP_VERSION="${PHP_VERSION:-8.5}"
DEPLOY_USER="${DEPLOY_USER:-deploy}"
ROOT="${DEPLOY_ROOT:-/var/www/trackpro}/$ENV_NAME"
API_REPO="${API_REPO:-https://github.com/ygsorianochiukim/trackpro-api.git}"
WEB_REPO="${WEB_REPO:-https://github.com/glynn315/trackpro.git}"
EXTRA_ORIGINS="${EXTRA_ORIGINS:-}"
BIND_ADDR="${BIND_ADDR:-$(ip -4 -o addr show docker0 2>/dev/null | awk '{print $4}' | cut -d/ -f1)}"
PHP="php$PHP_VERSION"

log()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m!!  %s\033[0m\n' "$*" >&2; }
die()  { printf '\033[1;31mxx  %s\033[0m\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "run as root"
[[ -n "$BIND_ADDR" ]] || die "no docker0 address found; set BIND_ADDR"
command -v "$PHP" >/dev/null || die "$PHP not found (Cargo Rush's provision.sh installs it)"
systemctl list-unit-files "php$PHP_VERSION-fpm.service" >/dev/null || die "php$PHP_VERSION-fpm missing"
id "$DEPLOY_USER" >/dev/null 2>&1 || die "user $DEPLOY_USER missing"
command -v mysql >/dev/null || die "mysql client missing"

for port in "$API_PORT" "$WEB_PORT"; do
  if ss -ltnH "( sport = :$port )" | grep -q . && [[ ! -f "/etc/nginx/sites-available/trackpro-$ENV_NAME-api" ]]; then
    die "port $port is already in use on this box — pick another with API_PORT/WEB_PORT"
  fi
done

set_env() {
  local file=$1 key=$2 value=$3
  if grep -qE "^#?\s*$key=" "$file"; then
    sed -i -E "s|^#?\s*$key=.*|$key=$value|" "$file"
  else
    printf '%s=%s\n' "$key" "$value" >> "$file"
  fi
}

log "Tooling"
if ! command -v composer >/dev/null; then
  expected="$(curl -fsSL https://composer.github.io/installer.sig)"
  "$PHP" -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
  actual="$("$PHP" -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
  [[ "$expected" == "$actual" ]] || die "composer installer checksum mismatch"
  "$PHP" /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi
node_major="$(node -v 2>/dev/null | sed -E 's/^v([0-9]+).*/\1/')"
if [[ "${node_major:-0}" -lt 22 ]] || ! command -v npm >/dev/null; then
  echo "node ${node_major:-none}, npm $(command -v npm >/dev/null && echo present || echo missing) — installing Node 22 from NodeSource"
  curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
  apt-get install -y -qq nodejs
fi
command -v npm >/dev/null || die "npm still missing after installing Node — check apt output above"
echo "node $(node -v), npm $(npm -v)"
command -v git >/dev/null || apt-get install -y -qq git

log "Folders under $ROOT"
install -d -o "$DEPLOY_USER" -g www-data -m 2775 "$ROOT"
clone_repo() {
  local dir=$1 repo=$2 branch=$3
  if [[ ! -d "$ROOT/$dir/.git" ]]; then
    sudo -u "$DEPLOY_USER" git clone --branch "$branch" "$repo" "$ROOT/$dir"
  else
    warn "$ROOT/$dir already cloned — left as is"
  fi
}
clone_repo api "$API_REPO" "$API_BRANCH"
clone_repo web "$WEB_REPO" "$WEB_BRANCH"

log "Database"
DB_NAME="trackpro_$ENV_NAME"
DB_USER="trackpro_$ENV_NAME"
API_ENV="$ROOT/api/.env"
if [[ -f "$API_ENV" ]] && grep -q '^DB_PASSWORD=.\+' "$API_ENV"; then
  DB_PASS="$(grep '^DB_PASSWORD=' "$API_ENV" | cut -d= -f2-)"
else
  DB_PASS="$(openssl rand -hex 24)"
fi
if mysql -Nse "SHOW DATABASES LIKE '$DB_NAME'" | grep -q .; then
  warn "database $DB_NAME exists — left as is"
else
  mysql -e "CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';"
  mysql -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1'; FLUSH PRIVILEGES;"
fi

log "API .env"
ORIGINS="https://$WEB_HOST${EXTRA_ORIGINS:+,$EXTRA_ORIGINS}"
if [[ ! -f "$API_ENV" ]]; then
  sudo -u "$DEPLOY_USER" cp "$ROOT/api/.env.example" "$API_ENV"
  set_env "$API_ENV" APP_NAME TrackPro
  set_env "$API_ENV" APP_ENV "$ENV_NAME"
  set_env "$API_ENV" APP_DEBUG false
  set_env "$API_ENV" APP_URL "https://$API_HOST"
  set_env "$API_ENV" DB_CONNECTION mysql
  set_env "$API_ENV" DB_HOST 127.0.0.1
  set_env "$API_ENV" DB_PORT 3306
  set_env "$API_ENV" DB_DATABASE "$DB_NAME"
  set_env "$API_ENV" DB_USERNAME "$DB_USER"
  set_env "$API_ENV" DB_PASSWORD "$DB_PASS"
  set_env "$API_ENV" FRONTEND_URL "$ORIGINS"
  set_env "$API_ENV" SESSION_SECURE_COOKIE true
  set_env "$API_ENV" LOG_CHANNEL daily
  chmod 640 "$API_ENV"
  chown "$DEPLOY_USER:www-data" "$API_ENV"
  (cd "$ROOT/api" && sudo -u "$DEPLOY_USER" composer install --no-dev --optimize-autoloader --no-interaction -q)
  (cd "$ROOT/api" && sudo -u "$DEPLOY_USER" "$PHP" artisan key:generate --force)
else
  warn "$API_ENV exists — left as is"
fi

log "Web .env.local"
WEB_ENV="$ROOT/web/.env.local"
if [[ ! -f "$WEB_ENV" ]]; then
  sudo -u "$DEPLOY_USER" tee "$WEB_ENV" >/dev/null <<EOF
NEXT_PUBLIC_API_URL=https://$API_HOST
API_URL=https://$API_HOST
SERVER_ACTIONS_ALLOWED_ORIGINS=$WEB_HOST
NEXT_PUBLIC_WEB3FORMS_KEY=
EOF
  chmod 640 "$WEB_ENV"
else
  warn "$WEB_ENV exists — left as is"
fi

log "Permissions"
chown -R "$DEPLOY_USER:www-data" "$ROOT/api/storage" "$ROOT/api/bootstrap/cache"
chmod -R ug+rwX "$ROOT/api/storage" "$ROOT/api/bootstrap/cache"
find "$ROOT/api/storage" "$ROOT/api/bootstrap/cache" -type d -exec chmod g+s {} +

log "nginx vhost ($API_HOST -> $BIND_ADDR:$API_PORT)"
VHOST="/etc/nginx/sites-available/trackpro-$ENV_NAME-api"
if [[ ! -f "$VHOST" || "${FORCE_NGINX:-0}" == 1 ]]; then
  [[ -f "$VHOST" ]] && cp "$VHOST" "$VHOST.bak.$(date +%s)"
  cat > "$VHOST" <<EOF
server {
    listen $BIND_ADDR:$API_PORT;
    server_name $API_HOST;
    root $ROOT/api/public;
    index index.php;
    charset utf-8;
    client_max_body_size 20M;

    access_log /var/log/nginx/trackpro-$ENV_NAME-api-access.log;
    error_log  /var/log/nginx/trackpro-$ENV_NAME-api-error.log;

    add_header X-Content-Type-Options "nosniff" always;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php$PHP_VERSION-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 60;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
EOF
  ln -sf "$VHOST" "/etc/nginx/sites-enabled/trackpro-$ENV_NAME-api"
  nginx -t
  systemctl reload nginx
else
  warn "$VHOST exists — left as is (FORCE_NGINX=1 to regenerate)"
fi

log "systemd units"
cat > "/etc/systemd/system/trackpro-web-$ENV_NAME.service" <<EOF
[Unit]
Description=TrackPro web ($ENV_NAME)
After=network.target

[Service]
User=$DEPLOY_USER
WorkingDirectory=$ROOT/web
Environment=NODE_ENV=production
ExecStart=$ROOT/web/node_modules/.bin/next start -H $BIND_ADDR -p $WEB_PORT
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF

cat > "/etc/systemd/system/trackpro-scheduler-$ENV_NAME.service" <<EOF
[Unit]
Description=TrackPro scheduler ($ENV_NAME)

[Service]
Type=oneshot
User=$DEPLOY_USER
WorkingDirectory=$ROOT/api
ExecStart=/usr/bin/$PHP artisan schedule:run
EOF

cat > "/etc/systemd/system/trackpro-scheduler-$ENV_NAME.timer" <<EOF
[Unit]
Description=TrackPro scheduler every minute ($ENV_NAME)

[Timer]
OnCalendar=*-*-* *:*:00
AccuracySec=1s

[Install]
WantedBy=timers.target
EOF

systemctl daemon-reload
systemctl enable "trackpro-web-$ENV_NAME.service"
systemctl enable --now "trackpro-scheduler-$ENV_NAME.timer"

cat > "/etc/sudoers.d/trackpro-$ENV_NAME" <<EOF
$DEPLOY_USER ALL=(root) NOPASSWD: /usr/bin/systemctl restart trackpro-web-$ENV_NAME
$DEPLOY_USER ALL=(root) NOPASSWD: /usr/bin/systemctl reload php$PHP_VERSION-fpm
EOF
chmod 440 "/etc/sudoers.d/trackpro-$ENV_NAME"
visudo -cf "/etc/sudoers.d/trackpro-$ENV_NAME" >/dev/null

log "First deploy"
sudo -u "$DEPLOY_USER" PHP_VERSION="$PHP_VERSION" bash "$ROOT/api/deploy/deploy.sh" "$ENV_NAME"

cat <<EOF

Done. Add these to the Caddyfile (the Caddy container that owns :80/:443), then reload Caddy:

$API_HOST {
    reverse_proxy $BIND_ADDR:$API_PORT
}
$WEB_HOST {
    reverse_proxy $BIND_ADDR:$WEB_PORT
}

Still to fill in $API_ENV: MAIL_PASSWORD, PAYMONGO_*, ADMIN_EMAIL/ADMIN_PASSWORD,
then:  cd $ROOT/api && $PHP artisan config:cache
Seed an empty database once:  cd $ROOT/api && $PHP artisan db:seed --force
Web keys in $WEB_ENV (NEXT_PUBLIC_WEB3FORMS_KEY) need a redeploy to take effect.
EOF
