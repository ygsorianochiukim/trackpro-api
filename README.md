# TrackPro API (Laravel)

Backend for the [TrackPro GPS](../trackpro) Next.js frontend.
Provides:

- **Public REST API** consumed by the Next.js storefront (products, customer auth, orders, payments)
- **Admin dashboard** at `/admin` for managing inventory, orders, and payments
- **PayMongo integration** — payment links + webhook receiver for GCash, GrabPay, PayMaya, and cards

---

## Stack

| Layer | Tech |
| --- | --- |
| Framework | Laravel 13 (PHP 8.4) |
| Auth | Laravel session (admin) + Sanctum tokens (customers) |
| Database | SQLite for dev (default) · MySQL/Postgres for production (one `.env` change) |
| Frontend (admin) | Server-rendered Blade + Tailwind CDN |
| Payments | PayMongo Links API |

---

## Quick start (5 minutes)

```bash
# 1. Install PHP dependencies (only first time)
composer install

# 2. Copy env and generate the app key (only first time)
cp .env.example .env
php artisan key:generate

# 3. Migrate + seed (creates 9 products and the admin user)
php artisan migrate --seed

# 4. Run the dev server
php artisan serve --host=127.0.0.1 --port=8000
```

Then open:

- API base: <http://127.0.0.1:8000/api/products>
- Admin panel: <http://127.0.0.1:8000/admin/login>

**Default admin login** (change immediately):

- Email: `admin@trackprogps.com`
- Password: `trackpro-change-me`

Rotate the admin password:

```bash
php artisan tinker
> User::where('email', 'admin@trackprogps.com')->first()->update(['password' => 'your-new-password']);
```

---

## API endpoints

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/api/products` | none | List active products |
| GET | `/api/products/{slug}` | none | Single product by slug |
| POST | `/api/customer/register` | none | Create customer; returns Sanctum token |
| POST | `/api/customer/login` | none | Email + password; returns Sanctum token |
| GET | `/api/customer/me` | Bearer | Current customer profile |
| POST | `/api/customer/logout` | Bearer | Revoke current token |
| GET | `/api/customer/orders` | Bearer | Customer's order history |
| POST | `/api/orders` | optional Bearer | Place an order; returns PayMongo `checkout_url` if configured |
| GET | `/api/orders/{reference}` | none | Look up order by reference (e.g. `TP-000001`) |
| POST | `/api/paymongo/webhook` | PayMongo signature | Receives `link.payment.paid`, `payment.failed`, etc. |

### Frontend usage example

```ts
// 1. Register customer → get token
const r = await fetch('http://127.0.0.1:8000/api/customer/register', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
  body: JSON.stringify({ name, email, phone, password }),
});
const { customer, token } = await r.json();

// 2. Place order with token
const order = await fetch('http://127.0.0.1:8000/api/orders', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    'Authorization': `Bearer ${token}`,
  },
  body: JSON.stringify({
    items: [{ slug: 'trackpro-premium-anti-jammer', qty: 1 }],
    customer: { name, email, phone, address, notes },
  }),
}).then(r => r.json());

// 3. Redirect to PayMongo checkout
if (order.checkout_url) window.location.href = order.checkout_url;
```

---

## PayMongo setup

1. Sign up at [paymongo.com](https://paymongo.com) and complete business KYC.
2. Dashboard → **Developers → API keys** → copy both keys (start with test keys).
3. Paste them into `.env`:
   ```env
   PAYMONGO_SECRET_KEY=sk_test_xxxxxxxxxxxx
   PAYMONGO_PUBLIC_KEY=pk_test_xxxxxxxxxxxx
   ```
4. Dashboard → **Developers → Webhooks** → add webhook:
   - URL: `https://your-api-domain.com/api/paymongo/webhook`
   - Events: `link.payment.paid`, `payment.failed`, `payment.refunded`
   - Copy the webhook secret into `.env` as `PAYMONGO_WEBHOOK_SECRET`.
5. For local webhook testing use [ngrok](https://ngrok.com) (`ngrok http 8000`) and use the HTTPS URL.

Live deployment: swap to `sk_live_...` keys and update the webhook URL.

---

## Switch SQLite → MySQL

Edit `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=trackpro
DB_USERNAME=root
DB_PASSWORD=
```

```sql
CREATE DATABASE trackpro CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
php artisan migrate:fresh --seed
```

---

## Admin dashboard tour

| Section | What you can do |
| --- | --- |
| **Dashboard** | Total orders, revenue today + all-time, inventory health, recent orders, low-stock alerts |
| **Orders** | Filter by status/payment, view items + customer + payment history, update order status |
| **Products / Stock** | Inline `+ / −` stock adjustments; Edit page for price, subscription, stock, featured flag, visibility |
| **Payments** | Audit trail of every payment (PayMongo + manual). Filter by status/provider |

From any order detail page you can:

- Update status (`pending → paid → preparing → shipped → completed`)
- Create a PayMongo payment link on demand
- Mark as paid manually (for direct bank transfer / GCash)

---

## Deployment

Recommended hosts:

- **Laravel Forge + DigitalOcean** droplet ($6/mo) — easiest, auto SSL, zero-downtime deploys
- **Hostinger Business** (~₱200/mo) — Filipino-hosted, supports Laravel via SSH/composer
- **fly.io** or **Railway** — modern PaaS, ~$5/mo

### Production checklist

```bash
APP_ENV=production
APP_DEBUG=false

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
php artisan migrate --force
```

### Lock down CORS

Edit `config/cors.php`:

```php
'allowed_origins' => ['https://trackprogps.com'],
```

---

## Project structure

```
app/
├── Http/Controllers/
│   ├── Api/                Public REST API for the Next.js storefront
│   └── Admin/              Server-rendered admin pages
├── Models/                 Product, Customer, Order, OrderItem, Payment
└── Services/
    └── PayMongoService.php Wrapper around PayMongo Links API + webhook signature check
database/
├── migrations/             products, customers, orders, order_items, payments
└── seeders/
    ├── ProductSeeder.php   Edit to add/change catalog items
    └── AdminUserSeeder.php Default admin login
resources/views/admin/      Blade templates for the dashboard
routes/
├── api.php                 /api/* routes
└── web.php                 /admin/* routes
```

To add or edit a product: open `database/seeders/ProductSeeder.php`, then `php artisan db:seed --class=ProductSeeder`. It uses `updateOrCreate` so it's safe to re-run.
