from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait, Select
from selenium.webdriver.support import expected_conditions as EC
from selenium.common.exceptions import TimeoutException

import re
import time

# Same Chrome setup as lpg_search.py/rc_print.py/hp_gas.py - see
# lpg_search.py's _create_driver() comment for why the anti-detection flags
# are needed (confirmed 2026-08-11: mysso.tataplay.com's WAF rejects a plain
# curl/requests GET outright with an F5-style "Request Rejected" page, but
# this same Chrome configuration gets through to the real login form).
from lpg_search import _create_driver, _quit_driver_with_timeout
from config import TATAPLAY_USERNAME, TATAPLAY_PASSWORD

SSO_URL = "https://mysso.tataplay.com/"

# Siebel's own quick-find result count line ("1 - 1 of 1", "1 - 13 of 13+",
# ...) - the ONLY reliable signal that a search actually matched a single
# real account, confirmed 2026-08-11: searching an unregistered mobile
# number doesn't return zero rows, it silently falls back to a list of
# unrelated recently-accessed accounts (also with a valid-looking "1 - N of
# N" line) instead of erroring. Treating anything other than exactly one
# result as "not found" is the only way to avoid handing back the wrong
# customer's data.
RESULT_COUNT_RE = re.compile(r"^\s*(\d+)\s*-\s*(\d+)\s*of\s*(\d+)\+?\s*$", re.MULTILINE)
FIELD_RE = re.compile(r"^(Account|Subscriber Id|Account Status)\s*:\s*(.*)$")


def _login(driver, wait):
    driver.get(SSO_URL)
    wait.until(lambda d: d.execute_script("return document.readyState") == "complete")

    driver.find_element(By.ID, "username").send_keys(TATAPLAY_USERNAME)
    driver.find_element(By.ID, "password").send_keys(TATAPLAY_PASSWORD)
    driver.find_element(By.CSS_SELECTOR, "input[type='submit']").click()
    wait.until(lambda d: d.execute_script("return document.readyState") == "complete")

    # The Siebel PRM app opens in a new tab/window via a plain
    # window.open(...) onclick handler, not a normal href - .click() still
    # fires that handler, so this needs a window-handle switch afterward
    # rather than following an href directly.
    original_handles = driver.window_handles
    # Confirmed intermittent (2026-08-18, search_type='tataplay' 502s in
    # service_error.log): most logins find this link within the normal 20s
    # wait, but a few timed out here even though the surrounding logins
    # (same credentials, same portal, minutes apart) succeeded - looks like
    # occasional slowness on the portal's side rather than a DOM/selector
    # change, so one retry (a fresh 20s window) rather than failing the
    # whole search on the first timeout.
    try:
        prm_link = wait.until(EC.presence_of_element_located((By.XPATH, "//a[contains(.,'Siebel PRM')]")))
    except TimeoutException:
        prm_link = wait.until(EC.presence_of_element_located((By.XPATH, "//a[contains(.,'Siebel PRM')]")))
    prm_link.click()

    wait.until(lambda d: len(d.window_handles) > len(original_handles))
    new_handle = [h for h in driver.window_handles if h not in original_handles][0]
    driver.switch_to.window(new_handle)
    wait.until(lambda d: d.execute_script("return document.readyState") == "complete")


def _select_account_find(driver, wait):
    # The "Find" toolbar dropdown's "Account" option is indented with
    # non-breaking spaces (\xa0) in the real DOM, not plain ones - XPath's
    # normalize-space() only collapses ASCII whitespace and doesn't strip
    # those, so it never matches (confirmed 2026-08-11: a normalize-space()
    # XPath timed out here). Python's str.strip() does strip \xa0, so the
    # dropdown is located by checking each <select>'s options in Python
    # instead of trying to match the option text via XPath.
    wait.until(EC.presence_of_element_located((By.TAG_NAME, "select")))
    find_select, account_option = None, None
    for s in driver.find_elements(By.TAG_NAME, "select"):
        for o in s.find_elements(By.TAG_NAME, "option"):
            if o.text.strip() == "Account":
                find_select, account_option = s, o.text
                break
        if find_select:
            break
    if find_select is None:
        raise RuntimeError("Could not find the Find dropdown's Account option.")
    Select(find_select).select_by_visible_text(account_option)

    # field_textbox_1 = "Mobile Phone #" (field_textbox_0 is Subscriber Id,
    # 2-5 are Contact #1-4) - confirmed 2026-08-11 from the real form's
    # title attributes, which is more stable to key off than the
    # auto-generated ids alone.
    return wait.until(EC.visibility_of_element_located(
        (By.CSS_SELECTOR, "input[title='Mobile Phone #']")
    ))


