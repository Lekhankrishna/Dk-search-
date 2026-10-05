from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait, Select
from selenium.webdriver.support import expected_conditions as EC
from selenium.common.exceptions import TimeoutException, StaleElementReferenceException

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

# Siebel's own quick-find result count line ("1 - 1 of 1", "1 - 2 of 2",
# "1 - 13 of 13+", ...) - the ONLY reliable signal that a search actually
# matched something, confirmed 2026-08-11: searching an unregistered mobile
# number doesn't return zero rows, it silently falls back to a list of
# unrelated recently-accessed accounts (also with a valid-looking "1 - N of
# N" line) instead of erroring.
RESULT_COUNT_RE = re.compile(r"^\s*(\d+)\s*-\s*(\d+)\s*of\s*(\d+)\+?\s*$", re.MULTILINE)

# A real customer can genuinely have more than one account record against
# the same mobile number (confirmed live 2026-08-19, searching 9884121267:
# "1 - 2 of 2", two accounts for the same person - a deactivated one and a
# pending one), so an exact-1 requirement was too strict and showed "not
# found" for a real, findable customer. But the fallback "recently
# accessed" list above is also real (confirmed 2026-08-11) and needs to
# stay rejected - it's just not been observed to be this small, so a low
# cap distinguishes "a person with a couple of account records" from "the
# generic recently-viewed list" without needing to open every row's own
# detail page just to check whether it's even related to the searched
# number (which _parse_all_results() doesn't have signal for from the
# results grid alone).
MAX_PLAUSIBLE_MATCHES = 5
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

    # A <select> tag being present doesn't mean its options are populated
    # yet - confirmed live 2026-08-19: the toolbar's dropdown can render
    # empty for a moment before the "Account" option (and its siblings)
    # get filled in, so a single immediate scan right after the tag itself
    # appears can miss it. Re-scanning for up to 10s (same intermittent-
    # slowness reasoning as _login()'s Siebel PRM link retry) instead of
    # failing the whole search on the first empty pass.
    find_select, account_option = None, None
    deadline = time.time() + 10
    while find_select is None and time.time() < deadline:
        for s in driver.find_elements(By.TAG_NAME, "select"):
            for o in s.find_elements(By.TAG_NAME, "option"):
                if o.text.strip() == "Account":
                    find_select, account_option = s, o.text
                    break
            if find_select:
                break
        if find_select is None:
            time.sleep(0.5)
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


def _parse_all_results(body_text):
    """
    Returns a list of {"Account", "Subscriber Id", "Account Status"} dicts,
    one per matched row in the quick-find results grid - [] if there's no
    valid result-count line, or if the count is 0 or implausibly large (see
    MAX_PLAUSIBLE_MATCHES). The three fields always appear in that fixed
    order per row (confirmed live 2026-08-19), so a new dict starts every
    time "Account Status" closes one out.
    """
    count_match = RESULT_COUNT_RE.search(body_text)
    if not count_match:
        return []
    total = int(count_match.group(3).rstrip("+")) if count_match.group(3).rstrip("+").isdigit() else None
    if total == 0 or (total is not None and total > MAX_PLAUSIBLE_MATCHES):
        return []

    results = []
    current = {}
    for line in body_text.splitlines():
        m = FIELD_RE.match(line.strip())
        if m:
            current[m.group(1)] = m.group(2).strip()
            if m.group(1) == "Account Status":
                results.append(current)
                current = {}

    return results


# Field labels on the account DETAIL form (reached by drilling into a
# quick-find result) covering everything under the real portal's own
# "Subscriber Details" and "Address" headings - confirmed live 2026-08-19
# from a real account's aria-labeled inputs (a superset of the original
# 2026-08-16 address-only set: Town/Tahsil were missing from that one).
# Read via aria-label rather than title/name: these inputs don't expose a
# title attribute the way the quick-find's own search fields do.
DETAIL_ARIA_LABELS = [
    "Account Type", "Account Category", "Account Sub-Category", "Sales Segment",
    "Address Line 1", "Address Line 2", "Village/Town/City", "Town", "District", "Tahsil", "State", "Pin Code",
]


def _open_nth_result_detail(driver, wait, index):
    """
    Drills into the (0-based) index'th row of the current quick-find
    results grid - a Siebel "drilldown" link Selenium considers not-
    interactable via a normal .click() (it's styled TSLDisplayNone), so
    this fires the click via JS instead. Row order is stable across a
    driver.back() back to the same results grid (confirmed live
    2026-08-19), which is what makes visiting every row of a multi-match
    result - back to the list, into the next row - workable at all.
    """
    links = wait.until(EC.presence_of_all_elements_located((By.XPATH, "//a[@name='Title']")))
    driver.execute_script("arguments[0].click();", links[index])
    wait.until(lambda d: d.execute_script("return document.readyState") == "complete")


