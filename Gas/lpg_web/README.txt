LPG Search Tool — Server Setup
===============================

This runs centrally on the CRM server itself (found 2026-07-27 — it used to
require a separate install on every agent's own computer; that whole
approach was replaced with this one). Agents just use the "LPG Search" /
"LPG Bulk Search" pages in the CRM directly - no download, no per-computer
setup, nothing to install on their end.

This folder only needs to be set up ONCE, on the server:

  1. Python 3.x and Google Chrome must be installed on this machine.
  2. Double-click "setup_and_run.bat" once to install the required Python
     packages (flask, selenium) and do a one-off manual start to confirm it
     works - watch for "Running on http://127.0.0.1:9196" with no errors,
     then close that window (Ctrl+C).
  3. For the service to run automatically (survive the server restarting,
     recover if it crashes), a copy of "run_lpg_service.bat" needs to be in
     this Windows account's Startup folder (shell:startup) - already set up
     as part of the 2026-07-27 migration; re-run this step only if setting
     up on a NEW server.

How it's wired up: lpg_search.php / lpg_bulk_search.php (the CRM pages
agents actually use) call lpg_search_api.php, which is a small PHP proxy
that forwards to this Flask service over plain loopback HTTP
(127.0.0.1:9196) - server-to-server, never exposed to the browser or the
internet. This is why there's no browser-facing auth check in app.py
itself: PHP's requireLpgSearchAccess() (checked before it ever proxies a
request here) is the only gate that matters.

Troubleshooting:
  - CRM pages show "Could not reach the search service" — this Flask
    service isn't running. Check `netstat -ano | findstr 9196` for a
    LISTENING entry; if missing, run run_lpg_service.bat manually (or
    double-click setup_and_run.bat again) and check its console output for
    errors.
  - Login to SDMS fails — double check the SDMS username/password stored
    inside lpg_search.py (USERNAME / PASSWORD near the top of the file) are
    still correct.
  - Searches queue up behind each other rather than running in parallel —
    expected: every search here shares one Selenium session at a time
    (selenium_lock in app.py), since this is now one shared service for
    every agent rather than one copy per computer.
