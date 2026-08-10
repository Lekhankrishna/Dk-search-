from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.common.keys import Keys

import time

# Same locateme.services account/login as rc_print.py - reused rather than
# duplicated (see rc_print.py's own comment on why the Chrome setup itself
# is imported from lpg_search.py).
from lpg_search import _create_driver, _quit_driver_with_timeout
from rc_print import _login

HP_GAS_URL = "https://locateme.services/tools/hp-gas-advanced"

# A hit renders as repeating section headers ("Consumer Details", "E-KYC
# Profile", "Distributor Intelligence", "Bank & LPG Linkage",
# "Geo-Intelligence") each followed by a grid of field cards - confirmed
# 2026-08-08 from a real result's HTML. Every field card is a
# <div class="p-3 bg-muted/30 rounded-lg ..."> holding exactly two <p> tags
# (label, then value), so scraping by that shared structure works for any
# section/field name locateme.services shows, without hardcoding field
# names that might change.
SECTION_HEADER_XPATH = "//h3[contains(@class,'tracking-[0.2em]')]"
FIELD_CARD_XPATH = "//div[contains(@class,'bg-muted/30') and contains(@class,'rounded-lg') and contains(@class,'p-3')]"


def _extract_sections(driver):
    nodes = driver.find_elements(By.XPATH, f"{SECTION_HEADER_XPATH} | {FIELD_CARD_XPATH}")

    sections = []
    current = None
    for el in nodes:
        if el.tag_name == "h3":
            current = {"title": el.text.strip(), "fields": []}
            sections.append(current)
            continue
        if current is None:
            continue
        ps = el.find_elements(By.TAG_NAME, "p")
        if len(ps) < 2:
            continue
        label = ps[0].text.strip()
        value = ps[-1].text.strip()
        if label:
            current["fields"].append({"label": label, "value": value})

    return [s for s in sections if s["fields"]]


def run_hp_gas_single(mobile_number):
    """
    Logs into locateme.services and runs a single HP Gas Advanced search for
    one mobile number, returning
    {"mobileNumber", "found", "sections": [{"title", "fields": [{"label","value"}]}]}
    on a hit, or {"mobileNumber", "found": False} on a miss.
    """

    driver = None
    try:
        driver = _create_driver(headless=True)
        wait = WebDriverWait(driver, 20)

        _login(driver, wait)

        driver.get(HP_GAS_URL)

        number_input = wait.until(
            EC.presence_of_element_located((By.CSS_SELECTOR, "input[placeholder='Enter 10-digit number']"))
        )
        number_input.clear()
        number_input.send_keys(mobile_number)
        number_input.send_keys(Keys.RETURN)

        deadline = time.time() + 30
        while time.time() < deadline:
            body_text = driver.find_element(By.TAG_NAME, "body").text

            if "not found" in body_text.lower():
                return {"mobileNumber": mobile_number, "found": False}

            # "NODE: <consumerNumber>" only appears once a result card has
            # actually rendered (confirmed from a real hit) - waiting for it
            # avoids reading the field-card grid while it's still empty/mid-render.
            if "NODE:" in body_text:
                sections = _extract_sections(driver)
                if sections:
                    return {"mobileNumber": mobile_number, "found": True, "sections": sections}
                # Structure changed unexpectedly - fall back to raw text
                # rather than silently returning nothing.
                return {"mobileNumber": mobile_number, "found": True, "rawText": body_text}

            time.sleep(1)

        raise RuntimeError(f"HP Gas Advanced timed out waiting for a result for {mobile_number}")

    finally:
        if driver is not None:
            _quit_driver_with_timeout(driver)
