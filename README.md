# PHP REST API — Class-Based, No Framework

A production-ready PHP REST API built from scratch — no Composer, no frameworks.
Designed for **shared hosting** (Apache + mod_rewrite).

---

## 📁 Project Structure

```
php-rest-api/
├── .htaccess                  ← URL rewriting (domain.com/user/login)
├── index.php                  ← Entry point (bootstrap + dispatch)
│
├── config/
│   └── Config.php             ← All config (DB, JWT, upload, log) — local/production
│
├── core/
│   ├── Router.php             ← Route dispatch + JWT middleware + rate limiting
│   ├── Request.php            ← HTTP request parsing (JSON, form, files)
│   ├── Response.php           ← Standardized JSON response + status codes
│   ├── Database.php           ← PDO singleton (CRUD, paginate, transactions)
│   ├── Auth.php               ← Pure-PHP JWT (HS256) — no libraries needed
│   ├── Logger.php             ← Structured file logging (request/response/error)
│   ├── RateLimit.php          ← Per-endpoint, per-IP rate limiting (DB-backed)
│   └── Validator.php          ← Input validation with chainable rules
│
├── helpers/
│   ├── CommonHelper.php       ← Utility functions (hash, sanitize, slug, etc.)
│   └── FileUploadHelper.php   ← Media upload → uploads/{entity}/{id}/{file}
│
├── app/controllers/
│   ├── BaseController.php     ← All controllers extend this
│   ├── AuthController.php     ← register, login, refresh, logout, me
│   └── UserController.php     ← list, detail, update, delete, uploadAvatar
│
├── routes/
│   └── api.php                ← All route definitions with options
│
├── migrations/
│   ├── Runner.php             ← Migration engine
│   ├── run.php                ← CLI entry: php migrations/run.php
│   └── versions/
│       └── 001_initial_setup.php  ← Creates tables + seeds admin user
│
├── logs/                      ← Auto-created, web-protected
│   ├── requests/              ← Every request + response logged
│   └── errors/                ← Errors only
│
└── uploads/                   ← Media files
    └── user/1/avatar_xxx.jpg
```

---

## ⚡ Quick Start

### 1. Database Setup
```sql
CREATE DATABASE php_rest_api CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 2. Configure
Edit `config/Config.php`:
```php
const ENV = 'local';  // or 'production'

// In 'local' section:
'db' => [
    'host' => 'localhost',
    'name' => 'php_rest_api',
    'user' => 'root',
    'pass' => '',
],
'jwt' => [
    'secret' => 'CHANGE-THIS-TO-A-STRONG-SECRET-KEY',
],
```

### 3. Run Migrations
```bash
# Creates tables + seeds admin@example.com / Admin@1234
php migrations/run.php

# Other commands:
php migrations/run.php status    # View migration history
php migrations/run.php rollback  # Undo last batch
```

### 4. Place in htdocs
Copy the entire folder to your htdocs/www directory. Make sure `mod_rewrite` is enabled.

---

## 🔌 API Endpoints

### Base URL
```
http://localhost/php-rest-api
```

### Authentication

| Method | Endpoint        | Auth | Rate Limit |
|--------|-----------------|------|------------|
| POST   | /auth/register  | No   | 3/hr       |
| POST   | /auth/login     | No   | 5/hr       |
| POST   | /auth/refresh   | No   | 10/hr      |
| POST   | /auth/logout    | Yes  | —          |
| GET    | /auth/me        | Yes  | 30/hr      |

### User Management

| Method | Endpoint              | Auth | Rate Limit | Role  |
|--------|-----------------------|------|------------|-------|
| GET    | /user/list            | Yes  | 15/hr      | Any   |
| GET    | /user/detail/{id}     | Yes  | 30/hr      | Any   |
| PUT    | /user/update/{id}     | Yes  | 10/hr      | Self/Admin |
| DELETE | /user/delete/{id}     | Yes  | 5/hr       | Admin |
| POST   | /user/upload-avatar   | Yes  | 5/hr       | Self  |
| PUT    | /user/change-password | Yes  | 3/hr       | Self  |

---

## 📨 Request / Response Format

### Login Request
```http
POST /auth/login
Content-Type: application/json

