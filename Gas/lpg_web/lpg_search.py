from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait, Select
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.common.keys import Keys
from selenium.common.exceptions import WebDriverException

import datetime
import re
import threading
import time

# =========================================================
# LOGIN DETAILS
# =========================================================
# Real values live in config.py (gitignored, see config.py.example) - found
# hardcoded here and already committed to git history 2026-08-07; moved out
# rather than left in place going forward.

from config import USERNAME, PASSWORD

# Re-used by both _prepare_phone_search (first location) and
# _search_one_number (every subsequent search) - see the comment in
# _search_one_number for why it has to be re-located every time rather than
# reusing one WebElement handle for the whole batch.
MOBILE_INPUT_XPATH = "/html/body/div[1]/div/div[6]/div/div[7]/div/div[1]/div/div[1]/div/form/span/div/div[2]/div[2]/input[2]"


def _create_driver(headless=False):

    options = webdriver.ChromeOptions()

    options.page_load_strategy = "eager"

    options.add_argument("--start-maximized")
    options.add_argument("--disable-notifications")
    options.add_argument("--disable-gpu")
    options.add_argument("--no-sandbox")
    options.add_argument("--disable-dev-shm-usage")

    # Bulk search never needs a visible window — the data is pulled out
    # programmatically, not read off the screen — and this server is reached
    # by remote CRM users too, for whom a window popping up here is useless
    # at best.
    if headless:
        options.add_argument("--headless=new")
        options.add_argument("--window-size=1920,1080")
        # SDMS actively detects and blocks plain headless Chrome — navigation
        # "succeeds" (no exception, no crash) but the page title comes back
        # as "The URL you requested has been blocked", so the login form
        # never appears and every wait.until() in _login() times out with an
        # opaque, message-less TimeoutException (found 2026-07-21, after
        # switching run_bulk_search to headless=True broke bulk search
        # entirely). These flags mask the most common automation fingerprints
        # (navigator.webdriver, the "Chrome is being controlled by automated
        # test software" infobar, a generic default user-agent) — confirmed
        # this alone is enough for SDMS to serve the real page again.
        options.add_argument("--disable-blink-features=AutomationControlled")
        options.add_experimental_option("excludeSwitches", ["enable-automation"])
        options.add_experimental_option("useAutomationExtension", False)
        options.add_argument(
            "user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
            "(KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36"
        )

    prefs = {
        "profile.managed_default_content_settings.images": 2
    }

    options.add_experimental_option("prefs", prefs)

    driver = webdriver.Chrome(options=options)

    if headless:
        driver.execute_cdp_cmd("Page.addScriptToEvaluateOnNewDocument", {
            "source": "Object.defineProperty(navigator, 'webdriver', {get: () => undefined})"
        })

    return driver


def _login(driver, wait):

    driver.get(
        "https://sdms.ex.indianoil.in/siebel/app/htim/enu?SWECmd=Start"
    )

    username = wait.until(
        EC.presence_of_element_located(
            (By.XPATH, "/html/body/form/div/div[2]/div[1]//input")
        )
    )

    username.clear()
    username.send_keys(USERNAME)

    password = wait.until(
        EC.presence_of_element_located(
            (By.XPATH, "/html/body/form/div/div[2]/div[2]//input")
        )
    )

    password.clear()
    password.send_keys(PASSWORD)

    login_button = wait.until(
        EC.presence_of_element_located(
            (By.XPATH, "/html/body/form/div/div[2]/div[4]/a")
        )
    )

    driver.execute_script("arguments[0].click();", login_button)

    time.sleep(5)


