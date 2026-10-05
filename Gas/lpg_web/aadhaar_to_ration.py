from tracing2_tools import run_tool_search, FAILURE_NEEDLES

TOOL_SLUG = "aadhaar-to-ration"


def _looks_like_a_miss(records):
    """
    run_tool_search()'s generic scraper checks the page's own text for
    FAILURE_NEEDLES before extracting field cards, but confirmed live
    2026-09-04: that check can lose the race against extraction - a "NO DATA
    FOUND" or "Connection to registry nodes failed. Please check your
    network." message rendered as this tool's own field-pair card gets
    scraped as if it were real data (found: true) on some runs, while an
    identical-shaped miss correctly returns found: false on others. Not
    reliably one or the other, so a dedicated, count-against-quota tool
    can't trust run_tool_search()'s own found flag alone - this re-checks
    every extracted field VALUE against the same needle list as a second,
    belt-and-suspenders pass.
    """
    for record in records:
        for field in record.get("fields", []):
            value_lower = str(field.get("value", "")).lower()
            for needle in FAILURE_NEEDLES:
                if needle in value_lower:
                    return True
    return False


def run_aadhaar_to_ration(aadhaar_number):
    """
    Looks up a Ration Card record for an Aadhaar number via locateme.services'
    Aadhaar to Ration Finder, reusing tracing2_tools.py's own generic
    scraper (already fixed for this tool's specific card shape - see that
    module's own history) rather than a second, separate implementation.
    Returns {"aadhaarNumber", "found": True, "records": [...]} on a hit or
    {"aadhaarNumber", "found": False} on a miss.
    """
    result = run_tool_search(TOOL_SLUG, aadhaar_number)

    if not result.get("found"):
        return {"aadhaarNumber": aadhaar_number, "found": False}

    records = result.get("records") or []
    if not records or _looks_like_a_miss(records):
        return {"aadhaarNumber": aadhaar_number, "found": False}

    return {"aadhaarNumber": aadhaar_number, "found": True, "records": records}
