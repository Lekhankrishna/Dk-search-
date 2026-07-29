from flask import Flask, request, jsonify

import threading
import time
import traceback
import uuid

from lpg_search import run_bulk_search

app = Flask(__name__)

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

# In-memory job store: job_id -> {"status": ..., "total": int, "done": int, "results": [...]}
jobs = {}
jobs_lock = threading.Lock()

MAX_NUMBERS = 25

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


# Every search spawns its own full Chrome+ChromeDriver process - nothing
# stops multiple requests from doing this at once, and concurrent Selenium
# sessions fighting over the same machine's resources caused searches to
# sit at "processing" for minutes before failing outright. This forces
# every search through one at a time; others simply wait their turn.
# Centralizing this service (found 2026-07-27) makes this queue shared
# across every agent, not just concurrent requests from one computer - a
# real trade-off for no longer needing a per-computer install, worth
# revisiting if search volume ever makes the queue a bottleneck in practice.
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


@app.route("/api/search", methods=["POST"])
def start_search():
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
            "finished_at": None,
        }

    threading.Thread(target=_run_job, args=(job_id, numbers), daemon=True).start()

    return jsonify({"jobId": job_id, "status": "processing"})


@app.route("/api/search/<job_id>", methods=["GET"])
def get_search(job_id):
    with jobs_lock:
        job = jobs.get(job_id)

        if not job:
            return jsonify({"error": "Job not found"}), 404

        return jsonify({
            "status": job["status"],
            "total": job["total"],
            "done": job["done"],
            "results": job["results"],
            "error": job.get("error"),
        })


if __name__ == "__main__":
    # host="127.0.0.1" (loopback only, not 0.0.0.0) - this must never be
    # reachable from outside this machine, since it has no auth of its own
    # any more (PHP's requireLpgSearchAccess() is what gates access now).
    # debug=False - this runs continuously as server infrastructure rather
    # than a locally-invoked convenience tool, so Werkzeug's interactive
    # debugger (which can execute arbitrary code from a stack trace page)
    # has no business being enabled here even behind loopback.
    app.run(host="127.0.0.1", debug=False, port=9196, threaded=True)
