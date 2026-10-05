from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.common.exceptions import StaleElementReferenceException

import time

from lpg_search import _type_and_submit
from rc_print import _login, run_rc_print, _create_locateme_driver, _release_locateme_driver
from hp_gas import run_hp_gas_single

LOCATEME_BASE = "https://locateme.services/tools"

# Every tool on locateme.services shares the same page shell (confirmed
# 2026-08-17 - one text/email <input> with a placeholder, one submit
# <button>), but NOT the same exact CSS classes for its result markup - a
# first attempt at this module matched Mobile Info and HP Gas Advanced by
# their literal Tailwind classes (e.g. "text-[10px] tracking-widest" for a
# label, "bg-muted/30 rounded-lg p-3" for a field card) and that broke on
# UPI Finder, which renders conceptually the same label/value field pairs
# and section headers with completely different classes
# ("text-sm font-medium" labels, no field-card wrapper at all - just a
# <div> holding two <p> children). Chasing per-tool class names across 24
# tools isn't sustainable, so this module matches DOM SHAPE instead:
#   - a field pair is ANY element with exactly two direct <p> children
#     (label, then value) - true across every tool's markup seen so far,
#     regardless of styling.
#   - a section header is ANY <h3> with non-empty text.
#   - a "person card" (Mobile Info's own style: one result = a name +
#     status badge + its fields) is still matched by its specific wrapper
#     class combo ("rounded-lg shadow-2xl animate-in"), since that combo is
#     distinctive enough to be low-risk and lets the name/badge be pulled
#     out cleanly - but its FIELDS are extracted with the same generic
#     "two <p> children" rule as everything else now, not the old
#     class-based label matcher.
# Extraction tries, in order: person-cards, then h3-delimited sections,
# then (if the page has field pairs but no name-cards/sections at all) one
# flat ungrouped record. Untested tools that match none of these fall
# through to the rawText capture at the bottom of run_tool_search().
#
# whatsapp-dp is a known exception - it returns an image (a WhatsApp profile
# picture), not label/value fields, so this generic scraper will find no
# records for it. Left in the registry rather than excluded so a search
# still comes back with SOMETHING (rawText / the raw credit-spend), not a
# silent 404 in the CRM - proper image handling can be added later if it's
# actually needed.
TOOL_REGISTRY = {
    "mobile-info":              {"label": "Mobile Info",              "placeholder": "Enter Mobile Number",   "credits": 100},
    "rc-print":                 {"label": "RC PRINT",                 "placeholder": "Enter Vehicle Number",  "credits": 150},
    "hp-gas-advanced":          {"label": "HP Gas Advanced",          "placeholder": "Enter Mobile Number",   "credits": 150},
    "vehicle-info":              {"label": "Vehicle Intelligence",     "placeholder": "Enter Vehicle Number",  "credits": 100},
    "aadhaar-info":               {"label": "Aadhaar Info",             "placeholder": "Enter Aadhaar Number",  "credits": 100},
    "sms-header-decode":         {"label": "SMS Header Decode",        "placeholder": "e.g. SGILTD",           "credits": 1},
    "imei-info":                 {"label": "IMEI Info",                "placeholder": "Enter 15-digit IMEI",   "credits": None},
    "aadhaar-to-ration":         {"label": "Aadhaar to Ration",        "placeholder": "Enter Aadhaar Number",  "credits": 50},
    "number-to-name":            {"label": "Number to Name",           "placeholder": "e.g. 9100721394",       "credits": 10},
    "number-to-facebook":        {"label": "Number to Facebook",       "placeholder": "e.g. 9717444994",       "credits": 5},
    "whatsapp-dp":               {"label": "WhatsApp DP Downloader",   "placeholder": "Mobile Number",         "credits": None},
    "aadhaar-to-pan":            {"label": "Aadhaar to PAN",           "placeholder": "e.g. 712481196833",     "credits": 50},
    "pan-to-gst":                {"label": "PAN to GST",               "placeholder": "e.g. ARCPV7418G",       "credits": 15},
    "vehicle-to-number":         {"label": "Vehicle to Number",        "placeholder": "e.g. UP70HQ2225",       "credits": 50},
    "indane-gas-info":           {"label": "Indane Gas",               "placeholder": "Enter 10-digit number", "credits": 100},
    "indane-gas-verification":   {"label": "Indane Gas v2",            "placeholder": "Enter mobile number",   "credits": 75},
    "bharat-gas-info":           {"label": "Bharat Gas Info",          "placeholder": "Enter Number",          "credits": None},
    "gmail-info":                {"label": "Gmail Info",               "placeholder": "example@gmail.com",     "credits": 35},
    "pan-info":                  {"label": "PAN Info",                 "placeholder": "Enter PAN Number",      "credits": 10},
    "vehicle-fastag":            {"label": "Vehicle Fastag",           "placeholder": "e.g. DL10C1234",        "credits": 50},
    "upi-finder":                {"label": "UPI Finder",               "placeholder": "e.g. 7982966659",       "credits": 25},
    "gst-info":                  {"label": "GST Info",                 "placeholder": "e.g. 09AAKCD6139J1Z8",  "credits": 25},
    "ifsc-info":                 {"label": "IFSC Info",                "placeholder": "e.g. SBIN0005383",      "credits": None},
    "ip-info":                   {"label": "IP Info",                  "placeholder": "e.g. 8.8.8.8",          "credits": None},
    "email-leak-check":          {"label": "Email Leak Check",         "placeholder": "user@example.com",      "credits": None},
    "sim-carrier-checker":       {"label": "SIM Carrier Checker",      "placeholder": "e.g. 9876543210",       "credits": None},
}

