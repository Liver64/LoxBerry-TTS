#!/usr/bin/env bash
# detect_bridge_config.sh — strict guard-rail before installing T2S Master
# Hard abort inside this script for any Client or Mixed state.
#
# Exit codes:
#   0 = OK (clean or master_only)
#   2 = ABORT (client_only or mixed_conflict)
#   3 = indeterminate/unexpected

set -euo pipefail

QUIET=0
DEBUG=0
AS_JSON=0

log()  { [[ $QUIET -eq 0 ]] && echo "$@"; }
dbg()  { [[ $DEBUG -eq 1 ]] && echo "$@"; }

usage() {
  cat <<'EOF'
Usage: detect_bridge_config.sh [options]

Exit codes:
  0 = OK (clean/master_only)
  2 = ABORT (client_only or mixed_conflict)
  3 = indeterminate/unexpected

Options:
  -q, --quiet   Minimal output
  -d, --debug   Verbose output (lists hits)
  -j, --json    Print JSON summary
  -h, --help    This help
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    -q|--quiet) QUIET=1; shift ;;
    -d|--debug) DEBUG=1; shift ;;
    -j|--json)  AS_JSON=1; shift ;;
    -h|--help)  usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; exit 3 ;;
  esac
done

# --- Paths (read-only) ---
CONF_D="/etc/mosquitto/conf.d"
CERTS="/etc/mosquitto/certs"

# --- Strict client markers (these two decide "client present") ---
CLIENT_FILES=(
  "$CERTS/sip_bridge.crt"
  "$CERTS/sip_bridge.key"
)

# --- Master markers (no ca/private needed; safe for user 'loxberry') ---
MASTER_FILES=(
  "$CONF_D/10-listener-tls.conf"
  "$CONF_D/00-global-per-listener.conf"
  "$CERTS/t2s.crt"
  "$CERTS/t2s.key"
  "$CERTS/clients"
  "/etc/mosquitto/tts-aclfile"
)

client_hits=()
master_hits=()

for p in "${CLIENT_FILES[@]}"; do
  [[ -e "$p" ]] && client_hits+=("$p")
done
for p in "${MASTER_FILES[@]}"; do
  [[ -e "$p" ]] && master_hits+=("$p")
done

dbg "Client hits (${#client_hits[@]}):"
for f in "${client_hits[@]:-}"; do dbg "  - $f"; done
dbg "Master hits (${#master_hits[@]}):"
for f in "${master_hits[@]:-}"; do dbg "  - $f"; done

state="indeterminate"
rc=3
advice=""

if (( ${#client_hits[@]} == 0 && ${#master_hits[@]} == 0 )); then
  state="clean"; rc=0
  advice="No artifacts detected. Safe to proceed."
elif (( ${#client_hits[@]} > 0 && ${#master_hits[@]} == 0 )); then
  state="client_only"; rc=2
  advice="Client artifacts detected (sip_bridge.*). Abort and uninstall Client before installing T2S Master."
elif (( ${#client_hits[@]} == 0 && ${#master_hits[@]} > 0 )); then
  state="master_only"; rc=0
  advice="Master artifacts present without Client mix. OK to continue Master-side operations."
else
  state="mixed_conflict"; rc=2
  advice="MIXED state detected (Client + Master). Abort and clean up Client artifacts before proceeding."
fi

if [[ $AS_JSON -eq 1 ]]; then
  json_arr() {
    local -n arr=$1; local first=1; printf '['
    for it in "${arr[@]:-}"; do
      local esc=${it//\\/\\\\}; esc=${esc//\"/\\\"}
      (( first )) || printf ','
      first=0; printf '"%s"' "$esc"
    done; printf ']'
  }
  printf '{'
  printf '"state":"%s","exit_code":%d,' "$state" "$rc"
  printf '"client_found":'; json_arr client_hits; printf ','
  printf '"master_found":'; json_arr master_hits; printf ','
  local esc=${advice//\\/\\\\}; esc=${esc//\"/\\\"}
  printf '"advice":"%s"}\n' "$esc"
fi

if [[ $QUIET -eq 0 ]]; then
  log "<INFO> ===== Mosquitto/T2S Role Detection ====="
  log "<INFO> State      : $state"
  log "<INFO> Exit code  : $rc"
  if (( ${#client_hits[@]} )); then
    log "<INFO> Client artifacts:"; for f in "${client_hits[@]}"; do log "  - $f"; done
  else
    log "<INFO> Client artifacts: none"
  fi
  if (( ${#master_hits[@]} )); then
    log "<INFO> Master artifacts:"; for f in "${master_hits[@]}"; do log "  - $f"; done
  else
    log "<INFO> Master artifacts: none"
  fi
  log "<INFO> Advice     : $advice"
fi

# --- HARD ABORT here for client/mixed ---
if (( rc == 2 )); then
  echo "<CRIT> Aborting: $state" >&2
  exit 2
fi

exit $rc
