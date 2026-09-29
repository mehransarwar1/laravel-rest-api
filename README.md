# Laravel REST API

[![Tests](https://github.com/mehransarwar1/laravel-rest-api/actions/workflows/tests.yml/badge.svg)](https://github.com/mehransarwar1/laravel-rest-api/actions/workflows/tests.yml)

A Laravel 12 REST API portfolio project demonstrating authentication, catalog management, transactional order processing, and inventory handling.

This is not a full e-commerce platform. It does not process payments, manage carts, or implement admin roles.

## Project Overview

The API is versioned under `/api/v1` and uses Laravel Sanctum bearer tokens. Catalog reads are public. Catalog writes, profile access, and all order operations require authentication.

The project focuses on server-side integrity: prices and totals are never taken from the client, stock changes run inside database transactions, and order items store historical snapshots.

## Key Features

**Authentication**
- Register, login, logout, and current-user (`/me`)
- Sanctum personal access tokens
- Login and registration rate limiting
- Generic invalid-credential responses

**Catalog**
- Categories, products, and product variants
- Pagination, search, filtering, and whitelist-based sorting
- Soft deletes for categories and products
- Unique slugs and SKUs

**Orders**
- Authenticated order creation and listing
- Server-side pricing and integer-cent money math
- Order item snapshots (name and unit price)
- Inventory decrement inside a transaction
- Cancellation with one-time stock restoration
- Duplicate line-item normalization

**Engineering**
- Form Requests, API Resources, and Policies
- `OrderService` for order create/cancel
- `OrderStatus` enum
- PHPUnit feature and unit tests
- GitHub Actions CI (PHPUnit, Pint, Composer audit)

## Technical Stack

| Technology | Usage |
|---|---|
| PHP 8.2+ | Runtime |
| Laravel 12 | API framework |
| Laravel Sanctum | Bearer-token authentication |
| SQLite | Default local database and CI/tests |
| MySQL / PostgreSQL | Recommended for production (row-level locking) |
| PHPUnit 11 | Automated tests |
| Laravel Pint | Code style |
| GitHub Actions | CI |

Local and CI use SQLite. Tests use an in-memory SQLite database (`phpunit.xml`). Production should use MySQL or PostgreSQL.

## Architecture

Request flow:

```text
HTTP request
→ routes/api.php
→ middleware (throttle, auth:sanctum)
→ Form Request
→ Policy
→ Controller
→ OrderService (orders only)
→ Eloquent / database
→ API Resource
→ JSON response
```

- Validation lives in Form Requests.
- Authorization lives in Policies (orders are also owner-scoped at route binding).
- Order create/cancel logic lives in `App\Services\OrderService`.
- Money arithmetic lives in `App\Support\Money` (integer cents).
- Responses are shaped by API Resources.

## Authentication

| Method | Endpoint | Auth |
|---|---|---|
| POST | `/api/v1/auth/register` | Public, throttled |
| POST | `/api/v1/auth/login` | Public, throttled |
| POST | `/api/v1/auth/logout` | Sanctum |
| GET | `/api/v1/me` | Sanctum |

Send the token as `Authorization: Bearer {token}`.

Logout deletes only the current access token. Failed login always returns `Invalid credentials.` (HTTP 401) without revealing whether the email exists.

Rate limits: 5 login attempts per minute per IP+email; 5 registrations per minute per IP; 30 authenticated writes per minute per user.

## API Endpoints

### Categories

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/categories` | Public | List categories |
| GET | `/api/v1/categories/{category}` | Public | Show category |
| POST | `/api/v1/categories` | Sanctum | Create category |
| PUT / PATCH | `/api/v1/categories/{category}` | Sanctum | Update category |
| DELETE | `/api/v1/categories/{category}` | Sanctum | Soft-delete category |

Query parameters (list): `search`, `is_active`, `sort` (`name`, `slug`, `created_at`, `updated_at`; prefix `-` for desc), `per_page` (1–100, default 15), `page`.

Default sort: `created_at` descending. Categories with products cannot be deleted (HTTP 409).

### Products

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/products` | Public | List products |
| GET | `/api/v1/products/{product}` | Public | Show product |
| POST | `/api/v1/products` | Sanctum | Create product |
| PUT / PATCH | `/api/v1/products/{product}` | Sanctum | Update product |
| DELETE | `/api/v1/products/{product}` | Sanctum | Soft-delete product |

Query parameters (list): `search`, `category_id`, `is_active`, `min_price`, `max_price`, `in_stock`, `sort` (`name`, `price`, `stock`, `created_at`, `updated_at`), `per_page`, `page`.

Product responses include the category when loaded. Variants are not nested; use the variant endpoints.

### Product variants

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/products/{product}/variants` | Public | List variants |
| GET | `/api/v1/products/{product}/variants/{variant}` | Public | Show variant |
| POST | `/api/v1/products/{product}/variants` | Sanctum | Create variant |
| PUT / PATCH | `/api/v1/products/{product}/variants/{variant}` | Sanctum | Update variant |
| DELETE | `/api/v1/products/{product}/variants/{variant}` | Sanctum | Delete variant |

Nested routes use scoped bindings. A variant accessed under the wrong product returns 404. `product_id` is taken from the URL, not the request body. Variants referenced by order items cannot be deleted (HTTP 409). Inactive or soft-deleted products do not expose variants.

### Orders

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/orders` | Sanctum | List the authenticated user's orders |
| GET | `/api/v1/orders/{order}` | Sanctum | Show own order |
| POST | `/api/v1/orders` | Sanctum | Create order |
| PATCH | `/api/v1/orders/{order}` | Sanctum | Not supported (405) |
| DELETE | `/api/v1/orders/{order}` | Sanctum | Cancel own order |

Another user's order ID returns **404** (owner-scoped binding).

Query parameters (list): `status`, `sort` (`created_at`, `updated_at`, `total`, `status`), `from_date`, `to_date`, `per_page`, `page`.

Order statuses: `pending`, `confirmed`, `processing`, `completed`, `cancelled`. New orders are `pending`. Cancellation is allowed only from `pending` or `confirmed`.

## Order and Inventory Processing

The client may send only product/variant IDs and quantities. The server ignores client-supplied `price`, `subtotal`, `total`, `stock`, `user_id`, `order_number`, and `status`.

Create flow (`OrderService`):

1. Merge duplicate lines (same product + same variant).
2. Open a database transaction.
3. Lock inventory rows in a deterministic ID order (`lockForUpdate()`).
4. Re-check active/deleted state after the lock.
5. Read current prices from the database.
6. Verify stock.
7. Create the order (`ORD-XXXXXXXX`, status `pending`).
8. Create order-item snapshots (`product_name`, `unit_price`, quantity, subtotal).
9. Decrement stock.
10. Commit, or roll back entirely on failure.

Inventory source:

- No variant: product price and product stock.
- Variant with a price: variant price and variant stock.
- Variant with a null price: product price and variant stock.

Product and variant lines for the same product stay separate.

Money is converted to integer cents, multiplied, then written back as `decimal(12,2)`. Tax is stored as `0.00`; **total equals subtotal**.

Cancellation restores stock once from the stored line items, then sets status to `cancelled`. The order and items remain. Repeated cancellation returns 409 and does not restore stock again.

**Concurrency:** `lockForUpdate()` is intended for MySQL/PostgreSQL. SQLite does not provide the same row-level locking. Local tests prove rollback, insufficient stock, and idempotent cancel — not production concurrent races.

## Security

- Sanctum `auth:sanctum` on protected routes
- Owner-scoped `{order}` binding
- Scoped nested `{product}/{variant}` routes
- Policies for orders, products, variants, and categories
- Explicit `$request->validated()` mapping (no `$request->all()` writes)
- No `$guarded = []`
- Sort fields are whitelisted (invalid sort → 422)
- LIKE search wildcards are escaped; search max 100 characters
- Pagination capped at 100
- Rate limits on login, register, and writes
- CORS origins from `CORS_ALLOWED_ORIGINS` (not `*`)
- Production API 500 responses omit stack traces when `APP_DEBUG=false`
- Unique constraints on slugs, SKUs, and order numbers
- Foreign keys use `restrictOnDelete` for order history

Catalog writes currently allow any authenticated user. There is no admin role yet.

## Database Design

| Entity | Notes |
|---|---|
| User | Has many orders |
| Category | Has many products; unique slug; soft deletes |
| Product | Belongs to category; has many variants; unique slug/SKU; `decimal(12,2)` price; unsigned stock; soft deletes |
| ProductVariant | Belongs to product; unique SKU; nullable price; stock |
| Order | Belongs to user; unique `order_number`; status enum; subtotal/tax/total |
| OrderItem | Belongs to order; snapshots name and unit price; optional variant |

A category cannot be deleted while products exist. A variant cannot be deleted while order items reference it. Soft-deleted products remain referenced by historical orders.

## Validation and Error Handling

Form Requests validate input. Examples:

- Order items: 1–50 lines, quantity 1–1000
- Prices: non-negative, max 2 decimal places, within `decimal(12,2)`
- Stock: integer ≥ 0
- Variant must belong to the given product

Typical JSON envelope:

```json
{
  "success": true,
  "message": "Products retrieved successfully.",
  "data": [],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 0
  }
}
```

| Status | Meaning |
|---|---|
| 201 | Created |
| 204 | Logout / successful catalog delete |
| 401 | Unauthenticated or invalid credentials |
| 403 | Unauthorized |
| 404 | Missing or not owned |
| 405 | Order PATCH (no editable fields) |
| 409 | Business conflict (stock, delete, cancel) |
| 422 | Validation failure |
| 429 | Rate limited |

## Testing

The suite covers authentication, authorization, catalog APIs, order processing, inventory, money calculations, validation, and IDOR/mass-assignment cases.

Snapshot at the time of writing: **118 tests**, **510 assertions**. Coverage percentage has not been measured.

```bash
php artisan test
vendor/bin/pint --test
```

PHPUnit uses SQLite `:memory:` (`phpunit.xml`).

## CI/CD

Workflow: [`.github/workflows/tests.yml`](https://github.com/mehransarwar1/laravel-rest-api/actions/workflows/tests.yml)

Runs on push and pull request to `main`. Jobs use PHP 8.2 and 8.3, install from `composer.lock`, migrate/seed on SQLite, run PHPUnit, Pint (`--test` on 8.2), and `composer audit --locked`. Token permissions are `contents: read`.

## Installation

```bash
git clone https://github.com/mehransarwar1/laravel-rest-api.git
cd laravel-rest-api
composer install
```

Copy the environment file:

```bash
# Windows
copy .env.example .env

# macOS / Linux
cp .env.example .env
```

```bash
php artisan key:generate
```

Default `DB_CONNECTION` is `sqlite`. Create the SQLite file if it does not exist:

```bash
# Windows PowerShell
New-Item -ItemType File -Path database\database.sqlite -Force

# macOS / Linux
touch database/database.sqlite
```

```bash
php artisan migrate --seed
php artisan serve
```

Seeded demo users use the factory password `password` (local development only). Example: `test@example.com`.

## Environment Configuration

| Variable | Purpose |
|---|---|
| `APP_ENV` | `local` / `production` |
| `APP_DEBUG` | Must be `false` in production |
| `APP_URL` | Application URL |
| `APP_KEY` | Generated; never commit a real key |
| `DB_CONNECTION` | Default `sqlite` |
| `DB_DATABASE` | SQLite path, or MySQL/Postgres database name |
| `CORS_ALLOWED_ORIGINS` | Comma-separated frontend origins |
| `SANCTUM_EXPIRATION` | Token lifetime in minutes; empty = no expiration. Production recommendation: `10080` (7 days) |

Do not commit `.env`. Production should not use `APP_DEBUG=true` or `CORS_ALLOWED_ORIGINS=*`.

## Running the Application

```bash
php artisan serve
```

Base URL: `http://localhost:8000/api/v1`

```bash
php artisan test
vendor/bin/pint --test
composer audit --locked
```

## Example API Requests

### Register / login

```http
POST /api/v1/auth/register
Content-Type: application/json

{
  "name": "Jane Doe",
  "email": "jane@example.com",
  "password": "Password1",
  "password_confirmation": "Password1"
}
```

```http
POST /api/v1/auth/login
Content-Type: application/json

{
  "email": "jane@example.com",
  "password": "Password1"
}
```

Example login response:

```json
{
  "success": true,
  "message": "Login successful.",
  "data": {
    "user": {
      "id": 1,
      "name": "Jane Doe",
      "email": "jane@example.com",
      "created_at": "2026-09-29T10:00:00+00:00"
    },
    "token": "1|..."
  }
}
```

Password hashes, `remember_token`, and tokens are not returned from `GET /api/v1/me`.

### List products

```http
GET /api/v1/products?search=laptop&sort=-price&per_page=15
```

### Create an order

```http
POST /api/v1/orders
Authorization: Bearer {token}
Content-Type: application/json

{
  "items": [
    { "product_id": 1, "quantity": 2 },
    { "product_id": 1, "product_variant_id": 2, "quantity": 1 }
  ]
}
```

The client does **not** send price, subtotal, total, stock, `user_id`, order number, or status.

Example create response:

```json
{
  "success": true,
  "message": "Order created successfully.",
  "data": {
    "id": 1,
    "order_number": "ORD-AB12CD34",
    "status": "pending",
    "subtotal": "20.00",
    "total": "20.00",
    "items": [
      {
        "id": 1,
        "product_id": 1,
        "product_variant_id": null,
        "product_name": "Demo Mug",
        "unit_price": "10.00",
        "quantity": 2,
        "subtotal": "20.00"
      }
    ],
    "created_at": "2026-09-29T10:00:00+00:00",
    "updated_at": "2026-09-29T10:00:00+00:00"
  }
}
```

## Project Structure

```text
app/
├── Enums/
├── Exceptions/
├── Http/
│   ├── Controllers/Api/V1/
│   ├── Requests/
│   └── Resources/
├── Models/
├── Policies/
├── Services/
└── Support/
database/
├── factories/
├── migrations/
└── seeders/
routes/
├── api.php
└── web.php
tests/
├── Feature/Api/V1/
└── Unit/
.github/workflows/tests.yml
```

## Engineering Decisions

- Sanctum tokens instead of a custom JWT stack
- Form Requests instead of controller validation
- Policies plus owner-scoped order binding (404 instead of 403 for other users' orders)
- Resources so models are not returned raw
- Integer cents instead of floating-point money math
- Server-side prices and snapshots so later catalog edits do not rewrite history
- Deterministic lock order to reduce deadlock risk on production databases
- Soft deletes on categories/products; hard-delete variants only when no order items exist
- SQLite for local/CI simplicity; MySQL/PostgreSQL for production locking

## Current Limitations

- No payments, cart, or checkout
- No admin role; any authenticated user can mutate the catalog
- SQLite cannot prove production concurrent stock safety
- Token expiration depends on `SANCTUM_EXPIRATION` (empty by default)
- No production deployment configuration
- `PATCH /api/v1/orders/{order}` returns 405 (no user-editable order fields)
- `processing` and `completed` orders cannot be cancelled

## Future Improvements

- Admin roles and catalog permissions
- MySQL/PostgreSQL concurrency integration tests
- Payment integration
- OpenAPI documentation
- Larastan / PHPStan
- Deployment automation
- Inventory reservations

These are not implemented.

## License

MIT, as declared in `composer.json`. There is no separate `LICENSE` file in the repository.
