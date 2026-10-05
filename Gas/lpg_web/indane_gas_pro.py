from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.common.exceptions import NoSuchElementException, StaleElementReferenceException, TimeoutException

import re
import time

from lpg_search import _create_driver, _quit_driver_with_timeout
from config import CYFUTURE_USERNAME, CYFUTURE_PASSWORD

LOGIN_URL = "https://app.cyfuture.co.in/login.php"
QUERY_FORM_URL = "https://app.cyfuture.co.in/query-form.php"

# IOCL only (explicit choice, 2026-09-02). Radio button id confirmed live
# 2026-09-02 (id="rdbcompany1" = ioc).
COMPANIES = [
    {"label": "IOCL", "radio_id": "rdbcompany1"},
]

# The genuine, side-effect-free lookup on this site (confirmed live
# 2026-09-02, and NOT the same thing as the Query Form's own Submit button):
# the phone field (id="complaint_phone_number", shared across all three
# companies - only "Alternative Phone Number", a separate field, differs by
# company) has a jQuery 'focusout' handler bound to it that fires an AJAX
# call straight to a read-only "load_consumer_details*" endpoint and shows
# the result in a "CONSUMER DATA" modal (#myModal) with a Close button and a
# (separately gated, never clicked here) "Confirm and Proceed" button that
# would carry the data into the real complaint form. This is completely
# different from the Query Form's own big Submit button, which DOES create
# a real complaint/query record every time (confirmed live 2026-08-31 -
# IOCL Complaint #856656229, HPCL Query #418056, BPCL Query #373598 were
# created purely by testing that path) - this module never touches that
# Submit button or the modal's own "Confirm and Proceed" button, only ever
# reads the modal's contents and clicks its plain "Close" button
# (data-dismiss="modal" only, no JS handler of its own - confirmed from the
# page's own source - so it's a pure client-side dismiss with no network
# call).
PHONE_FIELD_ID = "complaint_phone_number"


def _login(driver, wait):
    driver.get(LOGIN_URL)

    user_input = wait.until(
        EC.presence_of_element_located((By.CSS_SELECTOR, "input[placeholder='Username']"))
    )
    user_input.clear()
    user_input.send_keys(CYFUTURE_USERNAME)

    pass_input = driver.find_element(By.CSS_SELECTOR, "input[placeholder='Password']")
    pass_input.clear()
    pass_input.send_keys(CYFUTURE_PASSWORD)

    driver.find_element(By.CSS_SELECTOR, "button, input[type=submit]").click()
    wait.until(EC.presence_of_element_located((By.ID, "rdbcompany1")))


def _parse_consumer_table(m_body_html):
    """The modal's success state renders a <table> of
    <td class="t_hd">Label-</td><td>Value</td> rows (confirmed live
    2026-09-02) rather than the plain 'Label- Value' text this module
    originally assumed - parsed directly off that HTML instead of the
    rendered page text, since the table's actual DOM shape is now known."""
    fields = []
    for m in re.finditer(r'<td class="t_hd">([^<]+)-</td>\s*<td>(.*?)</td>', m_body_html, re.S):
        label = m.group(1).strip()
        value = re.sub(r"<[^>]+>", "", m.group(2)).strip()
        fields.append({"label": label, "value": value})
    return fields