def _open_contacts(driver, wait):

    contacts_xpath = "/html/body/div[1]/div/div[4]/div/div/div[1]/div[1]/ul/li[4]/a"

    # A short, dedicated wait for each retry attempt - the outer 10-attempt
    # loop already provides the retrying, so each attempt reusing the full
    # 20s `wait` here made the worst case 10 * (20s + 2s) = 220s (found
    # 2026-07-24: two jobs hung for 90s+ with zero output once this got
    # called for EVERY number in a batch, not just once per whole batch).
    short_wait = WebDriverWait(driver, 3)

    for i in range(10):

        try:

            contacts_button = short_wait.until(
                EC.presence_of_element_located((By.XPATH, contacts_xpath))
            )

            driver.execute_script("arguments[0].click();", contacts_button)
            print(f"[field-debug] _open_contacts: clicked on attempt {i + 1}", flush=True)

            break

        except Exception as e:

            print(f"[field-debug] _open_contacts: attempt {i + 1} failed: {e}", flush=True)
            time.sleep(2)

    # Trimmed from 3s (found 2026-07-28, as part of speeding up bulk search -
    # this was flat padding with no documented reason for the specific
    # duration, unlike the adaptive wait.until() calls elsewhere in this
    # file). Revert toward 3s if this proves too tight in practice.
    time.sleep(1.5)


def _prepare_phone_search(driver, wait):

    print("[field-debug] _prepare_phone_search: waiting for dropdown", flush=True)

    main_dropdown = wait.until(
        EC.presence_of_element_located(
            (
                By.XPATH,
                "/html/body/div[1]/div/div[6]/div/div[7]/div/div[1]/div/div[1]/div/form/span/div/div[1]/div[1]/select"
            )
        )
    )

    Select(main_dropdown).select_by_visible_text(
        "All Contacts Across Organizations"
    )

    # Trimmed from 2s (found 2026-07-28, speeding up bulk search - flat
    # padding with no documented specific reason; the wait.until() calls
    # immediately after already wait adaptively for the actual next element).
    time.sleep(1)

    print("[field-debug] _prepare_phone_search: waiting for phone_search_box", flush=True)

    phone_search_box = wait.until(
        EC.presence_of_element_located(
            (
                By.XPATH,
                "/html/body/div[1]/div/div[6]/div/div[7]/div/div[1]/div/div[1]/div/form/span/div/div[2]/div[2]/input[1]"
            )
        )
    )

    driver.execute_script("arguments[0].focus();", phone_search_box)
    driver.execute_script("arguments[0].value='Phone';", phone_search_box)

    # Trimmed from 2s (found 2026-07-28) - same reasoning as above.
    time.sleep(1)

    print("[field-debug] _prepare_phone_search: waiting for mobile_input", flush=True)

    mobile_input = wait.until(
        EC.presence_of_element_located((By.XPATH, MOBILE_INPUT_XPATH))
    )

    print("[field-debug] _prepare_phone_search: ready", flush=True)

    return mobile_input


def _read_form_field(driver, tag, aria_label):
    """
    Reads one field from the Contact Form by its aria-label — e.g.
    _read_form_field(driver, "input", "First Name"). Far more reliable than
    the old row/column table-position selectors DOB and Alternate Number
    used to use (found 2026-07-22, working from element inspection provided
    directly): those broke silently — no exception, just a blank field — if
    Siebel ever reordered the form's rows, since nothing about "row 8,
    column 3" ties it to actually being the DOB field.
    """
    try:
        elements = driver.find_elements(By.XPATH, f"//{tag}[@aria-label='{aria_label}']")
        print(f"[field-debug] tag={tag} aria-label={aria_label!r} matches={len(elements)}", flush=True)
        for i, el in enumerate(elements):
            try:
                val = (el.get_attribute("value") or "").strip()
                print(f"[field-debug]   [{i}] value={val!r} displayed={el.is_displayed()}", flush=True)
            except Exception as inner_e:
                print(f"[field-debug]   [{i}] error reading: {inner_e}", flush=True)

        if not elements:
            return ""

        # Multiple elements can share an aria-label on this page (e.g. a hidden
        # template row plus the real one) - prefer a visible element with an
        # actual value over just taking whichever one the XPath finds first.
        for el in elements:
            try:
                if el.is_displayed() and (el.get_attribute("value") or "").strip():
                    return (el.get_attribute("value") or "").strip()
            except Exception:
                pass
        for el in elements:
            try:
                if el.is_displayed():
                    return (el.get_attribute("value") or "").strip()
            except Exception:
                pass
        return (elements[0].get_attribute("value") or "").strip()
    except Exception as e:
        print(f"[field-debug] tag={tag} aria-label={aria_label!r} EXCEPTION: {e}", flush=True)
        return ""