CARD_XPATH = ".//div[contains(@class,'rounded-lg') and contains(@class,'shadow-2xl') and contains(@class,'animate-in')]"
NAME_XPATH = ".//div[contains(@class,'text-2xl') and contains(@class,'font-black') and contains(@class,'uppercase')]"
BADGE_XPATH = ".//div[contains(@class,'inline-flex') and contains(@class,'rounded-full') and contains(@class,'border') and contains(@class,'text-xs')]"

# The universal field-pair shape (see module docstring): any element with
# exactly two <p> DESCENDANTS (.//p, not just direct children ./p - widened
# 2026-08-17 after a live Aadhaar to Ration search returned zero records
# despite genuine data being present: that page's "Registry Meta" field
# cards put the label <p> one level deeper, inside an icon-wrapper <div>,
# while the value <p> is a direct child - "exactly two DIRECT <p> children"
# matched neither, so a field card that HAD 2 total <p>s just silently
# vanished.
#
# "and not(.//*[count(.//p)=2])" excludes any match that itself CONTAINS
# another match - without this, a field whose 2-<p> container is wrapped in
# one more layer (Mobile Info/UPI Finder's own "<div class='flex ... gap-3'>
# <svg/><div><p>label</p><p>value</p></div></div>" icon wrapper) matches
# TWICE: once as the inner <div> (2 direct <p>s) and once as its own outer
# wrapper (still exactly 2 <p> descendants, just one level further out) -
# confirmed 2026-08-17 as a regression this same day's earlier fix
# introduced, doubling every field on both of those tools. This keeps only
# the innermost (most specific) matching container per field, which still
# correctly matches Registry Meta's single-level card (nothing beneath it
# also satisfies count(.//p)=2, so the negation is trivially true there).
FIELD_PAIR_XPATH = ".//*[count(.//p) = 2 and not(.//*[count(.//p) = 2])]"

FAILURE_NEEDLES = (
    "not found", "no record", "no results", "no matching", "no data found",
    "invalid", "insufficient credit", "error occurred", "something went wrong",
    # Confirmed live 2026-08-17: genuine upstream failures on the site's own
    # side, not extraction bugs - surfacing these as a clean "not found"
    # instead of a raw-text dump.
    "api error", "service error", "cooldown",
    # Confirmed live 2026-09-04 (Aadhaar to Ration): a real upstream data-
    # source failure, not this scraper mis-locating anything - the site's
    # own message when its Aadhaar-linked-records backend itself is
    # unreachable.
    "connection to registry nodes failed", "please check your network",
)


def _is_loading_placeholder(value):
    """
    A live 20-tool verification pass (2026-08-17) found this site shows a
    themed loading animation ("SYNCHRONIZING REGISTRY NODE" / "ESTABLISHING
    SECURE HANDSHAKE...") on at least 7 of 24 tools while a search is still
    in flight - and it renders as a label/value pair matching the exact
    same "two <p> children" shape a genuine result field does, since it's
    built from the same UI component. Every placeholder value observed
    ends in an ellipsis, while no genuine field value seen on any tool
    does - filtering these out keeps the poll loop waiting for the real
    result instead of returning the animation text as if it were the
    final answer (confirmed: this was silently corrupting results for
    Vehicle Intelligence, Aadhaar Info, IMEI Info, Aadhaar to PAN, PAN
    Info, GST Info, and SMS Header Decode until this fix).
    """
    return value.rstrip().endswith(("...", "…"))


