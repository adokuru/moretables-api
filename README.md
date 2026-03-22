# MoreTables API

Backend API for **MoreTables** — restaurant discovery, reservations, waitlists, and merchant tooling. Built on **Laravel 12** with **Laravel Sanctum** for authentication.

**Base URL:** `/api/v1`

---

## Features

| Area | What it covers |
|------|----------------|
| **Public** | Restaurant listing, detail, slot availability, discovery rails, view tracking, public reviews, onboarding requests |
| **Auth** | Customer OTP / Google / Apple; staff login + 2FA; password reset; profile |
| **Customer** (Sanctum) | Book & manage reservations, join waitlist, accept/decline table offers, Expo push tokens, save restaurants, create restaurant lists, write reviews, track loyalty points and reward tiers |
| **Merchant** (Sanctum + roles) | Floor plan (dining areas, tables), reservations (walk-in, assign, seat, complete), waitlist & notify, menu & media |
| **Admin** | Organizations, business onboarding, restaurants, reward program management, users & roles, audit |
| **Local testing** | Faker data generator for organizations, restaurants, owners, staff, customers, and featured restaurants |

Real-time updates use **Laravel Reverb** / broadcasting where configured (e.g. waitlist).

---

## Documentation

| Doc | Description |
|-----|-------------|
| **OpenAPI UI (Scramble)** | `{APP_URL}/docs/api` — interactive API reference (see `config/scramble.php`). |
| **[Postman collection](docs/MoreTables-API.postman_collection.json)** | Checked-in v1 request collection covering auth, public, customer, merchant, and admin flows. |
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

Generate realistic local data for discovery, reservations, and admin demos:

```bash
php artisan app:generate-testing-data
# Example
php artisan app:generate-testing-data --organizations=5 --restaurants-per-organization=3 --staff-per-restaurant=4 --featured=6
```

---

## API highlights

- `POST /api/v1/admin/organizations/onboard` creates an organization, owner account, and one or more restaurants from the admin onboarding flow.
- `GET /api/v1/restaurants/discovery` returns the mobile/web homepage rails: `top_booked`, `top_viewed`, `top_saved`, `highly_rated`, `new_on_moretables`, and `featured`.
- `GET /api/v1/restaurants/discovery/{section}` paginates any single discovery section for "See all" screens.
- `POST /api/v1/restaurants/{restaurant}/views` records restaurant views that feed discovery ranking.
- `POST /api/v1/restaurants/{restaurant}/save` and `GET /api/v1/me/saved-restaurants` manage direct customer saves.
- `GET /api/v1/me/restaurant-lists` plus the related create/update/add/remove routes manage customer-curated restaurant lists.
- `GET /api/v1/restaurants/{restaurant}/reviews` and the authenticated review create/update/delete routes power public ratings and review summaries.
- `GET /api/v1/me/rewards/status` and `GET /api/v1/me/rewards/transactions` expose lifetime loyalty points, current tier, and reward history for the authenticated customer.
- `GET /api/v1/admin/reward-program`, `PATCH /api/v1/admin/reward-program`, and `POST /api/v1/admin/users/{user}/reward-points` manage the lifetime loyalty program and point grants.
- Restaurants support the `is_featured` flag across admin creation, onboarding, merchant updates, and public responses.

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