def _read_form_field_by_labelledby_contains(driver, tag, label_id_substring):
    """
    For fields that use aria-labelledby pointing at a separate label element,
    instead of carrying aria-label directly - e.g. District's is
    aria-labelledby="EPIC_District_Label_9", where the trailing "_9" is a
    row/session-specific instance number (found 2026-07-22 - same fragility
    the old row/column XPaths had, so it can't be hardcoded). Matches on any
    aria-labelledby containing the given substring instead of the full ID.
    """
    try:
        elements = driver.find_elements(
            By.XPATH, f"//{tag}[contains(@aria-labelledby, '{label_id_substring}')]"
        )
        for el in elements:
            try:
                if el.is_displayed() and (el.get_attribute("value") or "").strip():
                    return (el.get_attribute("value") or "").strip()
            except Exception:
                pass
        for el in elements:
            try:
                if el.is_displayed():
                    return (el.get_attribute("value") or "").strip()
            except Exception:
                pass
        if elements:
            return (elements[0].get_attribute("value") or "").strip()
        return ""
    except Exception:
        return ""


def _read_grid_field(driver, role_description):
    """
    Reads a cell straight from the search RESULTS grid (the row shown right
    after typing a number and hitting enter, before any drilldown) rather
    than the Contact Form - matched by aria-roledescription, which is a
    clean semantic label unlike the row-numbered id (e.g.
    "1_s_1_1_Personal_Address", where the "1_" is this row's index and
    isn't safe to hardcode). Must be called BEFORE the drilldown click -
    that grid area doesn't survive it, same as the search box itself
    (found 2026-07-23).
    """
    try:
        elements = driver.find_elements(
            By.XPATH, f"//td[@aria-roledescription='{role_description}']"
        )
        for el in elements:
            try:
                val = (el.get_attribute("title") or el.text or "").strip()
                if val:
                    return val
            except Exception:
                pass
        return ""
    except Exception:
        return ""


# SDMS's own Delivery Date format ("25-Jun-2026") - distinct from Tata
# Play's DD/MM/YYYY hh:mm:ss AM/PM, so this needs its own parser rather than
# reusing anything from tataplay.py.
_SDMS_MONTHS = {
    "jan": 1, "feb": 2, "mar": 3, "apr": 4, "may": 5, "jun": 6,
    "jul": 7, "aug": 8, "sep": 9, "oct": 10, "nov": 11, "dec": 12,
}
_SDMS_DATE_RE = re.compile(r"(\d{1,2})-([A-Za-z]{3})-(\d{4})")


def _parse_sdms_date(raw):
    m = _SDMS_DATE_RE.search(raw or "")
    if not m:
        return None
    day, mon, year = m.groups()
    month = _SDMS_MONTHS.get(mon.lower())
    if not month:
        return None
    try:
        return datetime.date(int(year), month, int(day))
    except ValueError:
        return None


