from flask import Flask, request, jsonify

import threading
import time
import traceback
import uuid

from lpg_search import run_bulk_search
from rc_print import run_rc_print
from hp_gas import run_hp_gas_bulk

app = Flask(__name__)


def clean_error_message(e):
    """
    Selenium's WebDriverException (and friends) dump their entire native
    ChromeDriver stacktrace - dozens of lines of "chromedriver!GetHandleVerifier
    [0x...]" - straight into str(e), since that IS the exception's message,
    not something separate a caller can opt out of. Left as-is that lands
    verbatim in the JSON error field and gets rendered straight into the
    page (found 2026-08-08 live) - useless and alarming to whoever's running
    a search, not just ugly. The real detail is still in this server's own
    console log (see the traceback.format_exc() print right before this is
    called) for whoever's actually debugging it; this is only what the
    agent using the tool sees.
    """
    first_line = str(e).split("\n", 1)[0].split("Stacktrace:", 1)[0].strip()
    # Selenium's own WebDriverException.__str__ prefixes even a genuinely
    # empty message with "Message:" - stripping it bare (found 2026-08-08,
    # a real crash on this server left literally "Message:" as the only
    # visible text) so an empty message actually falls through to the
    # generic fallback below instead of showing that half-formed leftover.
    if first_line.lower().startswith("message:"):
        first_line = first_line[len("message:"):].strip()
    return first_line or "An unexpected error occurred. Please try again or contact your admin."

# Runs centrally on the CRM server itself (found 2026-07-27) rather than one
# copy per agent's computer - lpg_search_api.php (server-side PHP, not the
# browser) is the only caller, reaching this over plain loopback HTTP, so
# none of the per-computer machinery the old design needed applies here:
# no browser-facing Private Network Access headers (PHP's outgoing request
# isn't a browser reaching into a private address space at all), no
# per-agent capability key / device-claim check (PHP already knows who's
# logged in via requireLpgSearchAccess() before it ever calls this), no
# heartbeat-based self-shutdown (this is meant to run continuously as
# server infrastructure, not something a closed browser window should stop).

# In-memory job store, shared by LPG bulk search and HP Gas bulk search
# (added 2026-08-11) - both are "numbers in, progress-tracked results out"
# jobs with an identical status shape, so one dict/lock/runner serves both
# rather than duplicating the whole queue/prune/semaphore-counting machinery
# a second time. job_id -> {"status": ..., "total": int, "done": int, "results": [...]}
jobs = {}
jobs_lock = threading.Lock()

MAX_NUMBERS = 10

# HP Gas credits are far more expensive per search (150/search vs SDMS's
# effectively free lookups) and agents' own monthly quotas default to just 5
# (see database/migrate_add_hp_gas_monthly_limit.sql) - 10 is a flat usage
# cap here too (same reasoning as MAX_NUMBERS above), hp_gas_api.php's own
# per-batch quota check is what actually stops a batch a given agent can't
# afford.
HP_GAS_MAX_NUMBERS = 10

# Raised from 25 to 500 (2026-08-19, admin-only per explicit request). Must
# match lpg_search.py's own hardcoded `mobile_numbers[:500]` inside
# run_bulk_search() - can't import this constant there (app.py already
# imports FROM lpg_search.py, so the reverse import would be circular), so
# the two numbers have to be kept in sync by hand instead. Whichever number
# wins here decides what "total" gets set to below; if run_bulk_search
# truncates to something smaller, a job finishes with done < total forever -
# looks exactly like the search got stuck partway through (found 2026-08-05
# live, when these two numbers first drifted apart at 25 vs an uncapped
# admin submission of 162).
MAX_NUMBERS_ADMIN = 500

# Nothing ever removed a finished job from `jobs` - for a server that stays
# up for days/weeks, that's an unbounded memory leak, one entry per search
# ever run. Anything finished more than an hour ago is pruned each time a
# new job starts - plenty of time for a client to still be polling a job it
# just kicked off, but not kept around forever.
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


