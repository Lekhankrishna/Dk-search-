# DK Search

Internal CRM/data-search portal used by agents to look up customer records across multiple states, e-commerce orders, and a range of third-party lookup tools (PAN India, Advance Pan India, Night Out, Tracing 2.0, RC Print, HP Gas Search, Indane Gas, TATA SKY DTH, Advanced Search, Indian LPG Search) — plus an admin panel for account management, per-agent feature access, and monthly usage caps on the tools that run against a shared vendor account.

## Features

- **State-wise customer search** (`index.php`) — Tamil Nadu, Andhra Pradesh, Karnataka, Kerala; searchable by mobile number, name, address, name+father, name+DOB, name+address, identity document, or full address.
- **E-Commerce order search** (`ecommerce.php`) — separate data shape (delivery date, coordinates, no father's name/DOB), with its own bulk CSV import.
- **Pan India Search** (`pan_india.php`) — email/Aadhaar/contact-number lookup against a Telegram bot via a persistent PHP MadelineProto worker (`cli/telegram_worker.php`).
- **Advance Pan India** (`advance_pan_india.php`) — plain PHP+curl client against a Django-backed vendor site (`includes/eagleeye_client.php`).
- **Night Out** (`pan_india_pro.php`) — plain PHP+curl client against a JSON REST API (`includes/pan_india_pro_client.php`).
- **Tracing 2.0** (`tracing2.php`) — a generic scraper covering all 24 of locateme.services' tools (Mobile Info, Vehicle Intelligence, UPI Finder, etc. - see `includes/tracing2_tools.php`) as tabs on one page, with RC Print and HP Gas Advanced folded in as tabs too. Each tool is individually gated per-agent, with credit-cost tracking against a shared monthly budget.
- **RC Print** / **HP Gas Search** (`rc_print.php`, `hp_gas.php`) — also available as their own standalone pages (in addition to their Tracing 2.0 tabs), Selenium automation against a shared Firebase-backed vendor account (`Gas/lpg_web/`), each with its own per-agent monthly search cap. HP Gas Search also has a Bulk Search tab (single-endpoint sequential lookups, capped at 10 numbers for agents / 50 for admins).
- **Indane Gas Info** (`indane_gas_info.php`) — split out of Tracing 2.0's generic tool checklist into its own dedicated access flag and monthly quota.
- **TATA SKY DTH** (`tataplay.php`) — mobile-number lookup via the Tata Play distributor's `mysso.tataplay.com` SSO login (`Gas/lpg_web/tataplay.py`).
- **Indian LPG Search** (`lpg_search.php`) — Single/Bulk Search tabs in one page (bulk capped at 10 for agents / 500 for admins) looking up IndianOil SDMS gas-connection records via a Flask/Selenium service. `lpg_bulk_search.php` redirects here with `?mode=bulk` for old links. Currently unlinked from the sidebar (reversible - see `includes/header.php`) but still fully functional for anyone with a direct link.
- **Advanced Search** (`advanced_search.php`) — plain PHP+curl client against an ASP.NET Core site with five separate per-region endpoints (`includes/tracekart_client.php`).
- **WhatsApp contact button** — a site-wide floating button, admin-configurable (global on/off plus a per-user allowlist).
- **Admin panel** (`admin/`) — account management (create/disable/delete, expiry dates), per-agent feature access + monthly usage caps, per-tool Tracing 2.0 checklists with credit overrides, multi-device login limits, audit log, Excel export of the accounts list, and bulk import tools for state/e-commerce data.

Every search tool is opt-in per account (off by default) and enforced both in the sidebar (hidden if not granted) and server-side in each tool's own API endpoint — granted from **Admin → Agents**. Results from every tool auto-archive to a deduplicated CSV on a separate drive, independent of the CRM's own database.

## Tech stack

- PHP 8.x + MySQL (PDO)
- Python/Flask + Selenium for the Indian LPG Search, RC Print, HP Gas, Tracing 2.0, and TATA SKY DTH services
- PHP (MadelineProto) for the Telegram-backed Pan India worker
- Plain PHP+curl clients (no browser automation) for Advance Pan India, Night Out, and Advanced Search
- Vanilla JS/CSS frontend, no build step

## Requirements

- PHP 8.2+ (curl, fileinfo, gd, gmp, mbstring, mysqli/pdo_mysql, openssl, sockets)
- MySQL/MariaDB
- Python 3 + Selenium + a matching ChromeDriver, only if Indian LPG Search / RC Print / HP Gas / Tracing 2.0 / TATA SKY DTH are used (see `Gas/lpg_web/`)
- A Telegram account with API credentials, only if Pan India search is used (see `PAN_INDIA_DEPLOYMENT.md`)

## Setup

1. **Config files** — copy each `.example` file and fill in real values (the real files are gitignored, never commit them):
   ```
   config/db.php.example                -> config/db.php
   config/secrets.php.example           -> config/secrets.php
   config/telegram.php.example          -> config/telegram.php          (only needed for Pan India Search)
   config/vendor_credentials.php.example -> config/vendor_credentials.php (Advance Pan India / Night Out / Advanced Search)
   Gas/lpg_web/config.py.example        -> Gas/lpg_web/config.py        (Indian LPG Search / RC Print / HP Gas / Tracing 2.0 / TATA SKY DTH)
   ```
2. **Database** — create the database, then run `database/schema.sql`, `database/schema_states.sql`, `database/schema_ecommerce.sql`, and every `database/migrate_*.sql` file in the order they were added (each has a comment explaining what it does and its default for existing rows).
3. **Web server** — run through the included router, which blocks direct access to `config/`, `database/`, `.git/`, and other non-public paths (don't serve this without it):
   ```
   php -S 127.0.0.1:8080 -t . .runtime/router.php
   ```
   `run_crm_server.bat` / `run_crm_server.ps1` do this plus start the Telegram worker in one step, for local Windows use. `web.config` includes an IIS reverse-proxy rule for the LPG tool if deploying under IIS instead.
4. Log in with an account created directly in the `users` table (see `database/schema.sql`), then use Admin > Agents to create the rest.
5. **Optional: Indian LPG Search / RC Print / HP Gas / Tracing 2.0 / TATA SKY DTH service** — see `Gas/lpg_web/README.txt`. Requires Python, Selenium, and Chrome/Chromedriver on the host. Runs as a Flask service (default port 9197) that the relevant `*_api.php` proxies to over loopback; `Gas/lpg_web/run_lpg_service.bat` also restarts it if it stops responding.
6. **Optional: Pan India Search** — see `PAN_INDIA_DEPLOYMENT.md` for the Telegram worker setup, one-time QR login, required PHP extensions, and what not to commit (session files, downloaded runtime, real API credentials). `cli/telegram_worker.php` is a long-running process (default port 8091) that `api/pan_india.php` talks to over loopback.

## Project structure

```
admin/        Admin panel (agents, imports, settings)
api/          JSON API endpoints
cli/          Command-line scripts (imports, Telegram worker)
config/       Environment config (gitignored except *.example)
database/     Schema + migrations
Gas/          Indian LPG Search / RC Print / HP Gas / Tracing 2.0 / TATA SKY DTH Flask+Selenium service
includes/     Shared PHP includes (auth, header/footer, per-tool clients/archives)
assets/       CSS, images (logo)
```

## Security notes

- `config/db.php`, `config/secrets.php`, `config/telegram.php`, `config/vendor_credentials.php`, `Gas/lpg_web/config.py`, and `.runtime/` are gitignored — they hold real credentials and session state and must never be committed.
- Feature access for every search tool is per-user and off by default for new accounts.
- Tools that run against a shared vendor account (RC Print, HP Gas, Tracing 2.0, TATA SKY DTH, Indane Gas, Advance Pan India, Night Out, Advanced Search) also carry a per-agent monthly search cap, since each search spends real credits/quota on that shared account.