def _parse_single_result(body_text):
    count_match = RESULT_COUNT_RE.search(body_text)
    if not count_match or count_match.group(1) != count_match.group(2) or count_match.group(2) != "1":
        return None

    fields = {}
    for line in body_text.splitlines():
        m = FIELD_RE.match(line.strip())
        if m:
            fields[m.group(1)] = m.group(2).strip()

    if not fields:
        return None
    return fields


# Field labels on the account DETAIL form (reached by drilling into the one
# quick-find result) that make up a postal address - confirmed 2026-08-16
# from a real account. Read via aria-label rather than title/name: these
# inputs don't expose a title attribute the way the quick-find's own search
# fields do.
ADDRESS_ARIA_LABELS = ["Address Line 1", "Address Line 2", "Village/Town/City", "District", "State", "Pin Code"]


def _open_account_detail_and_get_address(driver, wait):
    """
    Drills into the single quick-find result (a Siebel "drilldown" link that
    Selenium considers not-interactable via a normal .click() - it's styled
    TSLDisplayNone - so this fires the click via JS instead) and reads the
    account detail form's address fields. Returns a formatted address string,
    or "" if the detail page didn't load the expected fields in time.
    """
    link = wait.until(EC.presence_of_element_located((By.XPATH, "//a[@name='Title']")))
    driver.execute_script("arguments[0].click();", link)

    wait.until(lambda d: d.execute_script("return document.readyState") == "complete")

    deadline = time.time() + 15
    values = {}
    while time.time() < deadline:
        for label in ADDRESS_ARIA_LABELS:
            if label in values:
                continue
            els = driver.find_elements(By.CSS_SELECTOR, f"input[aria-label='{label}']")
            if els:
                values[label] = els[0].get_attribute("value") or ""
        if len(values) == len(ADDRESS_ARIA_LABELS):
            break
        time.sleep(0.5)

    parts = [values.get(label, "").strip() for label in ADDRESS_ARIA_LABELS]
    return ", ".join(p for p in parts if p)


# jqGrid renders each grid as a HEADER <table class="ui-jqgrid-htable"> right
# next to a BODY <table class="ui-jqgrid-btable"> (confirmed 2026-08-16) -
# the header row's own cells (after a leading blank checkbox-column cell)
# give the column order to read the body rows by, rather than hardcoding
# column positions that could shift if Siebel reorders/adds columns.
TRANSACTION_DATE_RE = re.compile(r"(\d{2})/(\d{2})/(\d{4})\s+(\d{2}):(\d{2}):(\d{2})\s*(AM|PM)", re.IGNORECASE)


def _parse_transaction_date(raw):
    m = TRANSACTION_DATE_RE.search(raw or "")
    if not m:
        return None
    day, month, year, hour, minute, second, ampm = m.groups()
    hour = int(hour) % 12
    if ampm.upper() == "PM":
        hour += 12
    try:
        from datetime import datetime
        return datetime(int(year), int(month), int(day), hour, int(minute), int(second))
    except ValueError:
        return None