# Every search spawns its own full Chrome+ChromeDriver process, each with
# its own independent Selenium driver instance (run_bulk_search() creates a
# fresh one per call, and lpg_search.py has no shared mutable module state -
# confirmed 2026-07-28 before relying on that for real concurrency) - so
# running several at once is safe as far as the code goes. What isn't safe
# is running unboundedly many at once: this machine has 4 logical CPU cores
# total, shared with IIS/MySQL for the rest of the CRM, and measured
# (2026-07-28) at roughly one logical core and ~400MB RAM per concurrent
# Chrome session. A semaphore caps how many run truly simultaneously; any
# more than that queue, same as the old single-lock version did for
# everything. 4 is the top of the range that testing showed the CPU can
# absorb without visibly slowing the rest of the CRM - drop to 3 in
# MAX_CONCURRENT_SEARCHES below if this server ever feels sluggish under
# load with all 4 slots busy.
MAX_CONCURRENT_SEARCHES = 4
selenium_semaphore = threading.Semaphore(MAX_CONCURRENT_SEARCHES)

# How many jobs are CURRENTLY running (inside the semaphore), not just
# queued - used only to decide the "queued" vs "processing" label a job
# starts with; the semaphore itself is what actually enforces the cap
# regardless of this counter's exact value.
_active_count = 0
_active_count_lock = threading.Lock()


def _run_job(job_id, numbers, search_fn):

    def on_progress(done, total, latest_record):
        with jobs_lock:
            jobs[job_id]["done"] = done
            jobs[job_id]["results"].append(latest_record)

    global _active_count
    try:
        with _active_count_lock:
            starts_queued = _active_count >= MAX_CONCURRENT_SEARCHES
        with jobs_lock:
            jobs[job_id]["status"] = "queued" if starts_queued else "processing"

        with selenium_semaphore:
            with _active_count_lock:
                _active_count += 1
            with jobs_lock:
                jobs[job_id]["status"] = "processing"
            try:
                search_fn(numbers, progress_callback=on_progress)
            finally:
                with _active_count_lock:
                    _active_count -= 1

        with jobs_lock:
            jobs[job_id]["status"] = "completed"
            jobs[job_id]["finished_at"] = time.time()

    except Exception as e:
        full_trace = traceback.format_exc()
        print(f"[job {job_id}] FAILED:\n{full_trace}")
        with jobs_lock:
            jobs[job_id]["status"] = "failed"
            jobs[job_id]["error"] = clean_error_message(e)
            jobs[job_id]["traceback"] = full_trace
            jobs[job_id]["finished_at"] = time.time()


def _start_job(numbers, search_fn):
    _prune_old_jobs()

    job_id = uuid.uuid4().hex

    with jobs_lock:
        jobs[job_id] = {
            "status": "processing",
            "total": len(numbers),
            "done": 0,
            "results": [],
            "finished_at": None,
            # Flips true the first time _get_job() reports this job as
            # "completed" - lets a caller (hp_gas_api.php) bill/log each
            # result exactly once even if the same completed job is somehow
            # polled again (a browser retry, a duplicate tab), without this
            # Flask service needing to know anything about quotas or
            # search_logs itself.
            "delivered": False,
        }

    threading.Thread(target=_run_job, args=(job_id, numbers, search_fn), daemon=True).start()

    return job_id


def _get_job(job_id):
    with jobs_lock:
        job = jobs.get(job_id)

        if not job:
            return None

        # How many other still-active jobs were submitted before this one -
        # without this, a queued job just says "waiting" with no sense of
        # how long, which is exactly what drove a real backlog (found
        # 2026-07-28): searches take a while, so someone waiting with no
        # indication of progress kept re-submitting (or hitting the Refresh
        # button, which resets the page's own state but does nothing to the
        # job still queued server-side), piling up dozens of duplicate jobs
        # that made the wait even longer for everyone. `jobs` is insertion-
        # ordered (plain dict, Python 3.7+), so counting prior still-active
        # entries gives an honest count. Subtracting (MAX_CONCURRENT_SEARCHES
        # - 1) accounts for the worker pool (found 2026-07-28, added
        # alongside it): with 4 concurrent slots, being 4th-in-submission-
        # order behind 3 already-running jobs means "next up", not "wait for
        # 4 whole turns" - the old single-lock version's raw count already
        # meant literally that, but stayed correct for a pool by this
        # adjustment.
        queue_position = None
        if job["status"] == "queued":
            ahead = 0
            for jid, j in jobs.items():
                if jid == job_id:
                    break
                if j["status"] in ("queued", "processing"):
                    ahead += 1
            queue_position = max(0, ahead - MAX_CONCURRENT_SEARCHES + 1)

        newly_completed = job["status"] == "completed" and not job["delivered"]
        if newly_completed:
            job["delivered"] = True

        return {
            "status": job["status"],
            "total": job["total"],
            "done": job["done"],
            "results": job["results"],
            "error": job.get("error"),
            "queuePosition": queue_position,
            "newlyCompleted": newly_completed,
        }


