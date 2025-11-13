#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# mqtt-watchdog-bridge.sh
# Version 1.7 — simplified (health.json authoritative, $SYS optional)
# Author: Oliver L.
# =============================================================================

LOG_DIR="REPLACELBHOMEDIR/log/plugins/text2speech"
LOG_FILE="/dev/shm/text2speech/mqtt-watchdog.log"
CA="/etc/mosquitto/certs/mosq-ca.crt"
CERT="/etc/mosquitto/certs/clients/sip_bridge/client.crt"
KEY="/etc/mosquitto/certs/clients/sip_bridge/client.key"
FINDER_SOCK="/dev/shm/mqttfinder.sock"
FINDER_JSON="/dev/shm/mqttfinder.json"
GEN_JSON="REPLACELBHOMEDIR/config/system/general.json"
HEALTH_FILE="/dev/shm/text2speech/health.json"

SYS_WAIT="${SYS_WAIT:-5}"
SYS_FALLBACK_WAIT="${SYS_FALLBACK_WAIT:-10}"

mkdir -p "$LOG_DIR"; chmod 0755 "$LOG_DIR"
touch "$LOG_FILE"; chmod 0644 "$LOG_FILE"

NAME="mqtt-watchdog-bridge"

# -----------------------------------------------------------------------------
# Logging: only time (HH:MM:SS.mmm), includes milliseconds
# -----------------------------------------------------------------------------
timestamp() {
  date +"%H:%M:%S.%3N"
}

_log_emit() { # $1=LEVEL $2...=MESSAGE
  local lvl="$1"; shift
  printf '%s %s [%s] %s\n' "$(timestamp)" "$lvl" "$NAME" "$*" | tee -a "$LOG_FILE" >/dev/null
}

log() {
  local msg="$*"
  local lvl="<INFO>"
  case "$msg" in
    ✅*) lvl="<OK>" ;;
    ℹ️*) lvl="<INFO>" ;;
    ⚠️*) lvl="<WARNING>" ;;
    ❌*) lvl="<ERROR>" ;;
    🛑*) lvl="<DEB>" ;;
    *)   lvl="<DEB>" ;;
  esac
  [[ "$msg" == *"RESULT: OK"* ]] && lvl="OK"
  [[ "$msg" == *"RESULT: FAILED"* ]] && lvl="ERR"
  _log_emit "$lvl" "$msg"
}


ok=1
log "🛑 ==== MQTT mTLS healthcheck start ===="

MOSQ_ETC="/etc/mosquitto"
CONF_DIR="$MOSQ_ETC/conf.d"
T2S_HDR_RE='^[[:space:]]*#.*Auto-generated[[:space:]]+by[[:space:]]+Text2Speech[[:space:]]+Plugin'

