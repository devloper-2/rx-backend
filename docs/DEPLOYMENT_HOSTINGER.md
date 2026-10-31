# Deploying to Hostinger (prescriptionrx.in)

This backend is prepared for deployment. Steps 1, 2, 3 and 7 below were
already completed via hPanel + GitHub (2026-10-31) — they're left in place
as a record of what was done and where the values came from. **Step 8 (first
deploy: `.env` + DB import) and SSL/domain verification (steps 4-5) are not
done and still need a human.**

## Already done (2026-10-31)

- MySQL database + user created: `u715248258_prescrx_app` /
  `u715248258_prescrx_app` (password generated, not stored in this repo —
  check your password manager / ask Claude's session log).
- SSH confirmed active: `u715248258@82.25.107.184:65002`.
- A dedicated deploy keypair (`github-actions-deploy`) was generated and its
  **public** key added under hPanel → Advanced → SSH Access → SSH keys.
- Confirmed the real deploy path via SSH: the site already has a (stale,
  manually-uploaded, no `.env`, no git) copy of this backend at
  `domains/prescriptionrx.in/public_html/api` — so `HOSTINGER_DEPLOY_PATH`
  is that path, **not** `public_html`.
- All 5 GitHub Actions secrets added to `devloper-2/rx-backend`:
  `HOSTINGER_SSH_HOST=82.25.107.184`, `HOSTINGER_SSH_USER=u715248258`,
  `HOSTINGER_SSH_PORT=65002`, `HOSTINGER_SSH_KEY` (the deploy private key),
  `HOSTINGER_DEPLOY_PATH=domains/prescriptionrx.in/public_html/api`.

## What's already prepared in the repo

- `config/Config.php` now reads all environment-specific settings (DB
  credentials, base URL, JWT secret, dev-OTP flag, etc.) from environment
  variables / a local `.env` file, falling back to the original local XAMPP
  defaults when nothing is set. **Local dev needs no changes.**
- `.env.example` documents every variable production needs.
- `.github/workflows/deploy.yml` rsyncs the repo to the server over SSH on
  every push to `main`.
- `.htaccess` already blocks direct access to `.sql`, `.md`, `.env`, `.git`,
  `logs/`, `config/`, `core/`, `helpers/`, `app/`, `routes/`, `migrations/`,
  `storage/`, `tests/` — verify this file is still present and unmodified
  after deploy (point 8 below).

## 1. Create the MySQL database + user (hPanel)

1. hPanel → **Databases → MySQL Databases**.
2. Create a database (e.g. `uXXXXXX_rx`) and a database user with a strong
   password; Hostinger auto-prefixes both with your account ID.
3. Note the **DB host** (usually `localhost` on shared hosting, but check
   the panel — some plans show a specific host), database name, user, and
   password. These become `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.

## 2. Enable SSH access and get host/port

1. hPanel → **Advanced → SSH Access** → enable it.
2. Note the **SSH hostname** and **port** shown there (Hostinger shared
   hosting commonly uses a non-22 port, e.g. 65002 — check your panel).
   These become `HOSTINGER_SSH_HOST` and `HOSTINGER_SSH_PORT`.
3. Note the SSH **username** (e.g. `uXXXXXX`) → `HOSTINGER_SSH_USER`.

## 3. Create an SSH key pair and add the public key in hPanel

On your own machine (not the server):

```bash
ssh-keygen -t ed25519 -C "deploy-rx-backend" -f ~/.ssh/rx_backend_deploy -N ""
```

1. Copy the contents of `~/.ssh/rx_backend_deploy.pub`.
2. hPanel → **Advanced → SSH Access → Manage SSH Keys** → add it there (or
   append it to `~/.ssh/authorized_keys` over an initial password SSH login
   if hPanel doesn't offer a key manager on your plan).
3. The **private** key (`~/.ssh/rx_backend_deploy`, the file with no `.pub`)
   becomes the `HOSTINGER_SSH_KEY` GitHub secret — paste its full contents
   (including the `-----BEGIN...-----`/`-----END...-----` lines).

## 4. Point prescriptionrx.in at Hostinger

1. If the domain is registered elsewhere, go to that registrar and set its
   nameservers to Hostinger's (shown in hPanel → **Domains**), *or* add an
   `A` record at the registrar pointing to the server's IP (shown in hPanel
   → **Hosting → Overview**).
2. If the domain is already on Hostinger, hPanel → **Domains** → attach
   `prescriptionrx.in` to this hosting account/plan.
3. DNS propagation can take up to 24-48h; hPanel shows propagation status.

## 5. Issue SSL

1. Once DNS points at Hostinger, hPanel → **Security → SSL** →
   issue the free Let's Encrypt certificate for `prescriptionrx.in` (and
   `www.prescriptionrx.in` if you'll use that too).
2. Enable **Force HTTPS** in the same section so plain `http://` redirects.

## 6. Set the document root

This repo's deploy path (`HOSTINGER_DEPLOY_PATH`, step 7) is whatever
directory you rsync into — it does **not** have to be `public_html` itself.

- **Simplest:** set `HOSTINGER_DEPLOY_PATH` to `public_html` (or
  `public_html/prescriptionrx.in` if Hostinger uses a per-domain subfolder
  for addon domains — check hPanel → **Websites**) so the API is served at
  the domain root (`https://prescriptionrx.in/auth/login-new`, etc.).
- **If you'd rather not serve from the webroot directly:** deploy to a
  sibling folder outside `public_html` (e.g. `~/rx-backend`) and point
  hPanel's **document root** for `prescriptionrx.in` at that folder instead
  (hPanel → **Websites → Manage → Document Root**, if your plan exposes
  that) — but Hostinger shared hosting generally serves whatever is in
  `public_html`, so the first option is usually required.
- Either way, update `APP_BASE_URL` in `.env` to match whatever path the
  API actually ends up served at.

## 7. Add the GitHub repo secrets — DONE (2026-10-31)

All five secrets are already set on `devloper-2/rx-backend` → **Settings →
Secrets and variables → Actions**: `HOSTINGER_SSH_HOST`,
`HOSTINGER_SSH_USER`, `HOSTINGER_SSH_PORT`, `HOSTINGER_SSH_KEY`,
`HOSTINGER_DEPLOY_PATH`. See "Already done" at the top for the values used.

## 8. First deploy + one-time manual steps

The GitHub Actions workflow (`.github/workflows/deploy.yml`) only
rsyncs files — it deliberately does **not**:

- Create or write `.env` on the server. **You must create it by hand once**
  (SSH in, `cd` to the deploy path, copy `.env.example` to `.env`, fill in
  real values — especially `DB_*`, `JWT_SECRET`, and make sure
  `EXPOSE_DEV_OTP=false` / `APP_ENV=production`). The workflow excludes
  `.env` from rsync on purpose so it's never overwritten or exposed via git.
- Import the database schema or run `migrations/sql/*.sql` files. The first
  deploy needs this done by hand over SSH (same commands as the README's
  "Quick start", against the production DB):
  ```bash
  mysql -u<DB_USER> -p <DB_NAME> < php_rest_api.sql
  mysql -u<DB_USER> -p <DB_NAME> < migrations/sql/002_doctor_auth.sql
  mysql -u<DB_USER> -p <DB_NAME> < migrations/sql/003_seed_gp_catalog.sql
  mysql -u<DB_USER> -p <DB_NAME> < migrations/sql/004_seed_lab_catalog.sql
  mysql -u<DB_USER> -p <DB_NAME> < migrations/sql/005_followup_actions.sql
  mysql -u<DB_USER> -p <DB_NAME> < migrations/sql/006_hours_and_billing.sql
  mysql -u<DB_USER> -p <DB_NAME> < migrations/sql/007_clinic_staff.sql
  ```
  Any **new** `migrations/sql/NNN_*.sql` added later also needs to be
  applied by hand after it's deployed — the workflow will never run raw SQL
  against production automatically, to avoid accidentally re-running or
  mis-ordering a destructive script unattended.
- Confirm `.htaccess` made it to the server unchanged and still blocks
  `.sql`/`.md`/`.env`/`.git` and the sensitive directories — open
  `https://prescriptionrx.in/php_rest_api.sql` and
  `https://prescriptionrx.in/.env` in a browser after deploy; both must
  **not** be downloadable (expect a 403).
- Confirm `logs/`, `uploads/`, `storage/` are writable by the web server
  user on the production filesystem (the workflow's post-deploy step runs
  `chmod -R a+rwX`, but double-check if PHP errors mention permission
  denied).

## 9. Push to deploy

Once secrets are set and the first manual DB import + `.env` are done,
pushing to `main` triggers `.github/workflows/deploy.yml`, which rsyncs the
repo to `HOSTINGER_DEPLOY_PATH` over SSH. Watch the Actions tab for the run.
