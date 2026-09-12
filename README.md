# Finopal Sales Organization — Backend

Laravel 12 API for the Finopal sales organization: five organizational roles, role-scoped wallets, historical gateway attribution, commission engine, two-stage withdrawals, training, promotions, tree-authorized chat, FraSoft sync, and a separate system-manager admin API.

## Requirements

- PHP 8.2+
- Composer
- SQLite (default) or MySQL 8 / Redis via `docker-compose.yml`

## Setup

```bash
composer dump-autoload --no-scripts
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve --host=127.0.0.1 --port=8000
```

Optional realtime:

```bash
npm install ws
node realtime/ws-server.mjs
```

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

## Tests

```bash
php artisan test
```