@app.route("/api/search", methods=["POST"])
def start_search():
    data = request.get_json(silent=True) or {}
    numbers = data.get("numbers", [])
    numbers = [str(n).strip() for n in numbers if str(n).strip()]

    if not numbers:
        return jsonify({"error": "No numbers provided"}), 400

    # lpg_search_api.php sets this from the caller's actual session role (not
    # trusted from anywhere else reachable - this endpoint only ever hears
    # from that PHP proxy over loopback, per the module-level comment above)
    # so admins can run batches larger than the normal agent cap.
    numbers = numbers[:MAX_NUMBERS_ADMIN] if data.get("isAdmin") else numbers[:MAX_NUMBERS]

    job_id = _start_job(numbers, run_bulk_search)
    return jsonify({"jobId": job_id, "status": "processing"})


@app.route("/api/search/<job_id>", methods=["GET"])
def get_search(job_id):
    job = _get_job(job_id)
    if job is None:
        return jsonify({"error": "Job not found"}), 404
    return jsonify(job)


@app.route("/api/hp-gas/search", methods=["POST"])
def start_hp_gas_search():
    data = request.get_json(silent=True) or {}
    numbers = data.get("numbers", [])
    numbers = [str(n).strip() for n in numbers if str(n).strip()][:HP_GAS_MAX_NUMBERS]

    if not numbers:
        return jsonify({"error": "No numbers provided"}), 400

    job_id = _start_job(numbers, run_hp_gas_bulk)
    return jsonify({"jobId": job_id, "status": "processing"})


@app.route("/api/hp-gas/search/<job_id>", methods=["GET"])
def get_hp_gas_search(job_id):
    job = _get_job(job_id)
    if job is None:
        return jsonify({"error": "Job not found"}), 404
    return jsonify(job)


@app.route("/api/rc-print", methods=["POST"])
def rc_print():
    data = request.get_json(silent=True) or {}
    vehicle_number = str(data.get("vehicleNumber", "")).strip()

    if not vehicle_number:
        return jsonify({"error": "No vehicle number provided"}), 400

    # Single lookup, run synchronously (unlike /api/search's job-queue +
    # polling, which exists for batches of up to 500 numbers) - one vehicle
    # in, one PDF out, so there's nothing to report incremental progress on.
    # Shares selenium_semaphore with LPG bulk search so the two tools'
    # Chrome sessions never together exceed MAX_CONCURRENT_SEARCHES on this
    # box's fixed core count.
    with selenium_semaphore:
        try:
            result = run_rc_print(vehicle_number)
        except Exception as e:
            full_trace = traceback.format_exc()
            print(f"[rc-print {vehicle_number}] FAILED:\n{full_trace}")
            return jsonify({"error": clean_error_message(e)}), 502

    return jsonify(result)


if __name__ == "__main__":
    # host="127.0.0.1" (loopback only, not 0.0.0.0) - this must never be
    # reachable from outside this machine, since it has no auth of its own
    # any more (PHP's requireLpgSearchAccess() is what gates access now).
    # debug=False - this runs continuously as server infrastructure rather
    # than a locally-invoked convenience tool, so Werkzeug's interactive
    # debugger (which can execute arbitrary code from a stack trace page)
    # has no business being enabled here even behind loopback.
    # Port changed from 9196 to 9197 (found 2026-07-28): 9196 got stuck in a
    # state where netstat/Get-NetTCPConnection both showed a LISTENING
    # socket + accumulating CLOSE_WAIT connections owned by a PID that had
    # no corresponding process at all (Get-Process, WMI, and taskkill all
    # agreed it didn't exist) - an orphaned kernel-level socket surviving
    # its own process, most likely a handle left behind by the repeated
    # Start-Process/kill cycles during testing that day. Moving off the
    # port sidesteps it outright rather than waiting on Windows' own
    # cleanup or a reboot.
    app.run(host="127.0.0.1", debug=False, port=9197, threaded=True)
