from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait, Select
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.common.keys import Keys
from selenium.common.exceptions import WebDriverException

import threading
import time

# =========================================================
# LOGIN DETAILS
# =========================================================

USERNAME = "NOIDCC1"
PASSWORD = "Test@2026"

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

    # Address is merged from these 7 results-grid cells - must happen BEFORE
    # the drilldown click below, since (like the search box) this grid
    # doesn't survive navigating into a contact's record (found 2026-07-23).
    # Landmark is deliberately excluded - in this data it just repeats the
    # phone number being searched, not an actual landmark.
    grid_address_parts = [
        _read_grid_field(driver, "Personal Address"),
        _read_grid_field(driver, "Address Line 2"),
        _read_grid_field(driver, "Address Line 3"),
        _read_grid_field(driver, "City"),
        _read_grid_field(driver, "Postal Code"),
        _read_grid_field(driver, "District"),
        _read_grid_field(driver, "State"),
    ]
    address = ", ".join(p for p in grid_address_parts if p)

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
        # Trimmed from 1.5s (found 2026-07-28, speeding up bulk search).
        time.sleep(0.8)
        print(f"[field-debug] drilldown clicked for {mobile_number}", flush=True)
    except Exception as e:
        # No result row to click — a genuine no-match. The field reads below
        # will all come back empty, same as any other not-found case.
        print(f"[field-debug] NO drilldown row for {mobile_number}: {e}", flush=True)

    relationship_id = _read_form_field(driver, "input", "Relationship Id")

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
