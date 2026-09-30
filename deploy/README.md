# TrackPro on the VPS (148.113.192.33)

TrackPro shares the box with Cargo Rush but nothing else: its own folders,
databases, nginx vhosts, ports and systemd units.

| Env | Folder | API | Site | Branches (api / web) |
|---|---|---|---|---|
| production | `/var/www/trackpro/production/{api,web}` | `api.trackpro-gps.store` → :3030 | `app.trackpro-gps.store` → :3031 | `main` / `master` |
| staging | `/var/www/trackpro/staging/{api,web}` | `staging-api.trackpro-gps.store` → :3040 | `staging.trackpro-gps.store` → :3041 | `main` / `master` |

Cargo Rush stays in `/var/www/cargo-rush` on ports 3010/3011/3020/3021.

## DNS first

- `api` has **two** A records. Delete the `37.44.245.85` one (old Hostinger),
  or half of all API requests go to the old server.
- Add `staging-api` → `148.113.192.33` (the staging site needs its own API host;
  Next.js already uses `/api/*` for its own routes, so they can't share one).

## First time, per environment (as root)

```bash
ssh root@148.113.192.33
git clone https://github.com/ygsorianochiukim/trackpro-api.git /tmp/trackpro-api

EXTRA_ORIGINS=https://trackpro-gps.store,https://www.trackpro-gps.store \
  bash /tmp/trackpro-api/deploy/provision.sh production \
  api.trackpro-gps.store app.trackpro-gps.store main master

bash /tmp/trackpro-api/deploy/provision.sh staging \
  staging-api.trackpro-gps.store staging.trackpro-gps.store main master
```

Staging runs the same branches as production, on its own database. To give it
its own branches later: `git -C /var/www/trackpro/staging/api switch -c staging origin/staging`
(same for `web`), then deploy.

Then add the printed blocks to the Caddyfile and reload Caddy. Caddy issues
the certificates.

Fill in `/var/www/trackpro/<env>/api/.env`: `MAIL_PASSWORD`, `PAYMONGO_*`,
`ADMIN_EMAIL`, `ADMIN_PASSWORD` — then `php8.5 artisan config:cache`.
Set `NEXT_PUBLIC_WEB3FORMS_KEY` in `web/.env.local` and redeploy.

### Production data

The live database is on Hostinger. Import it instead of seeding:

```bash
mysql trackpro_production < hostinger-dump.sql
```

Staging can be seeded: `cd /var/www/trackpro/staging/api && php8.5 artisan db:seed --force`.

## GitHub Actions

A push to `main` runs the tests, then deploys **staging**, then **production**
(`.github/workflows/deploy.yml`). **Actions → Deploy → Run workflow** redeploys
without a code change — pick `both`, `staging` or `production`. Use it after a
change to the website repo too: every deploy pulls both repos.

Repository secrets `SSH_PRIVATE_KEY` and `SSH_KNOWN_HOSTS` are already set
(the key logs in as `deploy` only).

### Overriding `.env` values

Add these under **Settings → Environments → `production` / `staging` → secrets**.
Each one overwrites that key on the server on the next deploy; empty ones are
ignored, and nothing else in the file changes (a `.env.bak` is kept).

| Secret | Goes to |
|---|---|
| `MAIL_MAILER` (`smtp`), `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | API `.env` |
| `PAYMONGO_SECRET_KEY`, `PAYMONGO_PUBLIC_KEY`, `PAYMONGO_WEBHOOK_SECRET` | API `.env` |
| `API_ENV_OVERRIDES` — any `KEY=VALUE` lines, one per line | API `.env` |
| `NEXT_PUBLIC_WEB3FORMS_KEY` | website `.env.local` |
| `WEB_ENV_OVERRIDES` — any `KEY=VALUE` lines | website `.env.local` |

Non-secret values can be environment **variables** instead of secrets
(`MAIL_HOST`, `MAIL_PORT`, `FRONTEND_URL`, `API_URL`, `NEXT_PUBLIC_API_URL`,
`SERVER_ACTIONS_ALLOWED_ORIGINS`, …). Values with spaces or symbols are quoted
for you. The log lists the key names that changed, never the values.

Gmail example (production environment): `MAIL_MAILER=smtp`, `MAIL_HOST=smtp.gmail.com`,
`MAIL_PORT=587`, `MAIL_USERNAME=you@gmail.com`, `MAIL_PASSWORD=<16-char app password>`,
`MAIL_FROM_ADDRESS=you@gmail.com`.

`APP_KEY`, `DB_PASSWORD` and `ADMIN_PASSWORD` never leave the server.

## Every deploy by hand (as `deploy`)

```bash
ssh deploy@148.113.192.33
bash /var/www/trackpro/production/api/deploy/deploy.sh production
bash /var/www/trackpro/staging/api/deploy/deploy.sh staging
```

Pulls the branch each checkout is on, runs `composer install`, migrations and
caches, rebuilds Next.js, restarts the site, and checks `/up`.

## After cutover

- PayMongo webhook → `https://api.trackpro-gps.store/api/paymongo/webhook`
- Vercel storefront (`@`): set `NEXT_PUBLIC_API_URL` / `API_URL` to
  `https://api.trackpro-gps.store` and redeploy.

## Logs

```bash
journalctl -u trackpro-web-production -f
tail -f /var/www/trackpro/production/api/storage/logs/laravel-*.log
tail -f /var/log/nginx/trackpro-production-api-error.log
systemctl list-timers trackpro-scheduler-production
```