{
    "email": "admin@example.com",
    "password": "Admin@1234"
}
```

### Success Response
```json
{
    "status": true,
    "code": 200,
    "message": "Login successful",
    "data": {
        "user": { "id": 1, "name": "Super Admin", "email": "admin@example.com", "role": "admin" },
        "access_token": "eyJ...",
        "refresh_token": "abc123...",
        "token_type": "Bearer",
        "expires_in": 3600
    },
    "errors": null,
    "meta": {
        "timestamp": "2024-01-15T10:30:00+00:00",
        "version": "v1",
        "elapsed_ms": 12.5
    }
}
```

### Error Response (Validation)
```json
{
    "status": false,
    "code": 422,
    "message": "Validation failed",
    "data": null,
    "errors": {
        "email": ["Email is required."],
        "password": ["Password must be at least 8 characters."]
    },
    "meta": { ... }
}
```

### Authenticated Request
```http
GET /user/list?page=1&limit=10
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

### Paginated Response
```json
{
    "status": true,
    "code": 200,
    "message": "Users fetched successfully",
    "data": [ ... ],
    "pagination": {
        "total": 50,
        "page": 1,
        "limit": 10,
        "total_pages": 5,
        "has_next": true,
        "has_prev": false
    }
}
```

---

## ➕ Adding New Controllers

### 1. Create controller file
```php
// app/controllers/ProductController.php
<?php
declare(strict_types=1);
require_once ROOT_PATH . '/app/controllers/BaseController.php';

class ProductController extends BaseController
{
    public function list(): void
    {
        $this->requireAuth();
        $page   = $this->getPage();
        $limit  = $this->getLimit();
        $result = $this->db->paginate('SELECT * FROM products ORDER BY id DESC', [], $page, $limit);
        Response::paginated($result['data'], $result['pagination']);
    }

    public function create(): void
    {
        $this->requireAuth();
        $data = $this->validate([
            'name'  => 'required|string|min:2|max:200',
            'price' => 'required|numeric|min:0',
        ]);
        $id = $this->db->insert('products', [
            'name'       => CommonHelper::sanitizeString($data['name']),
            'price'      => $data['price'],
            'created_at' => CommonHelper::now(),
        ]);
        Response::created(['id' => $id], 'Product created');
    }
}
```

### 2. Register routes
```php
// routes/api.php
$router->get('product/list',   'Product', 'list',   ['auth' => true, 'rate_limit' => ['max' => 30, 'window' => 3600]]);
$router->post('product/create','Product', 'create', ['auth' => true]);
```

### 3. Add URL-param routes
```php
$router->get('product/detail/{id}', 'Product', 'detail', ['auth' => true]);
// In controller: public function detail(array $urlParams): void { $id = $urlParams['id']; }
```

---

## 📁 Adding Migrations

```php
// migrations/versions/002_add_products_table.php
<?php
return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS products (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name       VARCHAR(200) NOT NULL,
                price      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    },
    'down' => function (PDO $pdo): void {
        $pdo->exec("DROP TABLE IF EXISTS products;");
    },
];
```

Then run: `php migrations/run.php`

---

## 📂 File Upload

```http
POST /user/upload-avatar
Authorization: Bearer <token>
Content-Type: multipart/form-data

avatar: <file>
```

Files are saved to: `uploads/user/{user_id}/avatar_{timestamp}_{random}.jpg`

---

## 🔐 Default Credentials

| Email              | Password   | Role  |
|--------------------|------------|-------|
| admin@example.com  | Admin@1234 | admin |

> ⚠️ Change the admin password immediately after setup!

---

## 🧾 Log Files

```
logs/YYYY-MM-DD.log            → General app log
logs/requests/YYYY-MM-DD.log   → Every request + response (JSON)
logs/errors/YYYY-MM-DD.log     → Errors only
```

All logs are web-protected via `.htaccess`.

---

## ⚙️ PHP Requirements

- PHP 8.1+
- PDO + PDO_MySQL extension
- Apache with `mod_rewrite`
- `fileinfo` extension (MIME detection)