def _try_company(driver, wait, company, mobile_number):
    driver.find_element(By.ID, company["radio_id"]).click()
    # The radio's own onclick="createunique_num(...)" AJAX call re-renders
    # the form while it settles, and (confirmed live 2026-09-02, HPCL
    # specifically) this site sometimes renders a completely different,
    # simpler form for the SAME company - one with no PHONE_FIELD_ID at all
    # and no focusout-triggered lookup handler bound anywhere, only the
    # Query Form's own complaint-creating Submit button. There's no way to
    # tell in advance which variant a given attempt will get. Since the
    # whole point of this module is to NEVER touch that Submit button, a
    # short bounded wait for PHONE_FIELD_ID specifically (rather than the
    # wait.until(...) this used to be, which would hang for the caller's
    # full WebDriverWait timeout) lets a variant without the safe lookup be
    # treated as "this company has nothing to check right now" instead of
    # an error - skipping it is the only safe option, not a fallback to the
    # unsafe path.
    deadline = time.time() + 10
    phone_input = None
    while time.time() < deadline:
        matches = driver.find_elements(By.ID, PHONE_FIELD_ID)
        if matches:
            phone_input = matches[0]
            break
        time.sleep(0.5)
    if phone_input is None:
        return None
    time.sleep(0.3)

    phone_input = driver.find_element(By.ID, PHONE_FIELD_ID)
    phone_input.clear()
    phone_input.send_keys(mobile_number)

    # Triggering the SAME jQuery event the site's own JS listens for (rather
    # than relying on a native blur from clicking elsewhere) fires the exact
    # bound handler directly and reliably.
    driver.execute_script("$(arguments[0]).trigger('focusout');", phone_input)

    # 45s, not 20s (2026-10-03): the site's own lookup slowed to ~30-37s on a
    # busy night, and the old 20s cut-off read the still-loading modal as an
    # empty answer - reporting real consumers as "not found". A lookup that
    # still hasn't finished is now an error (retryable, not cached), never a
    # miss.
    lookup_started = time.time()
    deadline = time.time() + 45
    m_body_html = ""
    settled = False
    while time.time() < deadline:
        m_body_html = driver.execute_script("return document.querySelector('.m_body').innerHTML;") or ""
        loader_visible = driver.execute_script("return $('#loader').is(':visible');")
        if m_body_html.strip() and not loader_visible:
            settled = True
            break
        time.sleep(0.25)

    if not settled:
        driver.execute_script("$('.close_btn').trigger('click');")
        raise RuntimeError("Indane Gas Pro source is responding too slowly - please try again.")

    # Always Close, never "Confirm and Proceed" and never the form's own
    # Submit button - see this module's own top-of-file comment on why. No
    # wait after clicking it - confirmed a pure client-side dismiss (see the
    # module docstring), and the caller either returns immediately with the
    # already-captured m_body_html above or reloads the page fresh on a
    # miss, so nothing downstream depends on the modal's close animation
    # having finished.
    driver.execute_script("$('.close_btn').trigger('click');")

    fields = _parse_consumer_table(m_body_html)
    # The site's own lookup answers "No record Found, Please proceed it
    # manually" BOTH for a real miss and when its upstream (IOCL) source
    # times out - the timeout case only after ~30s (confirmed live
    # 2026-10-03: numbers with records earlier that day came back that way
    # at 30-37s, the same night locateme's Indane source also timed out at
    # 30s). A slow "no record" is reported as a retryable error, not a miss.
    if not fields and time.time() - lookup_started >= 28:
        raise RuntimeError("Indane Gas Pro source (IOCL) is not responding right now - please try again later.")
    return fields


def search_indane_gas_pro(mobile_number):
    """
    Logs into app.cyfuture.co.in and checks a mobile number against IOCL
    using the site's own read-only consumer-lookup modal (never the
    complaint-creating Query Form submission), returning
    {"mobileNumber", "found": True, "fields": [{"label","value"}]} on a hit
    or {"mobileNumber", "found": False} otherwise.
    """
    # Fail at once while config.py still holds the template placeholder
    # (2026-10-03): otherwise every attempt launches Chrome and sits through
    # a ~29s failed login, holding up the CRM's single-request PHP server -
    # and every other agent's search queued behind it - the whole time.
    if not CYFUTURE_USERNAME or CYFUTURE_USERNAME.startswith("REPLACE_WITH"):
        raise RuntimeError("Indane Gas Pro is not set up yet - its Cyfuture login has not been configured.")

    driver = None
    try:
        driver = _create_driver(headless=True)
        wait = WebDriverWait(driver, 20)

        _login(driver, wait)
        # The safe lookup lives on the page the login lands on
        # (emergency_helpline1.php), NOT on query-form.php - confirmed live
        # 2026-10-03 that query-form.php has no lookup field at all, so the
        # retries below reload this page instead.
        lookup_url = driver.current_url

        for company in COMPANIES:
            fields = None
            last_error = None
            for attempt in range(2):
                try:
                    fields = _try_company(driver, wait, company, mobile_number)
                    last_error = None
                    # None = the site served the form variant WITHOUT the safe
                    # lookup field (occasional). Retry once on a fresh load,
                    # then report it as unavailable - never as "not found",
                    # which would tell agents real consumers don't exist.
                    if fields is None and attempt == 0:
                        driver.get(lookup_url)
                        wait.until(EC.presence_of_element_located((By.ID, "rdbcompany1")))
                        continue
                    break
                except (NoSuchElementException, StaleElementReferenceException, TimeoutException):
                    last_error = True
                    driver.get(lookup_url)
                    wait.until(EC.presence_of_element_located((By.ID, "rdbcompany1")))
            if last_error:
                raise RuntimeError(f"Indane Gas Pro: could not load the {company['label']} form after retrying.")
            if fields is None:
                raise RuntimeError("Indane Gas Pro lookup is not available on the Cyfuture site right now - please try again later.")

            if fields:
                return {"mobileNumber": mobile_number, "found": True, "fields": fields}

            driver.get(lookup_url)
            wait.until(EC.presence_of_element_located((By.ID, "rdbcompany1")))

        return {"mobileNumber": mobile_number, "found": False}

    finally:
        if driver is not None:
            _quit_driver_with_timeout(driver)
