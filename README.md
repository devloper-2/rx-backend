# Prescription Rx — Backend (PHP, no framework)

Plain-PHP REST API for the Prescription Rx doctor app. Apache + `mod_rewrite` + MySQL/MariaDB.

## Quick start (XAMPP)

1. Create the database and import the dump, then apply the migration:
   ```bash
   mysql -uroot -e "CREATE DATABASE php_rest_api CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   mysql -uroot php_rest_api < php_rest_api.sql
   mysql -uroot php_rest_api < migrations/sql/002_doctor_auth.sql
   ```
2. Make `logs/`, `uploads/` and `storage/` writable by Apache (`chmod -R a+rwX logs uploads storage` for local dev).
3. Check `config/Config.php` (DB credentials, JWT secret). `ENV = 'local'` for development.
4. API root (this checkout): `http://<your-ip>/prescription-rx/rx-backend/`

> The old `migrations/versions/001_initial_setup.php` belongs to the generic template (it creates
> `users` / `refresh_tokens`) and is **not used** — the real schema is `php_rest_api.sql`.

## Endpoints

All responses: `{ status, code, message, data, errors, meta }`.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `auth/register-new` | – | `name, phone, countrycode, password` → creates an *inactive* doctor, issues a mobile OTP |
| POST | `auth/verify-otp` | – | `mobile, otp` → activates the account, returns tokens |
| POST | `auth/resend-otp` | – | `mobile, purpose (register\|reset)` |
| POST | `auth/login-new` | – | `identifier (mobile or e-mail), password` |
| POST | `auth/forgot-password` | – | `mobile` (mobile or e-mail) → reset OTP (generic response, no account enumeration) |
| POST | `auth/reset-password` | – | `mobile, otp, password` |
| POST | `auth/refresh` | – | `refresh_token` → new access token |
| POST | `auth/logout` | – | `refresh_token` (proves ownership; works with an expired access token) |
| GET | `auth/me` | JWT | current doctor |
| GET/PUT | `doctor/profile` | JWT | read / update name, e-mail, degree, specialization, registration_no, experience_yrs |
| PUT | `doctor/change-password` | JWT | `current_password, password` → signs out other devices, returns fresh tokens |
| GET | `dashboard/summary` | JWT | numbers for the home screen |
| GET | `patients?q=&page=&limit=` | JWT | the doctor's patients, search by name / mobile / UHID, newest activity first |
| POST | `patients` | JWT | `name, sex, age_years` **or** `date_of_birth`, optional mobile, allergies, `condition_ids`, … Returns 409 + `duplicate` for the same name on the same mobile unless `force=true` |
| GET/PUT/DELETE | `patients/{id}` | JWT | read / update / archive (soft delete). Always scoped to the signed-in doctor |
| GET | `catalog/conditions` | JWT | chronic-condition catalogue |

## OTP delivery (important)

SMS / e-mail sending is **not implemented**. While `Config app.expose_dev_otp` is `true` (local only) the
code is returned in the response as `dev_otp` and the app shows it in a yellow "Test mode" banner.
Wire a provider in `AuthController::issueOtp()` (see the `TODO`) and set `expose_dev_otp` to `false`
before going live. The production block in `Config.php` already has it off.

OTP rules: 6 digits · valid 5 min · 30 s resend cooldown · locked after 5 wrong attempts.

## Sessions

`auth_tokens` has a unique key on `doctor_id`, so a doctor has **one** active session: signing in on a
second device signs the first one out. Access token 1 h, refresh token 7 days (stored hashed).

## Tests

```bash
python3 tests/api_smoke.py            # auth, profile, security (56 checks)
python3 tests/api_patients.py         # patients: CRUD, search, duplicates, isolation between doctors
```

The register endpoint is rate limited (20/hour per IP). If a run says `429`, clear the dev counters:
`mysql -uroot php_rest_api -e "delete from rate_limits"`.

It creates throw-away doctors with random mobile numbers. See `OVERNIGHT_REPORT.md` in the repo root
for everything that was found and changed.

## Before production

- Change `jwt.secret` and DB credentials in the `production` block, set `ENV = 'production'`.
- Serve over HTTPS; `expose_dev_otp` must stay `false`.
- Keep `.htaccess` in place — it blocks `config/ core/ logs/ tests/ *.sql *.md .git` from the web.
