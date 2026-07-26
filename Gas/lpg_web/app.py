from flask import Flask, request, jsonify, render_template

import json
import os
import threading
import time
import traceback
import urllib.error
import urllib.parse
import urllib.request
import uuid

from lpg_search import run_bulk_search

app = Flask(__name__)


# Written by the installer (lpg_tool_bootstrap.php) into this same directory
# at setup time - line 1 is this agent's per-user capability key (the SAME
# one lpg_autofill.php already uses), line 2 is the CRM's own base URL.
# Ties this specific installed copy to the specific agent who downloaded it
# (found 2026-07-26): re-checked against lpg_verify_user.php before every
# search, so disabling this account, revoking LPG access, letting it expire,
# or regenerating the key (Admin > Agents) all stop this copy from working -
# even if it's copied to a different computer, since it's a live server
# check, not a fact this file can carry on its own.
_AGENT_CONFIG_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "lpg_agent.cfg")


def _load_agent_config():
    try:
        # utf-8-sig (not plain utf-8) - strips a leading byte-order-mark if
        # one is present, harmless if not (found 2026-07-26: a BOM silently
        # prepended to the key made it fail the exact-64-hex-char check on
        # the CRM side with no obvious reason why, since it's invisible in
        # normal output - some tools/editors write UTF-8 files with a BOM
        # by default on Windows, so this is worth being defensive against
        # regardless of how this file ends up getting written).
        with open(_AGENT_CONFIG_PATH, "r", encoding="utf-8-sig") as f:
            lines = [line.strip() for line in f.readlines()]
        if len(lines) >= 2 and lines[0] and lines[1]:
            return {"key": lines[0], "verify_url": lines[1]}
    except OSError:
        pass
    return None


def _check_agent_active():
    """
    Returns (is_active, reason, username). A missing config file (an
    installed copy from before this check existed, or one that's been
    tampered with) is treated as NOT active rather than silently allowed
    through - this is meant to be an enforced gate, not an opt-in one.
    """
    config = _load_agent_config()
    if config is None:
        return False, "This computer's setup is out of date - download and run the installer again from the CRM.", None

    url = config["verify_url"] + "?k=" + urllib.parse.quote(config["key"])
    try:
        with urllib.request.urlopen(url, timeout=10) as resp:
            data = json.loads(resp.read().decode("utf-8"))
    except (urllib.error.URLError, TimeoutError, ValueError):
        # Can't reach the CRM to check - fails closed (blocks the search)
        # rather than open, consistent with this being an access control,
        # not a nice-to-have.
        return False, "Could not verify your account with the CRM - check your connection and try again.", None

    if not data.get("active"):
        # "logged out" (session_token cleared by logout.php - found
        # 2026-07-26) is the reason that matters most here: this is what
        # makes logging out of the CRM anywhere immediately stop this tool
        # from working too, next time the frontend polls agent-status.
        reason = {
            "logged out": "You have been logged out of the CRM - log in again to continue.",
        }.get(data.get("reason"), "Your CRM account is no longer active for LPG Search - contact your admin.")
        return False, reason, None

    return True, None, data.get("username")


# Chrome's Private Network Access (PNA) policy blocks a page loaded from a
# PUBLIC origin (the CRM at http://datasearch.in) from reaching a PRIVATE/
# local address (127.0.0.1) - both the iframe embed and the JS reachability
# fetch() count - unless this server explicitly grants permission via these
# response headers (found 2026-07-26). This is exactly why every server-side
# health check kept coming back clean (direct localhost access isn't crossing
# that public->private boundary at all, so it was never actually testing the
# thing that was broken) while the real CRM page, loaded from the public
# domain, still couldn't connect no matter how healthy the server was.
@app.after_request
def _allow_private_network_access(response):
    origin = request.headers.get("Origin")
    if origin:
        response.headers["Access-Control-Allow-Origin"] = origin
        response.headers["Access-Control-Allow-Private-Network"] = "true"
        response.headers["Vary"] = "Origin"
    return response


@app.route("/", methods=["OPTIONS"])
@app.route("/bulk", methods=["OPTIONS"])
@app.route("/api/lpg/bulk-search", methods=["OPTIONS"])
@app.route("/api/lpg/bulk-search/<job_id>", methods=["OPTIONS"])
@app.route("/api/lpg/agent-status", methods=["OPTIONS"])
def _pna_preflight(job_id=None):
    # Chrome sends its own dedicated preflight OPTIONS request for a private-
    # network access check before the real request is allowed to proceed,
    # separate from ordinary CORS preflighting - this just needs to answer
    # it with a 2xx so _allow_private_network_access's headers (added to
    # every response, including this one) can do their job.
    return ("", 204)


