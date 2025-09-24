#!/bin/bash
# ================================================================
# monitor_tts.sh - Monitoring for ALSA + mpg123 + tsp
# Logging direkt ins bestehende monitor.log (vom Daemon erzeugt)
# Timestamp + Loglevel + Message
# ================================================================

set -o pipefail

# Immer in ein existierendes Verzeichnis wechseln
cd / || exit 1

# --- Variablen ---
LOGFILE="${LBPLOG}/text2speech/monitor.log"
MAX_RUNNING_JOBS=3
MAX_JOB_RUNTIME=120
TS_SOCKET="/run/shm/ttsplugin.sock"
export TS_SOCKET

# --- Log-Guard: nur umleiten, wenn beschreibbar ---
mkdir -p "$(dirname "$LOGFILE")"
if [ ! -e "$LOGFILE" ]; then
  # versucht es, falls DAEMON noch nicht angelegt hat
  : >"$LOGFILE" 2>/dev/null || true
fi
if [ -w "$LOGFILE" ]; then
  exec >>"$LOGFILE" 2>&1
else
  echo "WARN: $LOGFILE not writable; logging to stdout" >&2
fi

# --- Timestamp-Helfer ---
_ts() { date +"%d.%m.%Y %H:%M:%S"; }

# --- Logging-Funktionen ---
DEB()   { echo "$(_ts) <DEBUG>: $*"; }
INF()   { echo "$(_ts) <INFO>: $*"; }
OK_()   { echo "$(_ts) <OK>: $*"; }
WARN()  { echo "$(_ts) <WARNING>: $*"; }
ERR()   { echo "$(_ts) <ERROR>: $*"; }
ALERT() { echo "$(_ts) <ALERT>: $*"; }
OK()    { OK_ "$@"; }

# --- Sanity Checks ---
if ! command -v tsp >/dev/null 2>&1; then
  ERR   "Task Spooler (tsp) not found in PATH. Aborting"
  ALERT "TTS Monitor Alert: tsp binary missing. Monitoring aborted"
  exit 1
fi
if ! tsp >/dev/null 2>&1; then
  ERR   "Task Spooler not running or socket missing ($TS_SOCKET). Aborting"
  ALERT "TTS Monitor Alert: Task Spooler not running. Monitoring aborted"
  exit 1
fi


# --- Funktionen ---
cleanup_finished() {
    local finished_count
    finished_count=$(tsp -l 2>/dev/null | awk '$2=="finished"{c++} END{print c+0}')
    if [ "${finished_count:-0}" -gt 0 ]; then
        tsp -C
        INF "Cleaned up $finished_count finished jobs"
    else
        DEB "No finished jobs to clean"
    fi
}

check_running_jobs() {
    local running_jobs
    running_jobs=$(tsp -l 2>/dev/null | awk '$2=="running"{c++} END{print c+0}')
    running_jobs=${running_jobs:-0}

    DEB "Currently running jobs: $running_jobs"

    if [ "$running_jobs" -gt "$MAX_RUNNING_JOBS" ]; then
        local oldest_id
        oldest_id=$(tsp -l | awk '$2=="running"{print $1; exit}')
        if [[ "$oldest_id" =~ ^[0-9]+$ ]]; then
            tsp -k "$oldest_id"
            WARN  "Too many running jobs ($running_jobs). Killed oldest job ID $oldest_id"
            ALERT "TTS Monitor Alert: Too many jobs running ($running_jobs). Oldest job killed (ID $oldest_id)"
        else
            ERR "Could not determine oldest running job ID."
        fi
    fi
}

check_job_runtimes() {
    mapfile -t running_ids < <(tsp -l 2>/dev/null | awk '$2=="running"{print $1}')

    if [ "${#running_ids[@]}" -eq 0 ]; then
        DEB "No running jobs."
        return
    fi

    for job_id in "${running_ids[@]}"; do
        local pid
        pid=$(tsp -p "$job_id" 2>/dev/null | tr -d '[:space:]')
        if ! [[ "$pid" =~ ^[0-9]+$ ]]; then
            ERR "Could not read PID for job $job_id (got: '$pid'). Skipping"
            continue
        fi

        local etimes
        etimes=$(ps -o etimes= -p "$pid" 2>/dev/null | tr -d '[:space:]')
        if ! [[ "$etimes" =~ ^[0-9]+$ ]]; then
            DEB "Process for job $job_id (pid $pid) vanished while checking"
            continue
        fi

        if [ "$etimes" -gt "$MAX_JOB_RUNTIME" ]; then
            tsp -k "$job_id"
            WARN  "Job $job_id (pid $pid) running for ${etimes}s. Killed due to timeout (${MAX_JOB_RUNTIME}s)."
            ALERT "TTS Monitor Alert: Job $job_id exceeded ${MAX_JOB_RUNTIME}s (was ${etimes}s). Killed"
        else
            DEB "Job $job_id (pid $pid) runtime ${etimes}s"
        fi
    done
}

# --- Main Execution ---
OK_ "Monitoring started"
cleanup_finished
check_running_jobs
check_job_runtimes
OK_ "Monitoring completed"
