# Prescription Rx — Backend (PHP, no framework)

Plain-PHP REST API for the Prescription Rx doctor app. Apache + `mod_rewrite` + MySQL/MariaDB.

## Quick start (XAMPP)

1. Create the database and import the dump, then apply the migration:
   ```bash
   mysql -uroot -e "CREATE DATABASE php_rest_api CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   mysql -uroot php_rest_api < php_rest_api.sql
   mysql -uroot php_rest_api < migrations/sql/002_doctor_auth.sql
   mysql -uroot php_rest_api < migrations/sql/003_seed_gp_catalog.sql   # everyday GP formulary, symptoms, advice (safe to re-run)
   mysql -uroot php_rest_api < migrations/sql/004_seed_lab_catalog.sql  # ~65 lab tests with reference ranges (safe to re-run)
   mysql -uroot php_rest_api < migrations/sql/005_followup_actions.sql # snoozed / dismissed follow-up items
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
| GET | `catalog/medicines?q=` | JWT | formulary search; blank `q` returns *this doctor's frequently used* medicines. Every item carries `last_used` (schedule, duration, instructions) to pre-fill the editor |
| POST | `catalog/medicines` | JWT | add a doctor-private medicine |
| GET | `catalog/symptoms`, `catalog/advice`, `catalog/diagnoses` | JWT | picker data; diagnoses = the doctor's own most used |
| POST | `prescriptions` | JWT | `patient_id`, `status` (draft\|final), vitals, `chief_complaint`, `symptoms[]`, `diagnosis`, `medicines[]` (dose slots), `advice[]`, `followup_date`. BMI and totals are computed server-side |
| GET | `prescriptions?since=&status=&page=` | JWT | recent prescriptions across patients |
| GET/PUT/DELETE | `prescriptions/{id}` | JWT | read; **edit/delete drafts only** (`PUT` with `status=final` saves and finalises in one step) |
| POST | `prescriptions/{id}/finalize` · `/cancel` | JWT | draft → final; final → cancelled. A finalised prescription is immutable |
| GET | `patients/{id}/prescriptions` | JWT | a patient's history |
| GET/PUT | `doctor/clinic` | JWT | clinic name/address printed on prescriptions |
| GET | `catalog/lab-tests?q=&codes=` | JWT | lab test search; blank = most-ordered + everyday basics; `codes=CBC,FBS` resolves panel shortcuts |
| GET | `orders?status=pending\|done\|all&kind=all\|lab\|imaging&patient_id=` | JWT | lab + imaging orders (final prescriptions only); urgent and oldest first when pending |
| GET | `lab-orders/{id}` · `radiology-orders/{id}` | JWT | detail; lab tests carry the patient's **previous value** of the same test |
| PUT | `lab-orders/{id}/results` | JWT | per-test `result_value` (+ optional `result_flag`); low/high/normal worked out from the reference range; all results in → `report_ready` |
| PUT | `radiology-orders/{id}/report` | JWT | `impression`, `radiologist_report` |
| POST | `lab-orders/{id}/status` · `radiology-orders/{id}/status` | JWT | validated status flow (ordered → … → report_ready → delivered, or cancelled) |
| POST/GET | `lab-orders/{id}/file` · `radiology-orders/{id}/file` | JWT | attach / read a photo of the printed report (JPG/PNG/WebP, 5 MB) |
| GET | `appointments?from=&to=&patient_id=&upcoming=1` | JWT | the queue / diary. `from`/`to` are UTC, so "today" follows the doctor's clock |
| POST | `appointments` | JWT | `walk_in:true` joins the queue now (status `arrived`); otherwise `scheduled_at` books a slot. Same patient twice within 12 h → 409 + `existing` unless `force` |
| PUT | `appointments/{id}` | JWT | reschedule / edit (only `scheduled`/`confirmed`) |
| POST | `appointments/{id}/status` | JWT | `confirmed`, `arrived`, `cancelled` (+`cancel_reason`), `no_show`, `completed`; only valid transitions are accepted |
| GET | `followups?today=YYYY-MM-DD` | JWT | "Needs attention": `followups`, `refills`, `results_to_review`, `pending_results`, `missed` + total `attention`. `today` is the doctor's local date |
| POST | `followups/dismiss` | JWT | `kind, ref_id, days?` — snooze for `days` (1–365) or remove for good |
| POST | `reminders` | JWT | `kind (followup\|refill\|missed), ref_id, message` — logs a reminder the doctor sent from WhatsApp in `wa_notifications` |

## Appointments and the queue

A walk-in is an appointment that has already arrived, so one list answers *who is waiting*, *who is due* and *who was seen*. Creating a prescription with `appointment_id` links it; **finalising it completes the visit automatically**. `updated_at` doubles as the check-in time for "waiting 12 min".

## Lab and imaging

Orders are written **inside the prescription** (`investigations`: one lab order holding all tests, plus one radiology order per study) and rewritten while it is a draft; once the prescription is final they form the doctor's "awaiting results" list. Cancelling the prescription cancels orders that have no result yet.
Reference ranges in `lab_tests_catalog` are generic adult values: the doctor can override any flag, and the lab's own range on the report prevails.
Report photos are stored in `storage/reports/<doctor_id>/` (blocked from the web by `.htaccess`) and streamed only to the signed-in owner.

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
python3 tests/api_prescriptions.py    # prescriptions, catalog, clinic: validation, immutability, isolation
python3 tests/api_appointments.py     # queue, booking, status flow, link with prescriptions
python3 tests/api_investigations.py   # lab/imaging orders, results + flags, status flow, private report photos
python3 tests/api_followups.py        # needs-attention lists, reminders, snooze, isolation (back-dates rows with the mysql CLI)
```

The register endpoint is rate limited (20/hour per IP). If a run says `429`, clear the dev counters:
`mysql -uroot php_rest_api -e "delete from rate_limits"`.

It creates throw-away doctors with random mobile numbers. See `OVERNIGHT_REPORT.md` in the repo root
for everything that was found and changed.

## Before production

- Change `jwt.secret` and DB credentials in the `production` block, set `ENV = 'production'`.
- Serve over HTTPS; `expose_dev_otp` must stay `false`.
- Keep `.htaccess` in place — it blocks `config/ core/ logs/ tests/ *.sql *.md .git` from the web.
