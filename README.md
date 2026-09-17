# Finopal Sales Organization — Backend

Laravel 12 API: five organizational roles, role-scoped wallets, gateway KYC review (senior manager + Finopal merchant code), Finopal transaction webhooks, commission engine, two-stage withdrawals, training, promotions, tree-authorized chat, FraSoft sync, and a separate system-manager admin API.

## Requirements

- PHP 8.2+
- Composer
- SQLite (default) or MySQL 8 / Redis via `docker-compose.yml`

## Setup

```bash
composer dump-autoload --no-scripts
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
php artisan geo:import
php artisan storage:link
php artisan serve --host=127.0.0.1 --port=8000
```

The React app proxies `/api` to this host/port. If artisan is not listening on `8000`, login shows an offline/server error — not a wrong password.

Chat realtime (keep this terminal open). If it is stopped, the UI falls back to HTTP:

```bash
npm install ws
node realtime/ws-server.mjs
```

## Artisan commands

| Command | Purpose |
|---|---|
| `php artisan serve --host=127.0.0.1 --port=8000` | API server |
| `php artisan migrate` | Apply new migrations |
| `php artisan migrate:fresh --seed` | Wipe DB, seed demo users/sales, import geo (uses MySQL from `.env`) |
| `php artisan db:seed --class=DemoReviewSeeder` | Extra review data (promotions, wallets, gateway queues) without wiping |
| `php artisan finopal:seed-webhook-demo` | Import/update Finopal webhook test gateways (does **not** wipe users; by default clears txs/commissions/wallets for those merchants) |
| `php artisan finopal:seed-webhook-demo --with-transaction` | Same + one sample transaction and commission split |
| `php artisan finopal:seed-webhook-demo --skip-reset` | Keep existing txs/commissions for demo merchants |
| `php artisan finopal:seed-empty-org` | Rebuild empty org: only senior (holds SM/DM/senior), no representatives |
| `php artisan finopal:seed-empty-org --purge --force` | Wipe all users except superuser, then seed only senior |
| `php artisan finopal:seed-full-demo` | Re-import full test org (users, tree, gateways, txs, DemoReview) |
| `php artisan finopal:seed-full-demo --purge` | Purge non-superusers, then re-import full test org (recommended reset) |
| `php artisan demo:shared-commissions` | Demo gateways/txs for shared referral + A/B shared sale |
| `php artisan geo:import` | Import/normalize Iranian provinces & cities from `database/data/iran-geo.json` |
| `php artisan geo:import --from-shop-maker` | Pull `states`/`cities` from MySQL `shop_maker` and rewrite the JSON |
| `php artisan geo:import --path=FILE` | Import a specific JSON file |
| `php artisan storage:link` | Public disk for KYC and chat files |
| `php artisan test` | PHPUnit |

Shop-maker connection (`geo:import --from-shop-maker`):

```
SHOP_MAKER_DB_HOST=127.0.0.1
SHOP_MAKER_DB_PORT=3306
SHOP_MAKER_DB_DATABASE=shop_maker
SHOP_MAKER_DB_USERNAME=root
SHOP_MAKER_DB_PASSWORD=
```

On a server, run `migrate`, `geo:import`, and `storage:link` after deploy. `geo:import` folds Arabic presentation forms so searches like «قزوین» match.

## Gateway review and Finopal commissions

KYC submit → `pending_inspection` (senior manager, downline only). Approve requires the Finopal `merchant_id` stored as `gateways.merchant_code` and moves the sale to `successful` with **no commissions**. Each later verified payment is posted to `POST /api/webhooks/finopal/transaction` (`X-Finopal-Webhook-Secret` = `FINOPAL_WEBHOOK_SECRET`). Commission base is `profit` (gateway profit on that payment), split by existing role/referrer/tree percents, floored to 3 decimals. FraSoft inbound sales stay `successful` immediately but still wait for a Finopal webhook before commissions.

Set `FINOPAL_WEBHOOK_SECRET` in `.env`. Full payload for Finopal developers is in the root `README.md`.

## Demo accounts

Password for all users: `Password123!`

| Mobile | Roles |
|---|---|
| 09120000000 | System manager |
| 09121111111 | Senior Manager + lower roles |
| 09122222222 | Development Manager |
| 09123333333 | Sales Manager |
| 09124444444 | Representative Referrer |
| 09125555555 | Representative |
| 09126666666 | Multi-role |
| 09127777777 / 09128888888 | Shared 50/50 representatives |
