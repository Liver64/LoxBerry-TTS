#!/usr/bin/env bash
# handshake_test.sh — End-to-end Test für den T2S Handshake (mTLS/Bridge)
# Exit 0 = OK mit Response, !=0 Fehler/Timeout

set -euo pipefail

# --- Umgebung/Hosts ---
MASTER_IP_DEFAULT="192.168.50.171"
LB1_HOST="loxberry-wohn"
LB2_HOST="loxberry-dev12"

PORT=8883
TOPIC_REQ="tts-handshake/request"
TOPIC_RESP_BASE="tts-handshake/response"

MODE="auto"     # auto|local|bridge|remote
TIMEOUT=12
QUIET=0

usage() {
  cat <<USAGE
Usage: $0 [--mode=auto|local|bridge|remote] [--timeout=SEC] [-h]
USAGE
}

for arg in "$@"; do
  case "$arg" in
    --mode=*) MODE="${arg#*=}";;
    --timeout=*) TIMEOUT="${arg#*=}";;
    -h|--help) usage; exit 0;;
    *) echo "Unknown arg: $arg"; usage; exit 2;;
  esac
done

log() { [ "$QUIET" -eq 1 ] && return 0; echo "$@"; }
err() { echo "$@" >&2; }
need() { command -v "$1" >/dev/null 2>&1 || { err "Missing binary: $1"; exit 3; }; }

need mosquitto_pub
need mosquitto_sub
need hostname
need awk
need date
need tr
need grep
need sed

HOSTNAME_LOCAL=$(hostname)
IP_LOCAL=$(hostname -I 2>/dev/null | awk '{print $1}')
[ -z "${IP_LOCAL}" ] && IP_LOCAL="127.0.0.1"
HOST_LC="$(printf '%s' "$HOSTNAME_LOCAL" | tr '[:upper:]' '[:lower:]')"
EXPECT_TOPIC="${TOPIC_RESP_BASE}/text2sip/${HOST_LC}"

# --- Modus ermitteln (auto) ---
if [ "$MODE" = "auto" ]; then
  case "$HOSTNAME_LOCAL" in
    "$LB1_HOST") MODE="local";;
    "$LB2_HOST") MODE="bridge";;
    *) MODE="bridge";;  # Default: als Bridge testen
  esac
fi

# --- Ziel-IP nach Modus ---
MASTER_IP="$MASTER_IP_DEFAULT"
case "$MODE" in
  local)   TARGET_HOST="127.0.0.1" ;;   # LB1 lokal
  bridge)  TARGET_HOST="$MASTER_IP" ;;  # LB2 -> LB1
  remote)  TARGET_HOST="$MASTER_IP" ;;
  *) err "Invalid mode: $MODE (use auto|local|bridge|remote)"; exit 2 ;;
esac

# --- Zertifikate nach Modus ---
# Gemeinsame CA
CAFILE="/etc/mosquitto/ca/mosq-ca.crt"

# Unterschiedliche Client-Zertifikate:
#  - local:   systemweite Client-Certs unter clients/sip_bridge/
#  - bridge/remote: Direct-Client Certs ohne clients/-Untereintrag
case "$MODE" in
  local)
    CERT="/etc/mosquitto/certs/clients/sip_bridge/client.crt"
    KEY="/etc/mosquitto/certs/clients/sip_bridge/client.key"
    ;;
  bridge|remote)
    CERT="/etc/mosquitto/certs/sip_bridge.crt"
    KEY="/etc/mosquitto/certs/sip_bridge.key"
    ;;
esac

# Zertifikate prüfen
for f in "$CAFILE" "$CERT" "$KEY"; do
  if [ ! -r "$f" ]; then err "File not readable: $f"; exit 4; fi
done

log "== Test-Setup =="
log "Mode:       $MODE"
log "Target:     $TARGET_HOST:$PORT"
log "Topics:     req='$TOPIC_REQ'  resp='$EXPECT_TOPIC'"
log "Certs:      CA=$(basename "$CAFILE") CERT=$(basename "$CERT") KEY=$(basename "$KEY")"
log "Local host: $HOSTNAME_LOCAL  IP=$IP_LOCAL"
log "Timeout:    ${TIMEOUT}s"
log

# Mit set -u darf $SYS nicht „leer“ sein → als String setzen:
SYS="\$SYS"

# ---------- DIAG 1: $SYS-Berechtigungen prüfen ----------
TMPDIR="$(mktemp -d)"; trap 'rm -rf "$TMPDIR"' EXIT
SYS_OUT="$TMPDIR/sys.txt"; SYS_ERR="$TMPDIR/sys.err"; : >"$SYS_OUT" >"$SYS_ERR"