# In-memory job store: job_id -> {"status": ..., "total": int, "done": int, "results": [...]}
jobs = {}
jobs_lock = threading.Lock()

MAX_NUMBERS = 25

# Nothing ever removed a finished job from `jobs` - for a server that stays
# up for days/weeks, that's an unbounded memory leak, one entry per search
# ever run (found 2026-07-25). Anything finished more than an hour ago is
# pruned each time a new job starts - plenty of time for a client to still
# be polling a job it just kicked off, but not kept around forever.
JOB_RETENTION_SECONDS = 3600


def _prune_old_jobs():

    cutoff = time.time() - JOB_RETENTION_SECONDS

    with jobs_lock:
        stale_ids = [
            job_id for job_id, job in jobs.items()
            if job.get("finished_at") is not None and job["finished_at"] < cutoff
        ]
        for job_id in stale_ids:
            del jobs[job_id]

# Every bulk-search job spawns its own full Chrome+ChromeDriver process —
# nothing stopped multiple requests from doing this at once, and under real
# concurrent use that starved every session of resources badly enough that
# one real search sat at "processing" for a full 2 minutes before failing
# outright (found 2026-07-21, after this tool moved to running centrally on
# the server rather than one copy per computer, which makes concurrent use
# from different agents the normal case rather than rare). This forces every
# search through one at a time; others simply wait their turn instead of
# piling up and fighting each other for resources.
selenium_lock = threading.Lock()


def _run_job(job_id, numbers):

    def on_progress(done, total, latest_record):

        with jobs_lock:
            jobs[job_id]["done"] = done
            jobs[job_id]["results"].append(latest_record)

    try:

        with jobs_lock:
            jobs[job_id]["status"] = "queued" if selenium_lock.locked() else "processing"

        with selenium_lock:

            with jobs_lock:
                jobs[job_id]["status"] = "processing"

            run_bulk_search(numbers, progress_callback=on_progress)

        with jobs_lock:
            jobs[job_id]["status"] = "completed"
            jobs[job_id]["finished_at"] = time.time()

    except Exception as e:

        full_trace = traceback.format_exc()

        print(f"[job {job_id}] FAILED:\n{full_trace}")

        with jobs_lock:
            jobs[job_id]["status"] = "failed"
            jobs[job_id]["error"] = str(e)
            jobs[job_id]["traceback"] = full_trace
            jobs[job_id]["finished_at"] = time.time()


@app.route("/")
def index():
    return render_template("index.html")


@app.route("/bulk")
def bulk():
    # Separate page from "/" (found 2026-07-24) - "/" is the single-number
    # quick search, this is the original multi-number textarea UI, restored
    # as its own page rather than folded back into "/". Both hit the same
    # /api/lpg/bulk-search API, which already accepts any number of numbers
    # up to MAX_NUMBERS - no backend change needed, just a second template.
    return render_template("bulk.html")


@app.route("/api/lpg/agent-status", methods=["GET"])
def agent_status():
    # Polled by the frontend every few seconds (found 2026-07-26) to show
    # which CRM account this installed copy belongs to, and to notice a
    # logout in real time rather than only finding out the next time a
    # search is attempted.
    is_active, reason, username = _check_agent_active()
    return jsonify({"active": is_active, "reason": reason, "username": username})


@app.route("/api/lpg/bulk-search", methods=["POST"])
def start_bulk_search():

    is_active, reason, _username = _check_agent_active()
    if not is_active:
        return jsonify({"error": reason}), 403

    data = request.get_json(silent=True) or {}
    numbers = data.get("numbers", [])

    numbers = [str(n).strip() for n in numbers if str(n).strip()]

    if not numbers:
        return jsonify({"error": "No numbers provided"}), 400

    numbers = numbers[:MAX_NUMBERS]

    _prune_old_jobs()

    job_id = uuid.uuid4().hex

    with jobs_lock:
        jobs[job_id] = {
            "status": "processing",
            "total": len(numbers),
            "done": 0,
            "results": [],
            "finished_at": None
        }

    thread = threading.Thread(target=_run_job, args=(job_id, numbers), daemon=True)
    thread.start()

    return jsonify({"jobId": job_id, "status": "processing"})


@app.route("/api/lpg/bulk-search/<job_id>", methods=["GET"])
def get_bulk_search(job_id):

    with jobs_lock:
        job = jobs.get(job_id)

        if not job:
            return jsonify({"error": "Job not found"}), 404

        return jsonify({
            "status": job["status"],
            "total": job["total"],
            "done": job["done"],
            "results": job["results"],
            "error": job.get("error")
        })


if __name__ == "__main__":
    # threaded=True so a status poll isn't blocked behind an in-progress
    # login/search request (each job itself already runs on its own thread —
    # this just lets the dev server accept more than one request at a time).
    app.run(debug=True, port=9196, threaded=True)