def _read_jqgrid_first_row(driver, header_must_contain):
    """
    Finds the jqGrid whose header text contains every string in
    header_must_contain and returns its first body row as a
    {column_label: value} dict (used for the Digicard/Asset grid, which -
    unlike Billing Portal's Transaction History - only ever has the one
    active box to read, not a history to scan for the latest of some
    type). Returns {} if no matching header, or that grid has no body rows.

    The matching header's own body table is found via the "next
    ui-jqgrid-btable following this header in document order" XPath rather
    than _get_last_recharge_date()'s positional all_header_tables[i] <->
    all_body_tables[i] pairing - confirmed live 2026-08-19 that an account
    detail page can carry a second, hidden jqGrid left over from the
    search-results grid used to drill into it (a "Title"-only header with
    no body table of its own at all), which throws that positional pairing
    off by one for every real grid after it.
    """
    header_table = None
    for t in driver.find_elements(By.CSS_SELECTOR, "table.ui-jqgrid-htable"):
        if all(h in t.text for h in header_must_contain):
            header_table = t
            break
    if header_table is None:
        return {}

    header_cells = [c.text.strip() for c in header_table.find_elements(By.TAG_NAME, "th")] \
        or [c.text.strip() for c in header_table.find_elements(By.TAG_NAME, "td")]

    body_tables = header_table.find_elements(By.XPATH, "following::table[contains(@class,'ui-jqgrid-btable')][1]")
    if not body_tables:
        return {}
    body_table = body_tables[0]

    for row in body_table.find_elements(By.TAG_NAME, "tr"):
        cells = row.find_elements(By.TAG_NAME, "td")
        if len(cells) < len(header_cells):
            continue
        return {header_cells[i]: cells[i].text.strip() for i in range(len(header_cells)) if header_cells[i]}
    return {}