mosquitto_sub -h "$TARGET_HOST" -p "$PORT" \
  --cafile "$CAFILE" --cert "$CERT" --key "$KEY" \
  --tls-version tlsv1.2 -q 1 -C 1 -W 1 -v \
  -t '$SYS/broker/clients/connected' >"$SYS_OUT" 2>"$SYS_ERR" || true

if grep -qiE 'connection.*lost|not authorized|acl|auth' "$SYS_ERR"; then
  err "❌ \$SYS-Subscribe wurde vom Broker beendet: $(sed -n '1p' "$SYS_ERR")"
  err "→ Entweder fehlt in der ACL 'topic read \$SYS/#' für deinen CN, oder die aktive ACL-Datei ist nicht die erwartete."
  err "Prüfe auf LB1: journalctl -u mosquitto | grep -i acl_file  (aktive ACL-Datei)"
  err "und stelle sicher, dass sie diese Zeile enthält (im Kontext 'user sip_bridge'):"
  err "  topic read \$SYS/#"
  exit 6
fi

# ---------- DIAG 2: erwartetes Handshake-Response-Topic probe-subscriben ----------
CHK_OUT="$TMPDIR/chk.txt"; CHK_ERR="$TMPDIR/chk.err"; : >"$CHK_OUT" >"$CHK_ERR"
log "Subscribing auf Topic: $EXPECT_TOPIC"
mosquitto_sub -h "$TARGET_HOST" -p "$PORT" \
  --cafile "$CAFILE" --cert "$CERT" --key "$KEY" \
  --tls-version tlsv1.2 -q 1 -C 1 -W 1 -v \
  -t "$EXPECT_TOPIC" >"$CHK_OUT" 2>"$CHK_ERR" || true

if grep -qiE 'connection.*lost|not authorized|acl|auth' "$CHK_ERR"; then
  err "❌ Bridge-Subscribe wurde vom Broker beendet: $(sed -n '1p' "$CHK_ERR")"
  err "→ Sehr wahrscheinlich erkennt der Broker **nicht** den CN 'sip_bridge' für diesen Client,"
  err "  oder die verwendete ACL-Datei ist eine andere als die, die du erwartest."
  err "  Erforderliche Minimal-ACL im Kontext 'user sip_bridge':"
  err "    topic write tts-handshake/request"
  err "    topic read  ${EXPECT_TOPIC}"
  exit 6
fi

# ---------- Normaler Test: Pre-Subscribe → Publish → Wait ----------
RESP_FILE="$TMPDIR/resp.txt"
SUB_ERR="$TMPDIR/sub.err"
: >"$RESP_FILE" >"$SUB_ERR"

mosquitto_sub -h "$TARGET_HOST" -p "$PORT" \
  --cafile "$CAFILE" --cert "$CERT" --key "$KEY" \
  --tls-version tlsv1.2 -q 1 -C 1 -W "$TIMEOUT" -v \
  -t "$EXPECT_TOPIC" >"$RESP_FILE" 2>"$SUB_ERR" &
SUB_PID=$!

sleep 0.35

TS_UTC=$(date -u +'%Y-%m-%dT%H:%M:%SZ')
PAYLOAD=$(cat <<JSON
{"client":"text2sip","version":"1.3.0","hostname":"$HOSTNAME_LOCAL","ip":"$IP_LOCAL","timestamp":"$TS_UTC"}
JSON
)

log "Sende Handshake..."
PUB_OUT="$(mosquitto_pub -h "$TARGET_HOST" -p "$PORT" \
  --cafile "$CAFILE" --cert "$CERT" --key "$KEY" \
  --tls-version tlsv1.2 \
  -t "$TOPIC_REQ" -m "$PAYLOAD" 2>&1)" || true
PUB_RC=$?

if [ $PUB_RC -ne 0 ]; then
  err "Publish fehlgeschlagen (rc=$PUB_RC): $PUB_OUT"
  err "Subscriber-Fehler:"; sed -e 's/^/  /' "$SUB_ERR" 2>/dev/null || true
  exit 5
fi

wait "$SUB_PID" || true
rc_sub=$?
if [ $rc_sub -ne 0 ]; then
  err "mosquitto_sub exited with rc=$rc_sub"
  sed -e 's/^/  /' "$SUB_ERR" 2>/dev/null || true
fi

if [ -s "$RESP_FILE" ]; then
  log "✅ Response empfangen:"
  sed -e 's/^/  /' "$RESP_FILE" | tail -n 1
  exit 0
else
  err "❌ Keine Response innerhalb von ${TIMEOUT}s."
  err "Subscriber-Fehler:"; sed -e 's/^/  /' "$SUB_ERR" 2>/dev/null || true
  exit 6
fi