def _read_most_recent_delivery_date(driver, wait):
    """
    Reads the Relationship Detail page's Sales Order grid (a jqGrid, same
    paired header/body <table> structure confirmed 2026-08-16 from live
    inspection - see the "Delivery Date" column) and returns the most recent
    Delivery Date across all its rows as a raw string, or "" if the grid
    isn't there or has no rows. Column position is read from the header row
    rather than hardcoded, in case Siebel ever reorders these.
    """
    try:
        header_table = None
        deadline = time.time() + 8
        while time.time() < deadline and header_table is None:
            for t in driver.find_elements(By.CSS_SELECTOR, "table.ui-jqgrid-htable"):
                if "Delivery Date" in t.text:
                    header_table = t
                    break
            if header_table is None:
                time.sleep(0.5)
        print(f"[field-debug] delivery-date:   header_table found: {header_table is not None}", flush=True)
        if header_table is None:
            all_htables = driver.find_elements(By.CSS_SELECTOR, "table.ui-jqgrid-htable")
            print(f"[field-debug] delivery-date:   {len(all_htables)} htables present, none had 'Delivery Date':", flush=True)
            for t in all_htables:
                print(f"[field-debug] delivery-date:     {t.text[:80]!r}", flush=True)
            return ""

        header_cells = [c.text.strip() for c in header_table.find_elements(By.TAG_NAME, "th")] \
            or [c.text.strip() for c in header_table.find_elements(By.TAG_NAME, "td")]
        print(f"[field-debug] delivery-date:   header_cells: {header_cells}", flush=True)
        if "Delivery Date" not in header_cells:
            return ""
        date_col = header_cells.index("Delivery Date")

        all_header_tables = driver.find_elements(By.CSS_SELECTOR, "table.ui-jqgrid-htable")
        all_body_tables = driver.find_elements(By.CSS_SELECTOR, "table.ui-jqgrid-btable")
        header_index = all_header_tables.index(header_table)
        if header_index >= len(all_body_tables):
            return ""
        body_table = all_body_tables[header_index]

        best_date, best_raw = None, ""
        body_rows = body_table.find_elements(By.TAG_NAME, "tr")
        print(f"[field-debug] delivery-date:   body rows: {len(body_rows)}", flush=True)
        for row in body_rows:
            cells = row.find_elements(By.TAG_NAME, "td")
            if len(cells) <= date_col:
                continue
            raw_date = cells[date_col].text.strip()
            print(f"[field-debug] delivery-date:     row date cell: {raw_date!r}", flush=True)
            parsed = _parse_sdms_date(raw_date)
            if parsed and (best_date is None or parsed > best_date):
                best_date, best_raw = parsed, raw_date

        return best_raw
    except Exception as e:
        print(f"[field-debug] delivery-date:   EXCEPTION in grid read: {e}", flush=True)
        return ""


def _get_last_delivery_date(driver, wait):
    """
    The Contact Form's own "Relationship" list (already part of the loaded
    contact's page - no separate "Relationships" tab click needed, confirmed
    2026-08-16 after repeatedly landing on an unrelated top-level
    "Relationships" module by mistake) has one row per relationship the
    consumer has - LPG, Loyalty, etc. LPG relationships are the ones whose
    Relationship Id starts with "7" (a "7 series" number, per explicit
    instruction - Loyalty and others use different numbering, e.g. a
    "6000..." id seen live). A consumer can have more than one 7-series
    relationship (e.g. an ACTIVE one and an older TRANSFERRED one) - each is
    drilled into and checked, since it isn't safe to assume only the first
    one has delivery history. Returns the most recent Delivery Date found
    across all of them, or "" if none have any.
    """
    try:
        id_cells = driver.find_elements(By.XPATH, "//td[@aria-roledescription='Relationship Id']")
        print(f"[field-debug] delivery-date: relationship id cells found: {len(id_cells)}", flush=True)
        seven_series_ids = []
        for cell in id_cells:
            try:
                link = cell.find_element(By.XPATH, ".//a[@class='drilldown']")
                value = (link.text or "").strip()
                print(f"[field-debug] delivery-date:   cell value={value!r}", flush=True)
                if value.startswith("7") and value not in seven_series_ids:
                    seven_series_ids.append(value)
            except Exception as e:
                print(f"[field-debug] delivery-date:   cell has no drilldown link: {e}", flush=True)
                continue

        print(f"[field-debug] delivery-date: 7-series ids: {seven_series_ids}", flush=True)
        if not seven_series_ids:
            return ""

        best_date, best_raw = None, ""
        for rel_id in seven_series_ids:
            try:
                link = wait.until(EC.presence_of_element_located((
                    By.XPATH,
                    f"//td[@aria-roledescription='Relationship Id']//a[normalize-space(text())='{rel_id}']"
                )))
                driver.execute_script("arguments[0].click();", link)

                # Some relationships trigger a native Siebel business-rule
                # alert() on drilldown (confirmed 2026-08-16, live: "Selected
                # address pincode at Relationship does not match any values
                # defined in your Serving PinCode master...") - purely
                # informational, dismissing it still lands on the
                # Relationship Detail page underneath. Left unhandled, this
                # blocks every subsequent driver call with an
                # UnexpectedAlertPresentException, which is what silently
                # emptied Last Delivery Date for every relationship checked
                # after it too.
                try:
                    WebDriverWait(driver, 2).until(EC.alert_is_present())
                    alert_text = driver.switch_to.alert.text
                    print(f"[field-debug] delivery-date:   dismissing alert: {alert_text!r}", flush=True)
                    driver.switch_to.alert.accept()
                except Exception:
                    pass

                wait.until(lambda d: d.execute_script("return document.readyState") == "complete")
                time.sleep(2)
                print(f"[field-debug] delivery-date: drilled into {rel_id}, url={driver.current_url!r}", flush=True)

                # The Relationship Detail page lands on a different sub-tab
                # by default (Address/Identity/Bank/Phone/Email applets) -
                # the Sales Order grid with Delivery Date lives under its own
                # "Orders" sub-tab (confirmed 2026-08-16, live), not shown
                # until that tab is clicked.
                try:
                    orders_tab = WebDriverWait(driver, 5).until(
                        EC.presence_of_element_located((By.XPATH, "//a[normalize-space(text())='Orders']"))
                    )
                    driver.execute_script("arguments[0].click();", orders_tab)
                    wait.until(lambda d: d.execute_script("return document.readyState") == "complete")
                    time.sleep(2)
                    print(f"[field-debug] delivery-date:   clicked Orders tab", flush=True)
                except Exception as e:
                    print(f"[field-debug] delivery-date:   could not find/click Orders tab: {e}", flush=True)

                raw_date = _read_most_recent_delivery_date(driver, wait)
                print(f"[field-debug] delivery-date:   raw_date for {rel_id}: {raw_date!r}", flush=True)
                parsed = _parse_sdms_date(raw_date) if raw_date else None
                if parsed and (best_date is None or parsed > best_date):
                    best_date, best_raw = parsed, raw_date
            except Exception as e:
                print(f"[field-debug] delivery-date: EXCEPTION for {rel_id}: {e}", flush=True)
            finally:
                # Back to the Contact page so the next 7-series id (if any)
                # can be found fresh - element handles from before this
                # drilldown don't survive it, same as everywhere else in
                # this file that navigates away and back.
                driver.back()
                wait.until(lambda d: d.execute_script("return document.readyState") == "complete")
                time.sleep(1.5)

        return best_raw
    except Exception:
        return ""