def _extract_account_details(driver, wait):
    """
    Reads the currently-open account detail page's Subscriber Details/
    Address fields (DETAIL_ARIA_LABELS) and its Digicard/Asset grid row,
    plus a best-effort Last Recharge Date from the Billing Portal tab (see
    _get_last_recharge_date()). Each group is read independently and best-
    effort - an exception or missing field in one doesn't block the
    others, same "" -on-failure contract the single-account path always
    had for address/lastRechargeDate.
    """
    details = {}

    # Re-reads every field on every pass rather than caching the first
    # non-empty value seen per label - confirmed live 2026-08-19, drilling
    # into a second account right after the first: these aria-labeled
    # inputs don't get replaced (no StaleElementReferenceException), their
    # VALUE attribute just updates asynchronously a moment after
    # navigation completes, so an early pass can read the PREVIOUS
    # account's still-lingering values and (with a cache-on-first-find
    # loop) lock them in permanently. Two consecutive identical passes is
    # treated as "stopped changing, safe to use"; each individual
    # find_elements() call still tolerates a StaleElementReferenceException
    # (this page can also genuinely swap nodes out from under a read mid-
    # poll, same failure mode already handled in tracing2_tools.py's own
    # polling loop) without aborting the whole pass. Kept short (2026-08-19,
    # per explicit "taking too much time" feedback, was 15s) - these fields
    # are consistently readable within the first couple passes in every
    # live test so far; a long ceiling here was only ever paying for a
    # worst case that hasn't actually been observed.
    deadline = time.time() + 6
    values, previous = {}, None
    while time.time() < deadline:
        current = {}
        try:
            for label in DETAIL_ARIA_LABELS:
                els = driver.find_elements(By.CSS_SELECTOR, f"input[aria-label='{label}']")
                if els:
                    current[label] = (els[0].get_attribute("value") or "").strip()
        except StaleElementReferenceException:
            pass
        values = current
        if current and current == previous:
            break
        previous = current
        time.sleep(0.5)

    details["accountType"] = values.get("Account Type", "")
    details["accountCategory"] = values.get("Account Category", "")
    details["accountSubCategory"] = values.get("Account Sub-Category", "")
    details["salesSegment"] = values.get("Sales Segment", "")
    details["addressLine1"] = values.get("Address Line 1", "")
    details["addressLine2"] = values.get("Address Line 2", "")
    details["villageTownCity"] = values.get("Village/Town/City", "")
    details["town"] = values.get("Town", "")
    details["district"] = values.get("District", "")
    details["tahsil"] = values.get("Tahsil", "")
    details["state"] = values.get("State", "")
    details["pinCode"] = values.get("Pin Code", "")

    # The Digicard/Asset grid is present on page load without needing a
    # tab click (unlike Billing Portal - confirmed live 2026-08-19 that
    # clicking the visible "Assets" tab instead swaps in a completely
    # different, broader grid with its own column set, not this one), but
    # it can render asynchronously a moment after the aria-labeled fields
    # above are already readable - and confirmed live, the header row can
    # be present with every cell still blank a moment before the real
    # values land, so a match needs at least one non-empty value, not just
    # a header/row match, before it's trusted. Same stability-across-two-
    # passes reasoning as the aria fields above once it does have data.
    # Kept short (2026-08-19, per explicit "taking too much time" feedback,
    # was 15s) - this grid's timing is inherently unreliable (sometimes
    # ready in seconds, sometimes not within 15s+ even), so a long ceiling
    # here was mostly just paying search latency for very little extra hit
    # rate; failing fast to "" is the better trade now that speed matters
    # more than squeezing out an occasional extra hit on this one section.
    digicard, previous = {}, None
    deadline = time.time() + 5
    while time.time() < deadline:
        try:
            candidate = _read_jqgrid_first_row(driver, ["Product", "Digicard #"])
        except StaleElementReferenceException:
            candidate = {}
        if not any(candidate.values()):
            candidate = {}
        digicard = candidate
        if digicard and digicard == previous:
            break
        previous = digicard
        time.sleep(0.5)
    details["digicardProduct"] = digicard.get("Product", "")
    details["digicardNumber"] = digicard.get("Digicard #", "")
    details["digicompNumber"] = digicard.get("Digicomp #", "")
    details["digicardType"] = digicard.get("Digicard Type", "")
    details["digicardStatus"] = digicard.get("Status", "")
    details["digicardEffectiveStartDate"] = digicard.get("Effective Start Date", "")
    details["digicompSerialNumber"] = digicard.get("DigiComp Mfg. Serial Number", "")
    details["assetType"] = digicard.get("Asset Type", "")

    try:
        details["lastRechargeDate"] = _get_last_recharge_date(driver, wait)
    except Exception:
        details["lastRechargeDate"] = ""
    return details


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
            # though it's also money changing hands. Confirmed live
            # 2026-08-23: a Deactivated/Pending account's grid renders one
            # entirely empty placeholder row (jqGrid's own "no data" shape,
            # not a scraping failure) rather than zero rows - the empty
            # type/date on it never matches "payment"/"recharge" so it's
            # correctly skipped, same as it would be for a real account with
            # genuinely no transaction history yet.
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
    quick-find by mobile number, returning {"mobileNumber", "found",
    "accounts": [{"accountName", "subscriberId", "accountStatus",
    "accountType", "accountCategory", "accountSubCategory", "salesSegment",
    "addressLine1", "addressLine2", "villageTownCity", "town", "district",
    "tahsil", "state", "pinCode", "digicardProduct", "digicardNumber",
    "digicompNumber", "digicardType", "digicardStatus",
    "digicardEffectiveStartDate", "digicompSerialNumber", "assetType",
    "lastRechargeDate"}, ...]} on a match (one entry per matched account -
    see _parse_all_results()' own comment on why a search can genuinely
    match more than one), or {"mobileNumber", "found": False} otherwise (no
    match, or an implausibly large result count - see
    MAX_PLAUSIBLE_MATCHES). Every field past accountStatus is best-effort
    (see _extract_account_details()) - drilling into one account, reading
    it, and going back to the list (confirmed live 2026-08-19 that
    driver.back() lands cleanly back on the same results grid, same row
    order) to drill into the next happens for every matched account, not
    just a single unambiguous one.
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
        rows = _parse_all_results(body_text)

        if not rows:
            return {"mobileNumber": mobile_number, "found": False}

        accounts = []
        for i, fields in enumerate(rows):
            account = {
                "accountName": fields.get("Account", ""),
                "subscriberId": fields.get("Subscriber Id", ""),
                "accountStatus": fields.get("Account Status", ""),
            }
            try:
                _open_nth_result_detail(driver, wait, i)
                # A flat pre-poll settle sleep was tried here (2026-08-19)
                # to give the Digicard grid's async load a head start -
                # removed after confirming live it didn't meaningfully
                # improve the hit rate, so it was just adding latency for
                # no real benefit (per explicit "taking too much time"
                # feedback). _extract_account_details() still polls for
                # each field on its own.
                account.update(_extract_account_details(driver, wait))
            except Exception:
                pass  # best-effort - keep whatever list-level fields we already have
            if i < len(rows) - 1:
                try:
                    driver.back()
                    wait.until(lambda d: d.execute_script("return document.readyState") == "complete")
                    time.sleep(1)
                except Exception:
                    pass
            accounts.append(account)

        return {"mobileNumber": mobile_number, "found": True, "accounts": accounts}

    finally:
        if driver is not None:
            _quit_driver_with_timeout(driver)
