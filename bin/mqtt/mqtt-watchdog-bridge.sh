#!/usr/bin/env bash
set -euo pipefail

LOG_DIR="REPLACELBHOMEDIR/log/plugins/text2speech"
LOG_FILE="$LOG_DIR/mqtt-watchdog.log"
CA="/etc/mosquitto/certs/mosq-ca.crt"
CERT="/etc/mosquitto/certs/clients/sip_bridge/client.crt"
KEY="/etc/mosquitto/certs/clients/sip_bridge/client.key"
FINDER_SOCK="/dev/shm/mqttfinder.sock"
FINDER_JSON="/dev/shm/mqttfinder.json"
GEN_JSON="REPLACELBHOMEDIR/config/system/general.json"

# Kürzere Default-Wartezeit: kann via ENV überschrieben werden (z.B. SYS_WAIT=10 mqtt-watchdog-bridge.sh)
SYS_WAIT="${SYS_WAIT:-5}"
SYS_FALLBACK_WAIT="${SYS_FALLBACK_WAIT:-10}"

mkdir -p "$LOG_DIR"; chmod 0755 "$LOG_DIR"
touch "$LOG_FILE"; chmod 0644 "$LOG_FILE"

NAME="mqtt-watchdog-bridge"

# ---- LoxBerry-ähnliches Logging ohne Init (mit Emoji → Level Mapping)
_log_emit() { # $1=LEVEL $2...=MESSAGE
  local lvl="$1"; shift
  printf '%(%Y-%m-%d %H:%M:%S)T %s [%s] %s\n' -1 "$lvl" "$NAME" "$*" | tee -a "$LOG_FILE" >/dev/null
}

# Auto-Mapping für vorhandene log "…"-Aufrufe:
# ✅ → OK, ℹ️/🛑 → INFO, ⚠️ → WARN, ❌ → ERR. "RESULT: OK/FAILED" hat Vorrang.
log() {
  local msg="$*"
  local lvl="<INFO>"
  case "$msg" in
    ✅*) lvl="<OK>"   ;;
    ℹ️*) lvl="<DEB>" ;;
    ⚠️*) lvl="<WARN>" ;;
    ❌*) lvl="<ERR>"  ;;
    🛑*) lvl="<DEB>" ;;
    *)   lvl="<DEB>" ;;
  esac
  [[ "$msg" == *"RESULT: OK"*      ]] && lvl="OK"
  [[ "$msg" == *"RESULT: FAILED"*  ]] && lvl="ERR"
  _log_emit "$lvl" "$msg"
}

# ---- Start-Header + OK-Flag
ok=1
log "🛑 ==== MQTT mTLS healthcheck start ===="

# ---------- Lokalen T2S-Master erkennen / mTLS-Checks überspringen ----------
MOSQ_ETC="/etc/mosquitto"
CONF_DIR="$MOSQ_ETC/conf.d"
T2S_HDR_RE='^[[:space:]]*#.*Auto-generated[[:space:]]+by[[:space:]]+Text2Speech[[:space:]]+Plugin'

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

SKIP_MTLS=0
REASON=""

if is_role_master; then
  SKIP_MTLS=1; REASON="role=master"
elif is_our_master_conf; then
  SKIP_MTLS=1; REASON="our TLS server conf"
elif [ ! -r "$KEY" ]; then
  SKIP_MTLS=1; REASON="client.key missing"
elif ! port_8883_open; then
  SKIP_MTLS=1; REASON="no 8883 listener"
fi

if [ "$SKIP_MTLS" -eq 1 ]; then
  log "ℹ️ Local/non-mTLS mode detected → skipping TLS handshake, \$SYS@8883 and ACL-negative. ($REASON)"
fi

# 0) Dienste aktiv?
if systemctl is-active --quiet mosquitto; then
  log "✅ mosquitto.service active"
else
  log "❌ mosquitto.service NOT active"; ok=0
fi

# 1) mTLS Handshake (mit Client-Zert)
if [ "$SKIP_MTLS" -eq 0 ]; then
  if timeout 7 bash -lc "echo | openssl s_client -connect 127.0.0.1:8883 -CAfile '$CA' -cert '$CERT' -key '$KEY' >/tmp/mtls_check.$$ 2>&1"; then
    if grep -q 'Verify return code: 0 (ok)' /tmp/mtls_check.$$; then
      log "✅ TLS handshake + verify OK"
    else
      log "❌ TLS handshake ran, but CA verify not OK"; ok=0
    fi
  else
    if [ -s /tmp/mtls_check.$$ ]; then
      first_err="$(head -n2 /tmp/mtls_check.$$ | tr -d '\r')"
      log "❌ TLS handshake failed (127.0.0.1:8883) — ${first_err}"
    else
      log "❌ TLS handshake failed (127.0.0.1:8883)"
    fi
    ok=0
  fi
  rm -f /tmp/mtls_check.$$