def _search_one_number(driver, wait, mobile_input, mobile_number):

    print(f"[field-debug] === searching {mobile_number} ===", flush=True)

    # mobile_input is re-prepared fresh by the caller for every number (see
    # run_bulk_search) rather than reused across the whole batch - the
    # element's XPath stops resolving at all once a drilldown has loaded a
    # specific contact's record, so the old approach of reusing one handle
    # for the entire batch made every number after the first fail outright.
    mobile_input.clear()
    time.sleep(0.2)

    mobile_input.send_keys(mobile_number)
    mobile_input.send_keys(Keys.ENTER)

    # Trimmed from 1s (found 2026-07-28, speeding up bulk search).
    time.sleep(0.6)

    # Also from the results grid, before the drilldown - a single combined
    # name field, more reliable than splitting First Name/Last Name below
    # since it doesn't depend on the drilldown succeeding at all.
    full_name = _read_grid_field(driver, "Full Name")

    # Click the Last Name drilldown link in the results grid to load that
    # contact's record into the Contact Form below — the fields read after
    # this point come from THAT form, not the grid row itself.
    try:
        drilldown = wait.until(
            EC.element_to_be_clickable(
                (By.XPATH, "(//a[@class='drilldown' and @name='Last Name'])[1]")
            )
        )
        driver.execute_script("arguments[0].click();", drilldown)
        print(f"[field-debug] drilldown clicked for {mobile_number}", flush=True)

        # A fixed 0.8s sleep here (trimmed from 1.5s on 2026-07-28 for bulk
        # search speed) used to be the only wait before reading every field
        # below - not always enough time for Siebel to finish rendering the
        # Contact Form, confirmed 2026-08-16 from real searches coming back
        # with Relationship Id (and sometimes DOB/Address) silently blank
        # even for a genuine match. Polling for Relationship Id specifically
        # to go non-empty is a proxy for "the form has actually rendered" -
        # capped at 3s so a record that's genuinely missing it (not a timing
        # issue) doesn't stall the whole search waiting for a value that will
        # never come.
        relationship_id_xpath = "//input[@aria-label='Relationship Id']"
        deadline = time.time() + 3
        while time.time() < deadline:
            try:
                el = driver.find_element(By.XPATH, relationship_id_xpath)
                if (el.get_attribute("value") or "").strip():
                    break
            except Exception:
                pass
            time.sleep(0.2)
        else:
            # Timed out without a value - still give the rest of the form a
            # brief moment, same floor as the old fixed sleep.
            time.sleep(0.3)
    except Exception as e:
        # No result row to click — a genuine no-match. The field reads below
        # will all come back empty, same as any other not-found case.
        print(f"[field-debug] NO drilldown row for {mobile_number}: {e}", flush=True)

    relationship_id = _read_form_field(driver, "input", "Relationship Id")

    # The Contact Form's own single "Address" textarea (Consumer Detail
    # panel) is the ONLY address source now (per explicit instruction) - the
    # old approach of reassembling 7 separate results-grid cells (Personal
    # Address/Address Line 2/3/City/Postal Code/District/State) produced a
    # messier, less readable address and has been dropped entirely rather
    # than kept as a fallback.
    #
    # A plain aria-label='Address' exact match (what live DevTools
    # inspection showed for two different contacts) came back with ZERO
    # matches for a third real contact (confirmed 2026-08-16) despite the
    # rest of the form reading fine - this field's real attribute is
    # aria-labelledby="EPIC_Primary_Account_Street_Address_Label_8", where
    # the trailing "_8" is a session-specific instance number (same
    # fragility documented on _read_form_field_by_labelledby_contains() for
    # District/Country/etc.), and apparently not every contact's session
    # renders a plain aria-label alongside it. Matching on the
    # labelledby-contains substring instead is what already handles this
    # exact situation for every other field in this file.
    address_xpath = "//textarea[contains(@aria-labelledby, 'Street_Address_Label')]"
    deadline = time.time() + 3
    while time.time() < deadline:
        try:
            el = driver.find_element(By.XPATH, address_xpath)
            if (el.get_attribute("value") or "").strip():
                break
        except Exception:
            pass
        time.sleep(0.2)
    address = _read_form_field_by_labelledby_contains(driver, "textarea", "Street_Address_Label")

    country = _read_form_field_by_labelledby_contains(driver, "input", "Personal_Country_Label")
    pin_code = _read_form_field_by_labelledby_contains(driver, "input", "Personal_Postal_Code_Label")
    urban_rural = _read_form_field_by_labelledby_contains(driver, "input", "EPIC_Urban_Rural_Label")

    # DOB EXTRACTION — reformats Siebel's raw value into DD-MM-YYYY, expanding
    # 2-digit years the same way the row/column version used to (00->1900,
    # >=50->19xx, <50->20xx).
    dob = ""
    raw_dob = _read_form_field(driver, "input", "DOB")
    if raw_dob:
        parts = raw_dob.split("-")
        if len(parts) == 3:
            day = parts[0].zfill(2)
            month = parts[1]
            year = parts[2]
            if len(year) == 4:
                final_year = year
            else:
                year_int = int(year)
                if year == "00":
                    final_year = "1900"
                elif year_int >= 50:
                    final_year = f"19{year}"
                else:
                    final_year = f"20{year}"
            dob = f"{day}-{month}-{final_year}"
        else:
            # A real value that just doesn't split into exactly 3 "-"
            # separated parts was previously discarded silently here,
            # leaving dob="" even though SDMS returned something (found
            # 2026-07-25). The raw value is a better result than nothing.
            dob = raw_dob

    # ALT NUMBER — the Phone related list can have any number of rows per
    # contact (found 2026-07-23, live inspection), not just one; each phone
    # cell carries aria-roledescription="Phone #" regardless of row, so
    # unlike the old row/column XPath this survives the list having a
    # different row count per contact. The searched number's own row is
    # always in there too, so every OTHER value in that column is collected
    # and joined - same pattern as the multi-line Address field above -
    # rather than keeping only the first one and dropping the rest.
    alt_numbers = []
    try:
        phone_cells = driver.find_elements(
            By.XPATH, "//td[@aria-roledescription='Phone #']"
        )
        print(f"[field-debug] phone cells found: {len(phone_cells)}", flush=True)
        for phone_cell in phone_cells:
            try:
                val = (phone_cell.get_attribute("title") or phone_cell.text or "").strip()
                print(f"[field-debug]   phone cell value={val!r}", flush=True)
                if val and val != mobile_number and val not in alt_numbers:
                    alt_numbers.append(val)
            except Exception as inner_e:
                print(f"[field-debug]   phone cell error: {inner_e}", flush=True)
    except Exception as e:
        print(f"[field-debug] phone cell scan EXCEPTION: {e}", flush=True)
    alternate_number = ", ".join(alt_numbers)

    last_delivery_date = _get_last_delivery_date(driver, wait)

    return {
        "Mobile Number": mobile_number,
        "Full Name": full_name,
        "Alternate Number": alternate_number,
        "DOB": dob,
        "Relationship Id": relationship_id,
        "Address": address,
        "Country": country,
        "Pin Code": pin_code,
        "Urban/Rural": urban_rural,
        "Last Delivery Date": last_delivery_date,
    }