def _is_sentence_label(label):
    """
    Field labels are short ("FULL NAME", "CONSUMER ID"); the redesigned tool
    header (2026-10-03) is a title + a full-sentence subtitle ("Complete
    consumer profile extraction and agency audit.") built from the same
    two-<p> shape, and was being returned as the search result. A label
    that reads like a sentence is never a real field.
    """
    label = label.strip()
    return label.endswith(".") or len(label) > 45 or len(label.split()) > 6


def _field_pairs_within(root):
    fields = []
    for el in root.find_elements(By.XPATH, FIELD_PAIR_XPATH):
        ps = el.find_elements(By.XPATH, ".//p")
        label = ps[0].text.strip()
        value = ps[1].text.strip()
        if label and not _is_sentence_label(label) and not _is_loading_placeholder(value):
            fields.append({"label": label, "value": value})
    return fields


def _extract_person_cards(root):
    cards = root.find_elements(By.XPATH, CARD_XPATH)
    records = []
    for card in cards:
        name_els = card.find_elements(By.XPATH, NAME_XPATH)
        name = name_els[0].text.strip() if name_els else ""

        badge_els = card.find_elements(By.XPATH, BADGE_XPATH)
        status = badge_els[0].text.strip() if badge_els else ""

        fields = _field_pairs_within(card)

        if name or fields:
            records.append({"name": name, "status": status, "fields": fields})

    return records


def _extract_sections(root):
    nodes = root.find_elements(By.XPATH, f".//h3 | {FIELD_PAIR_XPATH}")

    sections = []
    current = None
    for el in nodes:
        if el.tag_name == "h3":
            title = el.text.strip()
            if not title:
                continue
            current = {"name": title, "status": "", "fields": []}
            sections.append(current)
            continue
        if current is None:
            continue
        ps = el.find_elements(By.XPATH, ".//p")
        if len(ps) != 2:
            continue
        label = ps[0].text.strip()
        value = ps[1].text.strip()
        if label and not _is_sentence_label(label) and not _is_loading_placeholder(value):
            current["fields"].append({"label": label, "value": value})

    return [s for s in sections if s["fields"]]


def _extract_flat(root):
    fields = _field_pairs_within(root)
    return [{"name": "", "status": "", "fields": fields}] if fields else []


def _extract_tables(root):
    """
    A third layout, confirmed 2026-08-17 on Aadhaar to Ration: alongside its
    "Registry Meta" field-card section, that tool ALSO renders a genuine
    HTML <table> ("Family Member Profile") listing several people - one row
    per person, not one field per person. Each <tbody> row becomes its own
    record here (matching how a multi-person Mobile Info result already
    gets one card per person), with <thead>/<th> text as field labels and
    a "Name"-labeled column (case-insensitive) promoted to the record's own
    name if present.

    Each row also carries a "section" value - the table's own preceding
    <h3> heading text (e.g. "Family Member Profile (5)"), found via
    preceding::h3[1] (nearest earlier <h3> in document order, regardless of
    nesting depth, same "just find the closest one" approach already used
    to pair section headers with their fields in _extract_sections()). The
    frontend groups consecutive records sharing a "section" under one
    shared heading instead of repeating it - see tracing2.php's
    renderResult(). Records from every other extractor leave "section"
    empty, so they render as standalone cards same as before.
    """
    records = []
    for table in root.find_elements(By.XPATH, ".//table"):
        headers = [th.text.strip() for th in table.find_elements(By.XPATH, ".//thead//th")]
        if not headers:
            continue
        heading_els = table.find_elements(By.XPATH, "preceding::h3[1]")
        section = heading_els[0].text.strip() if heading_els else ""
        for row in table.find_elements(By.XPATH, ".//tbody/tr"):
            cells = row.find_elements(By.XPATH, "./td")
            if not cells:
                continue
            fields = []
            name = ""
            for i, cell in enumerate(cells):
                label = headers[i] if i < len(headers) else f"Column {i + 1}"
                value = cell.text.strip()
                if not value:
                    continue
                if not name and label.strip().lower() == "name":
                    name = value
                fields.append({"label": label, "value": value})
            if fields:
                records.append({"name": name, "status": "", "fields": fields, "section": section})
    return records