else
  log "✅ Skipped TLS handshake (local mode)"
fi

# 2) mTLS Subscribe auf $SYS
if [ "$SKIP_MTLS" -eq 0 ]; then
  if mosquitto_sub -h 127.0.0.1 -p 8883 \
     --cafile "$CA" --cert "$CERT" --key "$KEY" \
     -t '$SYS/#' -C 1 -v -W "$SYS_WAIT" > /tmp/sys.$$ 2> /tmp/sys.err.$$
  then
    first="$(head -n1 /tmp/sys.$$ || true)"
    if [ -n "$first" ]; then
      log "✅ mTLS sub \$SYS => ${first}"
    else
      log "⚠️ mTLS sub on \$SYS returned empty (evtl. sys_interval=0 / SYS disabled)"
    fi
  else
    err="$(tr -d '\r' < /tmp/sys.err.$$ | head -n1 || true)"
    log "ℹ️ mTLS sub on \$SYS not seen on 8883 (likely disabled); ok"
  fi
  rm -f /tmp/sys.$$ /tmp/sys.err.$$
else
  log "✅ Skipped \$SYS@8883 subscribe (local mode)"
fi

# Optionaler Fallback: $SYS auf 1883 testen (falls Credentials vorhanden)
if [ -f "$GEN_JSON" ] && command -v jq >/dev/null 2>&1; then
  BH=$(jq -r '.Mqtt.Brokerhost // "localhost"' "$GEN_JSON")
  BP=$(jq -r '.Mqtt.Brokerport // 1883' "$GEN_JSON")
  BU=$(jq -r '.Mqtt.Brokeruser // empty' "$GEN_JSON" || true)
  BPW=$(jq -r '.Mqtt.Brokerpass // empty' "$GEN_JSON" || true)
  if [ -n "${BU:-}" ] && [ -n "${BPW:-}" ]; then
    if mosquitto_sub -h "$BH" -p "$BP" -u "$BU" -P "$BPW" \
         -t '$SYS/#' -C 1 -v -W "$SYS_FALLBACK_WAIT" >/dev/null 2>&1; then
      log "ℹ️ \$SYS available on $BH:$BP"
    else
      log "ℹ️ \$SYS not seen on $BH:$BP (ok if disabled)"
    fi
  fi
fi

# 3) ACL-NEGATIV-Test
if [ "$SKIP_MTLS" -eq 0 ]; then
  out="$(mosquitto_pub -d -V mqttv5 -q 1 -h 127.0.0.1 -p 8883 \
          --cafile "$CA" --cert "$CERT" --key "$KEY" \
          -t 'tts-response/deny-test' -m 'nope' 2>&1 || true)"
  echo "$out" > /tmp/aclpub.out.$$
  if echo "$out" | grep -qiE 'not authorized|denied publish|reason code: not authorized|0x87'; then
    log "✅ ACL negative test passed ($(echo "$out" | head -n1))"
  else
    log "❌ ACL negative test failed (No Not-Authorized-Message received)"
  fi
  rm -f /tmp/aclpub.out.$$
else
  log "✅ Skipped ACL negative test (local mode; no ACL expected on 1883)"
fi

# 4) Local 1883 Publish (optional)
if [ -f "$GEN_JSON" ] && command -v jq >/dev/null 2>&1; then
  BH=$(jq -r '.Mqtt.Brokerhost // "localhost"' "$GEN_JSON")
  BP=$(jq -r '.Mqtt.Brokerport // 1883' "$GEN_JSON")
  BU=$(jq -r '.Mqtt.Brokeruser // empty' "$GEN_JSON" || true)
  BPW=$(jq -r '.Mqtt.Brokerpass // empty' "$GEN_JSON" || true)
  if [ -n "${BU:-}" ] && [ -n "${BPW:-}" ]; then
    if mosquitto_pub -h "$BH" -p "$BP" -u "$BU" -P "$BPW" \
       -t 'diag/healthcheck' -m "ok-$(date +%s)" >/dev/null 2>&1; then
      log "✅ local 1883 publish ($BH:$BP) successful"
    else
      log "⚠️ local 1883 publish failed"
    fi
  else
    log "ℹ️ no local 1883 credentials in general.json; skip"
  fi
else
  log "ℹ️ jq or general.json missing; skip 1883 publish"
fi

# 5) Finder-Socket & JSON
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

# 6) Ergebnis
if [ "$ok" -eq 1 ]; then
  log "🛑 ✅ ==== RESULT: OK ===="
  exit 0
else
  log "🛑 ❌ ==== RESULT: FAILED ===="
  exit 1
fi
