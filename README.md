# lookup

A PHP/MySQL CRM data-search portal: state-wise customer lookup, e-commerce
order search, Pan India search (Telegram-backed), and LPG SDMS search, all
behind a single login with per-agent permissions.

## Features

- **State search** — Tamil Nadu, Andhra Pradesh, Karnataka, Kerala, each
  searchable by mobile number, name, address, name+father, name+DOB,
  name+address, identity document, or full address.
- **E Commerce search** — separate data shape (delivery date, coordinates,
  no father's name/DOB), with its own bulk import tool.
- **Pan India search** — email/Aadhaar/contact-number lookup against a
  Telegram bot, gated by a per-agent `Pan India Access` permission. See
  [PAN_INDIA_DEPLOYMENT.md](PAN_INDIA_DEPLOYMENT.md) for the Telegram worker
  setup.
- **LPG Search / LPG Bulk Search** — single or batch (up to 500 for admins,
  10 for agents) mobile-number lookup against IndianOil's SDMS portal via a
  Selenium-driven backend, gated by a per-agent `LPG Search Access`
  permission.
- **WhatsApp floating button** — optional site-wide contact button, with a
  global on/off switch and a per-agent allowlist.
- **Admin console** — account management (create/disable/delete agents and
  admins, expiry dates, per-agent permission grants), audit log of every
  search, and bulk import tools for state and e-commerce data.

## Requirements

- PHP 8.2+ with curl, fileinfo, gd, gmp, mbstring, mysqli/pdo_mysql,
  openssl, and sockets
- MySQL/MariaDB
- Python 3 + Selenium + a matching ChromeDriver, only if LPG Search is used
  (see `Gas/lpg_web/`)
- A Telegram account with API credentials, only if Pan India search is used
  (see `PAN_INDIA_DEPLOYMENT.md`)

## Setup

1. Copy the config templates and fill in real values:
   ```
   config/db.php.example      -> config/db.php
   config/secrets.php.example -> config/secrets.php
   config/telegram.php.example -> config/telegram.php   (Pan India only)
   ```
2. Create the database and apply the schema, then every `database/migrate_*.sql`
   file in the order they were added (each has a comment explaining what it
   does and its default value for existing rows):
   ```
   database/schema.sql
   database/schema_ecommerce.sql
   database/schema_states.sql
   database/migrate_*.sql
   ```
3. Run the app with PHP's built-in server through the included router (the
   router blocks direct access to `config/`, `database/`, `.git/`, and other
   non-public paths — don't serve this without it):
   ```
   php -S 127.0.0.1:8080 -t . .runtime/router.php
   ```
   `run_crm_server.bat` / `run_crm_server.ps1` do this plus start the
   Telegram worker in one step, for local Windows use.
4. Log in with an account created directly in the `users` table (see
   `database/schema.sql`), then use Admin > Agents to create the rest.

## LPG Search backend

`Gas/lpg_web/app.py` is a small Flask service (default port 9197) that
`lpg_search_api.php` proxies to. It runs Selenium against SDMS one number at
a time per job, with up to 4 jobs concurrently. Start it directly or via
`Gas/lpg_web/run_lpg_service.bat`, which also restarts it if it stops
responding.

## Pan India backend

`cli/telegram_worker.php` is a long-running MadelineProto process (default
port 8091) that `api/pan_india.php` talks to over loopback. It needs a
one-time interactive Telegram QR login per server; see
[PAN_INDIA_DEPLOYMENT.md](PAN_INDIA_DEPLOYMENT.md) for the full setup and
what not to commit (session files, downloaded runtime, real API credentials).