def _extract_records(driver):
    """
    A single result page can mix layouts (Aadhaar to Ration shows a
    field-card "Registry Meta" section AND a separate family-member
    <table> at once) - every source is tried and combined rather than
    stopping at the first non-empty one, so nothing found by a later
    pattern gets silently dropped just because an earlier one already
    matched something. Only falls back to the ungrouped flat scan when
    NONE of the structured patterns found anything at all.
    """
    root = _main_root(driver)
    records = []
    records.extend(_extract_person_cards(root))
    records.extend(_extract_sections(root))
    records.extend(_extract_tables(root))
    if records:
        return records
    return _extract_flat(root)


def _main_root(driver):
    """
    The page layout nests two <main> elements (an outer shell, an inner
    page-specific one - confirmed 2026-08-17) - the innermost one holds only
    the actual tool's own content, excluding the sidebar (Dashboard/History/
    Settings/Credits/account email) and topbar. Scoping every extraction
    query to this element (rather than the whole document) both keeps
    output clean and reduces false-positive risk for the generic
    "two <p> children" field-pair match - a random sidebar/topbar element
    coincidentally shaped that way would otherwise be picked up too. Falls
    back to <body> (searches the whole document) if the nested-<main>
    structure ever changes, rather than raising. Always returns a
    WebElement (not the driver itself), since callers use both
    .find_elements(...) and .text on the result.
    """
    mains = driver.find_elements(By.TAG_NAME, "main")
    return mains[-1] if mains else driver.find_element(By.TAG_NAME, "body")


def _main_text(driver):
    root = _main_root(driver)
    return root.text


def _records_signature(records):
    """Comparable fingerprint of extracted records (labels + values)."""
    return tuple(
        (r.get("name", ""), tuple((f["label"], f["value"]) for f in r.get("fields", [])))
        for r in records
    )


def _scan_in_progress(input_el):
    """True while the tool's own submit button is disabled (scan running)."""
    try:
        buttons = input_el.find_elements(By.XPATH, "./ancestor::form//button[@type='submit']")
    except Exception:
        return False
    return bool(buttons) and buttons[0].get_attribute("disabled") is not None


