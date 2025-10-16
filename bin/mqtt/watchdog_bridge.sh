#!/usr/bin/env bash
set -euo pipefail

LOG_DIR="REPLACELBPLOGDIR"
LOG_FILE="$LOG_DIR/mqtt-watchdog.log"
CA="/etc/mosquitto/certs/mosq-ca.crt"
CERT="/etc/mosquitto/certs/clients/sip_bridge/client.crt"
KEY="/etc/mosquitto/certs/clients/sip_bridge/client.key"
FINDER_SOCK="/dev/shm/mqttfinder.sock"
FINDER_JSON="/dev/shm/mqttfinder.json"
GEN_JSON="REPLACELBHOMEDIR/config/system/general.json"

# Kürzere Default-Wartezeit: kann via ENV überschrieben werden (z.B. SYS_WAIT=10 watchdog_bridge.sh)
SYS_WAIT="${SYS_WAIT:-5}"
SYS_FALLBACK_WAIT="${SYS_FALLBACK_WAIT:-10}"

mkdir -p "$LOG_DIR"; chmod 0755 "$LOG_DIR"
touch "$LOG_FILE"; chmod 0644 "$LOG_FILE"

log(){ printf '%s %s\n' "$(date '+%F %T')" "$*" | tee -a "$LOG_FILE"; }

ok=1
log "🛑 ==== MQTT mTLS healthcheck start ===="

# 0) Dienste aktiv?
if systemctl is-active --quiet mosquitto; then
  log "✅ mosquitto.service active"
else
  log "❌ mosquitto.service NOT active"; ok=0
fi

if systemctl is-active --quiet mqttfinder.service 2>/dev/null; then
  log "✅ mqttfinder.service active"
else
  log "⚠️ mqttfinder.service not active (optional)"
fi

# 1) mTLS Handshake (mit Client-Zert)
if timeout 7 bash -lc "echo | openssl s_client -connect 127.0.0.1:8883 -CAfile '$CA' -cert '$CERT' -key '$KEY' >/tmp/mtls_check.$$ 2>&1"; then
  if grep -q 'Verify return code: 0 (ok)' /tmp/mtls_check.$$; then
    log "✅ TLS handshake + verify OK"
  else
    log "❌ TLS handshake ran, but CA verify not OK"; ok=0
  fi
else
  # Fehlerausgabe mitloggen
  if [ -s /tmp/mtls_check.$$ ]; then
    first_err="$(head -n2 /tmp/mtls_check.$$ | tr -d '\r')"
    log "❌ TLS handshake failed (127.0.0.1:8883) — ${first_err}"
  else
    log "❌ TLS handshake failed (127.0.0.1:8883)"
  fi
  ok=0
fi
rm -f /tmp/mtls_check.$$

# 2) mTLS Subscribe auf $SYS (nicht retained; kommt periodisch)
#    Auf manchen Builds ist $SYS deaktiviert -> dann nur WARNEN.
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

# 3) ACL-NEGATIV-Test: publish auf verbotenen Pfad (soll scheitern)
out="$(mosquitto_pub -d -V mqttv5 -q 1 -h 127.0.0.1 -p 8883 \
        --cafile "$CA" --cert "$CERT" --key "$KEY" \
        -t 'tts-response/deny-test' -m 'nope' 2>&1 || true)"
echo "$out" > /tmp/aclpub.out.$$

if echo "$out" | grep -qiE 'not authorized|denied publish|reason code: not authorized|0x87'; then
	log "✅ ACL negative test passed ($(echo "$out" | head -n1))"
  else
    log "❌ ACL negative test failed (No Not-Authorized-Meessage received)"
fi
rm -f /tmp/aclpub.out.$$

# 4) Local 1883 Publish mit LoxBerry-Creds (optional, falls vorhanden)
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
