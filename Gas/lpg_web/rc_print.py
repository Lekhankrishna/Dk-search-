from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

import queue
import sys
import time

# Reuses lpg_search.py's Chrome setup (headless + the anti-detection flags
# tuned for SDMS's bot-blocking) rather than duplicating it - locateme.services
# is a similar target (a login-gated site actively used by real staff, not a
# throwaway scrape), so the same hardened driver setup is the safer default
# here too rather than starting from a plain unconfigured Chrome instance.
from lpg_search import _create_driver, _quit_driver_with_timeout, _type_and_submit

# =========================================================
# LOGIN DETAILS - locateme.services (Firebase email/password login).
# Real values live in config.py (gitignored, see config.py.example) - found
# hardcoded here and already committed to git history; moved out rather
# than left in place going forward, same as lpg_search.py's SDMS
# USERNAME/PASSWORD. hp_gas.py reuses this same login (imports _login
# directly from this file) rather than duplicating it.
# =========================================================

from config import LOCATEME_EMAIL as EMAIL, LOCATEME_PASSWORD as PASSWORD

LOGIN_URL = "https://locateme.services/login"
RC_PRINT_URL = "https://locateme.services/tools/rc-print"

# Session-cookie caching was tried and reverted 2026-08-08: this site's
# auth is Firebase's indexedDBLocalPersistence (the session lives in an
# IndexedDB database named "firebaseLocalStorageDb", not a cookie), and
# copying that between Selenium sessions would mean depending on Firebase's
# internal schema.
#
# Persistent Chrome profiles don't help either (tested 2026-10-03): the
# session is gone as soon as the browser closes. What does work is keeping
# a few already-logged-in browsers OPEN between searches - confirmed live
# that several browsers can stay signed in to the same account at once, and
# that an idle one is still signed in a minute later. Each search borrows an
# idle warm browser (skipping ~1-3s start-up, ~8s login and ~2s shutdown)
# or starts a fresh one when none is free; afterwards it's put back for the
# next search, up to _MAX_IDLE kept open. A browser whose search raised an
# error is closed rather than reused, since its page state is unknown.
_MAX_IDLE = 3
_idle_drivers = queue.LifoQueue()


def _create_locateme_driver():
    """A headless locateme.services browser - a warm, already-logged-in one
    when available. Always hand it back via _release_locateme_driver()."""
    while True:
        try:
            driver = _idle_drivers.get_nowait()
        except queue.Empty:
            return _create_driver(headless=True)
        try:
            driver.current_url  # still alive?
            return driver
        except Exception:
            _quit_driver_with_timeout(driver)


def _release_locateme_driver(driver):
    # sys.exc_info() is set here when called from a `finally` while an
    # exception is propagating - don't recycle a browser after a failure.
    failed = sys.exc_info()[0] is not None
    if not failed and _idle_drivers.qsize() < _MAX_IDLE:
        try:
            driver.get("about:blank")
            _idle_drivers.put(driver)
            return
        except Exception:
            pass
    _quit_driver_with_timeout(driver)


def _login(driver, wait):

    driver.get(LOGIN_URL)

    # A warm browser that's still signed in never shows the form - the site
    # redirects it straight on to /dashboard. Whichever happens first
    # decides: redirect = already logged in, form = log in now.
    deadline = time.time() + 20
    email_input = None
    while time.time() < deadline:
        if "/dashboard" in driver.current_url:
            return
        found = driver.find_elements(By.CSS_SELECTOR, "input[type='email']")
        if found:
            email_input = found[0]
            break
        time.sleep(0.25)
    if email_input is None:
        raise RuntimeError("locateme.services login page never loaded")
    email_input.clear()
    email_input.send_keys(EMAIL)

    password_input = driver.find_element(By.CSS_SELECTOR, "input[type='password']")
    password_input.clear()
    password_input.send_keys(PASSWORD)

    submit_button = driver.find_element(By.CSS_SELECTOR, "button[type='submit']")
    driver.execute_script("arguments[0].click();", submit_button)

    # A successful login redirects to /dashboard. A failed one (wrong
    # password, or this machine's IP not matching the account's registered
    # IP) leaves the URL unchanged and shows a toast error instead - polling
    # for either, rather than one fixed WebDriverWait on the URL alone, is
    # what lets a real failure surface with its actual message instead of a
    # bare, unhelpful TimeoutException.
    deadline = time.time() + 15
    while time.time() < deadline:
        if "/dashboard" in driver.current_url:
            return
        page_text = driver.find_element(By.TAG_NAME, "body").text.lower()
        for needle in ("access restricted", "node disabled", "invalid email", "incorrect", "authentication error"):
            if needle in page_text:
                raise RuntimeError(f"locateme.services login failed: {needle}")
        time.sleep(0.5)

    raise RuntimeError("locateme.services login timed out - never reached /dashboard")


def run_rc_print(vehicle_number):
    """
    Logs into locateme.services and runs the RC Print tool for one vehicle
    number, returning {"vehicleNumber": ..., "pdfDataUri": ...}.

    The site generates the PDF via its own "generateRcPrint" server action
    and renders it straight into
    <iframe title="RC PDF Preview" src="data:application/pdf;base64,...">
    on their own page (confirmed 2026-08-08 from that page's JS bundle) -
    this just reads that same src attribute back out instead of trying to
    replicate their server action call directly, which is tied to a
    build-specific action-ID hash that would silently break on their next
    deploy.
    """

    driver = None
    try:
        driver = _create_locateme_driver()
        wait = WebDriverWait(driver, 20)

        _login(driver, wait)

        driver.get(RC_PRINT_URL)

        number_input = wait.until(
            EC.presence_of_element_located((By.CSS_SELECTOR, "input[placeholder='ENTER VEHICLE NUMBER']"))
        )
        _type_and_submit(number_input, vehicle_number)

        # PDF generation (their server action) isn't instant - poll for
        # either the result iframe or an error message rather than one long
        # WebDriverWait, same reasoning as _login()'s poll above.
        deadline = time.time() + 45
        while time.time() < deadline:
            iframes = driver.find_elements(By.CSS_SELECTOR, "iframe[title='RC PDF Preview']")
            if iframes:
                pdf_src = iframes[0].get_attribute("src") or ""
                pdf_data_uri = pdf_src.split("#", 1)[0]
                return {"vehicleNumber": vehicle_number, "pdfDataUri": pdf_data_uri}

            page_text = driver.find_element(By.TAG_NAME, "body").text.lower()
            for needle in ("insufficient credit", "not found", "invalid vehicle", "no record"):
                if needle in page_text:
                    raise RuntimeError(f"RC Print failed for {vehicle_number}: {needle}")

            time.sleep(1)

        raise RuntimeError(f"RC Print timed out waiting for a result for {vehicle_number}")

    finally:
        if driver is not None:
            _release_locateme_driver(driver)