def run_tool_search(tool_slug, query):
    """
    Logs into locateme.services and runs the given tool for one query value,
    returning {"toolSlug", "query", "found", "records": [...]} on a hit,
    {"toolSlug", "query", "found": False} on a clean miss, or
    {"toolSlug", "query", "found": True, "rawText": "..."} if the page
    rendered something but not in the expected card shape (untested tool,
    or a genuinely different result layout - see module docstring).

    rc-print and hp-gas-advanced are special-cased to delegate straight to
    rc_print.py's run_rc_print()/hp_gas.py's run_hp_gas_single() - both
    already proven in production (their own dedicated pages/APIs used
    these directly for weeks before being folded into Tracing 2.0 as tabs,
    2026-08-17) - rather than routing them through this module's generic
    scraper, which has already been caught guessing wrong on other tools'
    exact markup. No reason to risk two tools that already work.
    """
    if tool_slug not in TOOL_REGISTRY:
        raise ValueError(f"Unknown locateme.services tool: {tool_slug}")

    if tool_slug == "rc-print":
        result = run_rc_print(query)
        return {"toolSlug": tool_slug, "query": query, "found": True, "pdfDataUri": result["pdfDataUri"]}

    if tool_slug == "hp-gas-advanced":
        result = run_hp_gas_single(query)
        if not result.get("found"):
            return {"toolSlug": tool_slug, "query": query, "found": False}
        records = [
            {"name": s["title"], "status": "", "fields": s["fields"]}
            for s in result.get("sections", [])
        ]
        if not records:
            return {"toolSlug": tool_slug, "query": query, "found": True, "rawText": result.get("rawText", "")}
        return {"toolSlug": tool_slug, "query": query, "found": True, "records": records}

    driver = None
    try:
        driver = _create_locateme_driver()
        wait = WebDriverWait(driver, 20)

        _login(driver, wait)

        driver.get(f"{LOCATEME_BASE}/{tool_slug}")

        # Every tool page has exactly one real (non-hidden) input (confirmed
        # 2026-08-17 across all 23 remaining tools) - grabbing the first one
        # generically avoids hardcoding each tool's exact placeholder text,
        # which is cosmetic and has already been seen to vary tool-to-tool.
        input_el = wait.until(
            EC.presence_of_element_located((By.CSS_SELECTOR, "input:not([type='hidden'])"))
        )

        # What the page shows BEFORE searching (2026-10-03, confirmed live
        # on Indane Gas): the redesigned tool header is itself a two-<p>
        # title/subtitle pair, so the generic extractor "found" it instantly
        # and returned the page subtitle as the result - before the real
        # scan had even started - leaving every column blank. Anything
        # identical to this pre-search snapshot is never treated as a result.
        # Let the page finish drawing first - a snapshot taken mid-render
        # (header not painted yet) let the header through as "new" content,
        # blanking ~85 real agent searches on 2026-10-03.
        time.sleep(1.5)
        baseline = _records_signature(_extract_records(driver))
        baseline_text = _main_text(driver).lower()

        _type_and_submit(input_el, query)

        # Don't read anything until the scan has visibly started (the site
        # disables its own submit button while scanning) - up to 6s for it
        # to kick in; tools that never disable the button just proceed.
        submitted_at = time.time()
        started_by = time.time() + 6
        scan_started = False
        while time.time() < started_by:
            if _scan_in_progress(input_el):
                scan_started = True
                break
            time.sleep(0.25)

        # A scan now takes ~7-30s on the site's side (it was ~2-8s).
        deadline = time.time() + 45
        scan_done_at = None
        while time.time() < deadline:
            try:
                # The site disables its own submit button while a scan is in
                # flight - nothing on the page is final until it re-enables.
                if _scan_in_progress(input_el):
                    time.sleep(1)
                    continue
                if scan_started and scan_done_at is None:
                    scan_done_at = time.time()

                # Records first: a real result can itself contain words like
                # "not found"/"invalid" as field values (confirmed 2026-10-03,
                # a genuine hit was discarded as a miss when the failure-text
                # check ran first). Failure text only counts when no result.
                records = _extract_records(driver)
                if records and _records_signature(records) != baseline:
                    return {"toolSlug": tool_slug, "query": query, "found": True, "records": records}

                page_text = _main_text(driver)
                lower = page_text.lower()

                for needle in FAILURE_NEEDLES:
                    if needle in lower and needle not in baseline_text:
                        return {"toolSlug": tool_slug, "query": query, "found": False}

                # The scan has finished and still nothing new on the page:
                # that's how the redesigned site reports a miss (confirmed
                # live 2026-10-03 - a "retrieved successfully" toast, no
                # result card, no credits charged). A short grace period
                # covers the result card rendering just after the button
                # re-enables.
                if scan_done_at is not None and time.time() - scan_done_at > 6:
                    # A scan that ran right up to the site's own ~30s limit
                    # and produced nothing is the site's data source timing
                    # out, not a real "no record" (confirmed live 2026-10-03:
                    # genuine misses finish in ~7s, hits in ~7-10s, while a
                    # source outage made EVERY scan end at ~30-31s empty,
                    # even for numbers found an hour earlier). Reported as an
                    # error, so it isn't shown as "not found", doesn't count
                    # against the agent's quota and isn't cached.
                    if scan_done_at - submitted_at >= 28:
                        label = TOOL_REGISTRY.get(tool_slug, {}).get("label", "This tool")
                        raise RuntimeError(f"{label} source is not responding right now - please try again later.")
                    return {"toolSlug": tool_slug, "query": query, "found": False}
            except StaleElementReferenceException:
                # The page's own React app can swap DOM nodes out from under
                # us mid-read (confirmed 2026-08-17, live on Aadhaar to
                # Ration) while a result is still rendering - an element we
                # just located genuinely stopped existing between being
                # found and being read. Not a real failure, just caught
                # mid-update; the next iteration re-queries everything from
                # scratch, so retrying is always safe here.
                pass

            time.sleep(1)

        # Timed out without a clear card/section result or failure needle -
        # capture whatever's in the page's own <main> (excludes the
        # sidebar/topbar chrome - see _main_text()) rather than silently
        # reporting nothing (e.g. a tool whose result layout matches neither
        # extractor, like WhatsApp DP Downloader's image output - see module
        # docstring). Same stale-element risk as the loop above, so the same
        # tolerance applies - one retry is enough since nothing should still
        # be actively re-rendering once the 30s deadline has passed.
        try:
            return {"toolSlug": tool_slug, "query": query, "found": True, "rawText": _main_text(driver)}
        except StaleElementReferenceException:
            return {"toolSlug": tool_slug, "query": query, "found": True, "rawText": _main_text(driver)}

    finally:
        if driver is not None:
            _release_locateme_driver(driver)