def _get_last_recharge_date(driver, wait):
    """
    Switches to the account detail's Billing Portal tab and reads its
    Transaction History grid, returning the raw "Transaction Date" string of
    the most recent row whose Type contains "recharge" (case-insensitive),
    or "" if the tab/grid didn't load, has no rows, or has no recharge-type
    row - same best-effort contract as _open_account_detail_and_get_address().
    """
    try:
        billing_tab = wait.until(EC.presence_of_element_located(
            (By.XPATH, "//*[normalize-space(text())='Billing Portal']")
        ))
        driver.execute_script("arguments[0].click();", billing_tab)
        wait.until(lambda d: d.execute_script("return document.readyState") == "complete")
        time.sleep(2)  # jqGrid re-renders client-side after the tab switch

        header_table = None
        for t in driver.find_elements(By.CSS_SELECTOR, "table.ui-jqgrid-htable"):
            if "Transaction Date" in t.text and "Type" in t.text:
                header_table = t
                break
        if header_table is None:
            return ""

        header_cells = [c.text.strip() for c in header_table.find_elements(By.TAG_NAME, "th")] \
            or [c.text.strip() for c in header_table.find_elements(By.TAG_NAME, "td")]
        if "Transaction Date" not in header_cells or "Type" not in header_cells:
            return ""
        date_col = header_cells.index("Transaction Date")
        type_col = header_cells.index("Type")

        # The paired body grid is the very next .ui-jqgrid-btable in DOM
        # order after this header grid (there's one header/body pair per
        # applet on the page, e.g. the search-results grid above this one).
        all_header_tables = driver.find_elements(By.CSS_SELECTOR, "table.ui-jqgrid-htable")
        all_body_tables = driver.find_elements(By.CSS_SELECTOR, "table.ui-jqgrid-btable")
        header_index = all_header_tables.index(header_table)
        if header_index >= len(all_body_tables):
            return ""
        body_table = all_body_tables[header_index]

        best_date = None
        best_raw = ""
        for row in body_table.find_elements(By.TAG_NAME, "tr"):
            cells = row.find_elements(By.TAG_NAME, "td")
            if len(cells) <= max(date_col, type_col):
                continue
            # A real active account's Transaction History (confirmed
            # 2026-08-16) never actually uses the word "Recharge" as a Type
            # value - "Payments" is what an EVD/recharge transaction is typed
            # as here; "NRC" (Non-Recurring Charge - activation fee, rental,
            # etc.) is a one-time fee, not a recharge, so it's excluded even
            # though it's also money changing hands.
            type_value = cells[type_col].text.strip().lower()
            if "payment" not in type_value and "recharge" not in type_value:
                continue
            raw_date = cells[date_col].text.strip()
            parsed = _parse_transaction_date(raw_date)
            if parsed and (best_date is None or parsed > best_date):
                best_date, best_raw = parsed, raw_date

        return best_raw
    except Exception:
        return ""


def run_tataplay_single(mobile_number):
    """
    Logs into the Tata Play distributor SSO portal and runs a single Account
    quick-find by mobile number, returning
    {"mobileNumber", "found", "accountName", "subscriberId", "accountStatus", "address", "lastRechargeDate"}
    on an unambiguous single-account match, or {"mobileNumber", "found": False}
    otherwise (no match, or an ambiguous multi-result fallback list - see
    RESULT_COUNT_RE's comment). "address"/"lastRechargeDate" are best-effort -
    each is its own extra page/tab load that can fail independently of the
    search itself, so a found account with either one unreadable still comes
    back as found with that field "" rather than failing the whole search.
    """

    driver = None
    try:
        driver = _create_driver(headless=True)
        wait = WebDriverWait(driver, 20)

        _login(driver, wait)

        mobile_field = _select_account_find(driver, wait)
        mobile_field.send_keys(mobile_number)

        find_button = driver.find_element(By.XPATH, "//button[@title='Find']")
        find_button.click()
        wait.until(lambda d: d.execute_script("return document.readyState") == "complete")
        time.sleep(2)  # the results list re-renders client-side after the page "load" fires

        body_text = driver.find_element(By.TAG_NAME, "body").text
        fields = _parse_single_result(body_text)

        if not fields:
            return {"mobileNumber": mobile_number, "found": False}

        try:
            address = _open_account_detail_and_get_address(driver, wait)
        except Exception:
            address = ""

        last_recharge_date = _get_last_recharge_date(driver, wait)

        return {
            "mobileNumber": mobile_number,
            "found": True,
            "accountName": fields.get("Account", ""),
            "subscriberId": fields.get("Subscriber Id", ""),
            "accountStatus": fields.get("Account Status", ""),
            "address": address,
            "lastRechargeDate": last_recharge_date,
        }

    finally:
        if driver is not None:
            _quit_driver_with_timeout(driver)