def _quit_driver_with_timeout(driver, timeout=15):
    """
    driver.quit() sends a real HTTP request to chromedriver and waits for a
    response - if chromedriver itself has gone fully unresponsive (found
    2026-07-25, under heavy resource pressure from many accumulated Chrome
    processes), that call can hang indefinitely. Worse than losing this one
    job: run_bulk_search's caller holds selenium_lock for as long as this
    call blocks, so every OTHER queued search sits frozen behind it too, with
    no way out short of restarting the whole server. Running the quit() call
    on its own thread and only waiting up to `timeout` seconds for it means
    the lock always gets released - worst case, the underlying chromedriver
    process is abandoned as an orphan, which is already a known, separately
    handled cleanup case rather than a total deadlock.
    """
    quit_thread = threading.Thread(target=driver.quit, daemon=True)
    quit_thread.start()
    quit_thread.join(timeout)


def _type_and_submit(input_el, value, timeout=8):
    """
    Fills a locateme.services search <input> with `value` and submits it -
    used by rc_print.py/hp_gas.py/tracing2_tools.py's own search inputs.

    Confirmed live 2026-09-02: this site rejects Selenium's synthetic
    keyboard events on its search inputs specifically (send_keys() and even
    ActionChains produced literally zero characters, every single attempt -
    not a timing race, since retrying repeatedly over 8s never once got a
    character through) while the SAME technique typed the login page's
    email/password fields (rc_print.py's _login()) just fine - this looks
    like a deliberate anti-scraping measure scoped to the credit-consuming
    search pages rather than a general bot-detection block (navigator.
    webdriver reads as unset, same as everywhere else this codebase talks to
    this site). Enter-key submission is blocked the same way.
    Setting the value via the native <input> value setter (bypassing
    whatever wraps .value on this React/Next.js input) and dispatching
    input/change events updates React's own state correctly - confirmed via
    get_attribute("value") reflecting it immediately - and clicking the
    page's own type="submit" button (real mouse click, not a key event)
    submits it the same way a human clicking it would.
    """
    deadline = time.time() + timeout
    while True:
        input_el.click()
        driver = input_el.parent
        driver.execute_script(
            """
            const el = arguments[0], value = arguments[1];
            const nativeSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
            nativeSetter.call(el, value);
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
            """,
            input_el, str(value),
        )
        if input_el.get_attribute("value") == str(value):
            break
        if time.time() >= deadline:
            raise RuntimeError(f"Could not type {value!r} into the search box.")
        time.sleep(0.5)

    submit_btn = input_el.find_element(By.XPATH, "./ancestor::form//button[@type='submit']")
    driver.execute_script("arguments[0].click();", submit_btn)


