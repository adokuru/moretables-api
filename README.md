# MoreTables API

Backend API for **MoreTables** — restaurant discovery, reservations, waitlists, and merchant tooling. Built on **Laravel 12** with **Laravel Sanctum** for authentication.

**Base URL:** `/api/v1`

---

## Features

| Area | What it covers |
|------|----------------|
| **Public** | Restaurant listing, detail, slot availability; onboarding requests |
| **Auth** | Customer OTP / Google / Apple; staff login + 2FA; password reset; profile |
| **Customer** (Sanctum) | Book & manage reservations, join waitlist, accept/decline table offers, Expo push tokens |
| **Merchant** (Sanctum + roles) | Floor plan (dining areas, tables), reservations (walk-in, assign, seat, complete), waitlist & notify, menu & media |
| **Admin** | Organizations, restaurants, users & roles, audit |

Real-time updates use **Laravel Reverb** / broadcasting where configured (e.g. waitlist).

---

## Documentation

| Doc | Description |
|-----|-------------|
| **OpenAPI UI (Scramble)** | `{APP_URL}/docs/api` — interactive API reference (see `config/scramble.php`). |
| **[Notifications](docs/NOTIFICATIONS.md)** | Every **email** and **Expo push** notification, when they fire, and code locations. |

---

## Requirements

- PHP **8.2+**
- Composer
- Database (SQLite for local dev; MySQL/PostgreSQL in production)
- **Queue worker** recommended (`QUEUE_CONNECTION=database`) — many notifications are queued

---

## Quick start

```bash
cp .env.example .env
php artisan key:generate
composer install
php artisan migrate
# Optional: seed roles/permissions if your flow needs them
php artisan db:seed --class=RoleAndPermissionSeeder

php artisan serve
```

For full local setup (including frontend assets if you use them), see `composer run-script setup` in `composer.json`.

Run a queue worker so mail/push jobs are processed:

```bash
php artisan queue:work
```

---

## Testing

```bash
php artisan test
# Single file
php artisan test tests/Feature/Feature/Merchant/MerchantOperationsTest.php
```

Tests use **Pest**.

---

## Code style

```bash
vendor/bin/pint --dirty
```

---

## Stack (high level)

- **Laravel 12**, **Sanctum**, **Pest**
- **dedoc/scramble** — OpenAPI docs
- **spatie/laravel-medialibrary** — restaurant & menu images
- **Laravel Reverb** / **Pulse** — as configured in the project

---

## License

MIT.
