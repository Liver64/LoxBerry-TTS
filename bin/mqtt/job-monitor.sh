#!/bin/bash
# ================================================================
# mqtt-job-monitor – Monitoring for ALSA + mpg123 + tsp
# Format: HH:MM:SS.mmm <LEVEL> [job-monitor] Message
# LEVEL: OK | INFO | WARN | ERR | DEB
# ================================================================

set -o pipefail
cd / || exit 1

NAME="job-monitor"
LOGFILE="/dev/shm/text2speech/mqtt-watchdog.log"

MAX_RUNNING_JOBS=3
MAX_JOB_RUNTIME=120
TS_SOCKET="/run/shm/ttsplugin.sock"
export TS_SOCKET

# --- Minimal-Logger (GENAU so verwenden: logmsg "OK" "Text") ---
logmsg() { # usage: logmsg LOGLEVEL "text ..."
    local lvl="$1"; shift
    local msg="$*"
    # Format: HH:MM:SS.mmm <LEVEL> [NAME] Message
    local timestamp
    timestamp=$(date +"%H:%M:%S.%3N")
    printf '%s <%s> [%s] %s\n' "$timestamp" "$lvl" "$NAME" "$msg" >> "$LOGFILE"
}

# --- Sanity Checks ---
if ! command -v tsp >/dev/null 2>&1; then
  logmsg "ERR"  "Task Spooler (tsp) not found in PATH. Aborting"
  logmsg "WARN" "TTS Monitor: tsp binary missing. Monitoring aborted"
  exit 1
fi
if ! tsp >/dev/null 2>&1; then
  logmsg "ERR"  "Task Spooler not running or socket missing ($TS_SOCKET). Aborting"
  logmsg "WARN" "TTS Monitor: Task Spooler not running. Monitoring aborted"
  exit 1
fi

# --- Ensure tsp socket/daemon exists (short-living mode) ---
if [ ! -S "$TS_SOCKET" ]; then
  logmsg "INFO" "tsp socket missing ($TS_SOCKET) – starting temporary tsp daemon"
  tsp >/dev/null 2>&1 &
  sleep 1
fi

# --- Funktionen ---
cleanup_finished() {
  local finished_count
  finished_count=$(tsp -l 2>/dev/null | awk '$2=="finished"{c++} END{print c+0}')
  if [ "${finished_count:-0}" -gt 0 ]; then
    tsp -C
    logmsg "DEB" "Cleaned up ${finished_count} finished jobs"
  else
    logmsg "DEB"  "No finished jobs to clean"
  fi
}

check_running_jobs() {
  local running_jobs
  running_jobs=$(tsp -l 2>/dev/null | awk '$2=="running"{c++} END{print c+0}')
  running_jobs=${running_jobs:-0}

  logmsg "DEB" "Currently running jobs: ${running_jobs}"

  if [ "$running_jobs" -gt "$MAX_RUNNING_JOBS" ]; then
    local oldest_id
    oldest_id=$(tsp -l | awk '$2=="running"{print $1; exit}')
    if [[ "$oldest_id" =~ ^[0-9]+$ ]]; then
      tsp -k "$oldest_id"
      logmsg "WARN" "Too many running jobs (${running_jobs}). Killed oldest job ID ${oldest_id}"
    else
      logmsg "ERR"  "Could not determine oldest running job ID."
    fi
  fi
}

check_job_runtimes() {
  mapfile -t running_ids < <(tsp -l 2>/dev/null | awk '$2=="running"{print $1}')
  if [ "${#running_ids[@]}" -eq 0 ]; then
    logmsg "DEB" "No running jobs."
    return
  fi

  for job_id in "${running_ids[@]}"; do
    local pid etimes
    pid=$(tsp -p "$job_id" 2>/dev/null | tr -d '[:space:]')
    if ! [[ "$pid" =~ ^[0-9]+$ ]]; then
      logmsg "ERR" "Could not read PID for job ${job_id} (got: '${pid}'). Skipping"
      continue
    fi

    etimes=$(ps -o etimes= -p "$pid" 2>/dev/null | tr -d '[:space:]')
    if ! [[ "$etimes" =~ ^[0-9]+$ ]]; then
      logmsg "DEB" "Process for job ${job_id} (pid ${pid}) vanished while checking"
      continue
    fi

    if [ "$etimes" -gt "$MAX_JOB_RUNTIME" ]; then
      tsp -k "$job_id"
      logmsg "WARN" "Job ${job_id} (pid ${pid}) running for ${etimes}s. Killed (timeout ${MAX_JOB_RUNTIME}s)."
    else
      logmsg "DEB" "Job ${job_id} (pid ${pid}) runtime ${etimes}s"
    fi
  done
}

# --- Main ---
logmsg "OK" "Monitoring started"
cleanup_finished
check_running_jobs
check_job_runtimes
logmsg "OK" "Monitoring completed"