def run_bulk_search(mobile_numbers, progress_callback=None):
    """
    Runs the SDMS bulk search for the given list of mobile numbers
    and returns a list of result dicts.

    progress_callback(done_count, total_count, latest_record) is called
    after each number is processed, if provided.
    """

    # Raised from 25 to 500 (2026-08-19, admin-only per explicit request) -
    # must match app.py's MAX_NUMBERS_ADMIN. Can't import that constant here
    # (app.py already imports run_bulk_search FROM this module, so the
    # reverse import would be circular) - see app.py's own comment on
    # MAX_NUMBERS_ADMIN for what goes wrong if these two drift apart again.
    mobile_numbers = mobile_numbers[:500]

    results = []

    # driver creation used to happen BEFORE this try/finally, so a failure
    # inside _create_driver() itself (e.g. a ChromeDriver crash while
    # starting up) skipped the driver.quit() cleanup entirely, orphaning
    # whatever Chrome processes had already been spawned. Those orphans pile
    # up over repeated failures and starve later searches of resources,
    # causing MORE failures — a self-reinforcing pile-up (found 2026-07-21,
    # ~30+ orphaned chrome.exe processes found on the server after a run of
    # failures). driver=None here means the finally block below only calls
    # .quit() when there's actually something to quit.
    driver = None
    try:

        driver = _create_driver(headless=True)
        wait = WebDriverWait(driver, 20)

        _login(driver, wait)

        def _not_found_record(number):
            return {
                "Mobile Number": number,
                "Full Name": "",
                "Alternate Number": "",
                "DOB": "",
                "Relationship Id": "",
                "Address": "",
                "Country": "",
                "Pin Code": "",
                "Urban/Rural": "",
                "Last Delivery Date": "",
                "NOT_FOUND": True
            }

        for index, mobile_number in enumerate(mobile_numbers):

            try:
                # Re-opening Contacts and re-preparing the search fresh for
                # EVERY number, not just once before the loop (found
                # 2026-07-23): once a drilldown loads a specific contact's
                # record, the search box's XPath stops resolving at all - it
                # isn't just "populated below", the search area itself
                # changes state. Reusing one mobile_input handle across the
                # whole batch meant every number after the first either hit
                # a 20s timeout trying to re-find it or a stale-element
                # error outright, silently turning into "Not Found".
                _open_contacts(driver, wait)
                mobile_input = _prepare_phone_search(driver, wait)

                record = _search_one_number(driver, wait, mobile_input, mobile_number)

            except Exception:

                record = _not_found_record(mobile_number)

            results.append(record)

            if progress_callback:
                progress_callback(index + 1, len(mobile_numbers), record)

            if record.get("NOT_FOUND"):
                # A per-number failure is normal (genuine no-match, one-off
                # timeout) and isn't itself a reason to stop. But if the
                # WHOLE session died (Chrome crashed, driver disconnected),
                # every remaining number would independently discover that
                # same fact only after its own full ~20s of waits - a cheap
                # liveness check here catches that once instead of N times
                # (found 2026-07-25). driver.title on a dead session raises
                # immediately rather than hanging.
                try:
                    _ = driver.title
                except WebDriverException:
                    for remaining_number in mobile_numbers[index + 1:]:
                        remaining_record = _not_found_record(remaining_number)
                        results.append(remaining_record)
                        if progress_callback:
                            progress_callback(len(results), len(mobile_numbers), remaining_record)
                    break

    finally:

        if driver is not None:
            _quit_driver_with_timeout(driver)

    return results


if __name__ == "__main__":

    print("Enter mobile numbers for bulk search")
    print("(separate by comma, space, or new line - up to 500 numbers).")
    print("Press ENTER on a blank line when done:\n")

    raw_lines = []

    while True:

        line = input()

        if not line.strip():
            break

        raw_lines.append(line)

    raw_text = " ".join(raw_lines)

    numbers = [
        n.strip()
        for n in raw_text.replace(",", " ").split()
        if n.strip()
    ]

    print(f"Total Numbers Loaded: {len(numbers)}")

    search_results = run_bulk_search(numbers)

    print("\nALL PROCESS COMPLETED")

    for r in search_results:
        print(r)