# -----------------------------------------------------------------------------
# Role detection
# -----------------------------------------------------------------------------
port_8883_open() {
  ss -tnl 2>/dev/null | awk '{print $4}' | grep -qE '(:|\.|^)8883$'
}
is_our_master_conf() {
  shopt -s nullglob
  for c in "$CONF_DIR"/*.conf; do
    [ -r "$c" ] || continue
    if head -n 80 "$c" | sed 's/\r$//' | grep -Eiq "$T2S_HDR_RE"; then
      txt="$(sed -e 's/\r$//' -e '/^[[:space:]]*#/d' "$c")"
      echo "$txt" | grep -Eiq '\blistener[[:space:]]+8883\b'            || continue
      echo "$txt" | grep -Eiq '\brequire_certificate[[:space:]]+true\b' || continue
      echo "$txt" | grep -Eiq '\bcertfile[[:space:]]+/etc/mosquitto/certs/t2s\.crt\b' || continue
      echo "$txt" | grep -Eiq '\bkeyfile[[:space:]]+/etc/mosquitto/certs/t2s\.key\b'  || continue
      shopt -u nullglob
      return 0
    fi
  done
  shopt -u nullglob
  return 1
}
is_role_master() {
  [ -r "$MOSQ_ETC/tts-role/role" ] && grep -qi 'master' "$MOSQ_ETC/tts-role/role"
}

IS_MASTER=0
IS_BRIDGE=0
if is_role_master || is_our_master_conf; then IS_MASTER=1; fi
if [ -r "$CERT" ] && [ -r "$KEY" ]; then IS_BRIDGE=1; fi

if [ "$IS_MASTER" -eq 1 ] && [ "$IS_BRIDGE" -eq 1 ]; then
  MODE="HYBRID MASTER+BRIDGE mode"
elif [ "$IS_MASTER" -eq 1 ]; then
  MODE="LOCAL MASTER mode"
elif [ "$IS_BRIDGE" -eq 1 ]; then
  MODE="REMOTE BRIDGE mode"
else
  MODE="UNDEFINED mode"
fi

log "ℹ️ Detected operating mode: $MODE"

# -----------------------------------------------------------------------------
# Health.json analysis (authoritative for bridge activity)
# -----------------------------------------------------------------------------
if [ "$IS_MASTER" -eq 1 ] && [ -f "$HEALTH_FILE" ] && command -v jq >/dev/null 2>&1; then
  clients=$(jq -r 'keys_unsorted | join(", ")' "$HEALTH_FILE" 2>/dev/null || echo "")
  last_ts=$(jq -r 'to_entries | max_by(.value.timestamp) | .value.timestamp' "$HEALTH_FILE" 2>/dev/null || echo 0)
  last_iso=$(jq -r 'to_entries | max_by(.value.timestamp) | .value.iso_time' "$HEALTH_FILE" 2>/dev/null || echo "")
  if [[ "$last_ts" =~ ^[0-9]+$ ]] && [ "$last_ts" -gt 0 ]; then
    age=$(( $(date +%s) - last_ts ))
    if [ "$age" -le 600 ]; then
      log "✅ T2S Master actively used – last handshake $age seconds ago ($last_iso)"
      log "ℹ️ Active bridge clients: ${clients:-none}"
    else
      log "⚠️ T2S Master seems idle – last handshake $age seconds ago ($last_iso)"
    fi
  else
    log "⚠️ T2S Master health.json present but unreadable or empty"
  fi
elif [ "$IS_MASTER" -eq 1 ]; then
  log "⚠️ T2S Master configured but no health.json found"
fi

# -----------------------------------------------------------------------------
# Mosquitto service check
# -----------------------------------------------------------------------------
if systemctl is-active --quiet mosquitto; then
  log "✅ mosquitto.service active"
else
  log "❌ mosquitto.service NOT active"; ok=0
fi

# -----------------------------------------------------------------------------
# TLS handshake check (only if cert/key exist)
# -----------------------------------------------------------------------------
if [ -r "$CERT" ] && [ -r "$KEY" ] && port_8883_open; then
  if timeout 7 bash -lc "echo | openssl s_client -connect 127.0.0.1:8883 \
     -CAfile '$CA' -cert '$CERT' -key '$KEY' >/tmp/mtls_check.$$ 2>&1"; then
    if grep -q 'Verify return code: 0 (ok)' /tmp/mtls_check.$$; then
      log "✅ TLS handshake + verify OK"
    else
      log "❌ TLS handshake ran, but CA verify not OK"; ok=0
    fi
  else
    first_err="$(head -n2 /tmp/mtls_check.$$ 2>/dev/null | tr -d '\r' || true)"
    log "❌ TLS handshake failed (127.0.0.1:8883) — ${first_err:-unknown error}"
    ok=0
  fi
  rm -f /tmp/mtls_check.$$
else
  log "✅ Skipped TLS handshake (no client cert or port closed)"
fi

# -----------------------------------------------------------------------------
# Finder diagnostics
# -----------------------------------------------------------------------------
if [ -S "$FINDER_SOCK" ]; then
  if timeout 2 bash -lc "perl -MIO::Socket::UNIX -e '\$s=IO::Socket::UNIX->new(Type=>SOCK_STREAM,Peer=>q{$FINDER_SOCK}) or exit 1; print qq(socket-ok)'" >/dev/null 2>&1; then
    log "✅ mqttfinder.sock connect"
  else
    log "⚠️ mqttfinder.sock present but connect failed"
  fi
else
  log "⚠️ mqttfinder.sock missing"
fi

if [ -f "$FINDER_JSON" ]; then
  age=$(( $(date +%s) - $(stat -c %Y "$FINDER_JSON") ))
  size=$(stat -c %s "$FINDER_JSON")
  cnt="-"
  if command -v jq >/dev/null 2>&1; then
    cnt=$(jq '.incoming | length' "$FINDER_JSON" 2>/dev/null || echo "-")
  fi
  log "✅ mqttfinder.json size=${size}B age=${age}s incoming=${cnt}"
else
  log "⚠️ mqttfinder.json missing"
fi

# -----------------------------------------------------------------------------
# Final result (time only + milliseconds, clean output)
# -----------------------------------------------------------------------------
if [ "$ok" -eq 1 ]; then
  echo "$(timestamp) OK: [${NAME}] Watchdog finished without errors." | tee -a "$LOG_FILE" >/dev/null
  exit 0
else
  echo "$(timestamp) ERROR: [${NAME}] Watchdog finished with errors." | tee -a "$LOG_FILE" >/dev/null
  exit 1
fi

