#!/bin/bash
# tls_check.sh — Diagnostic tool for TLS connection between T2S Master and Bridge Client
# Usage: ./tls_check.sh --role master   or   ./tls_check.sh --role client

ROLE=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --role)
      ROLE="$2"
      shift 2
      ;;
    *)
      echo "❌ Unknown parameter: $1"
      exit 1
      ;;
  esac
done

if [[ "$ROLE" != "master" && "$ROLE" != "client" ]]; then
  echo "❌ Please specify a role: --role master or --role client"
  exit 1
fi

if [[ "$ROLE" == "client" ]]; then
  echo "🔍 [CLIENT] Checking file permissions:"
  for FILE in /etc/mosquitto/ca/mosq-ca.crt /etc/mosquitto/certs/sip-bridge/t2s-bridge.crt /etc/mosquitto/certs/sip-bridge/t2s-bridge.key; do
    echo "→ $FILE"
    if [ -f "$FILE" ]; then
      stat -c "  %a %U:%G" "$FILE"
    else
      echo "  ❌ File missing"
    fi
  done

  echo -e "\n🔐 [CLIENT] Certificate/Key comparison:"
  if [ -f /etc/mosquitto/certs/sip-bridge/t2s-bridge.crt ] && [ -f /etc/mosquitto/certs/sip-bridge/t2s-bridge.key ]; then
    CRT_HASH=$(openssl x509 -noout -modulus -in /etc/mosquitto/certs/sip-bridge/t2s-bridge.crt | openssl md5)
    KEY_HASH=$(openssl rsa -noout -modulus -in /etc/mosquitto/certs/sip-bridge/t2s-bridge.key | openssl md5)
    echo "  CRT: $CRT_HASH"
    echo "  KEY: $KEY_HASH"
    [[ "$CRT_HASH" == "$KEY_HASH" ]] && echo "  ✅ Match" || echo "  ❌ Mismatch"
  else
    echo "  ❌ Certificate or key missing – comparison not possible"
  fi

  echo -e "\n🧾 [CLIENT] CA signature validation:"
  if [ -f /etc/mosquitto/certs/sip-bridge/t2s-bridge.crt ]; then
    openssl verify -CAfile /etc/mosquitto/ca/mosq-ca.crt /etc/mosquitto/certs/sip-bridge/t2s-bridge.crt
  else
    echo "  ❌ Client certificate missing – cannot verify signature"
  fi

  echo -e "\n🧪 [CLIENT] TLS handshake with Master:"
  openssl s_client -connect t2s.local:8883 \
    -CAfile /etc/mosquitto/ca/mosq-ca.crt \
    -cert /etc/mosquitto/certs/sip-bridge/t2s-bridge.crt \
    -key /etc/mosquitto/certs/sip-bridge/t2s-bridge.key \
    -tls1_2 < /dev/null

  echo -e "\n📡 [CLIENT] mosquitto_pub test:"
  mosquitto_pub -h t2s.local -p 8883 \
    -t "tts-handshake/test" -m "hello" \
    --cafile /etc/mosquitto/ca/mosq-ca.crt \
    --cert /etc/mosquitto/certs/sip-bridge/t2s-bridge.crt \
    --key /etc/mosquitto/certs/sip-bridge/t2s-bridge.key \
    --tls-version tlsv1.2

  # ============================================================
  # 🔎 Identity vs. Username check block
  # ============================================================
  echo -e "\n🔎 [CLIENT] Bridge identity vs. actual username check:"

  if [ -f /etc/mosquitto/certs/sip-bridge/t2s-bridge.crt ]; then
    EXPECTED_CN=$(openssl x509 -in /etc/mosquitto/certs/sip-bridge/t2s-bridge.crt -noout -subject | sed -n 's/.*CN *= *//p')
  else
    echo "  ❌ Certificate not found – cannot check CN."
    EXPECTED_CN="(unknown)"
  fi

  LAST_LOG=$(grep -E "New bridge connected" /var/log/mosquitto/mosquitto.log | tail -n 1)
  if [[ -z "$LAST_LOG" ]]; then
    echo "  ⚠️ No recent bridge connection found in Mosquitto log."
  else
    ACTUAL_USER=$(echo "$LAST_LOG" | sed -n "s/.*u'\([^']*\)'.*/\1/p")
    LOG_TIME=$(echo "$LAST_LOG" | awk '{print $1}' | sed 's/T/ /')
    if [[ -n "$ACTUAL_USER" ]]; then
      echo "  🕓 Last connection: $LOG_TIME"
      echo "  🔹 Expected CN: $EXPECTED_CN"
      echo "  🔹 Logged username: $ACTUAL_USER"
      if [[ "$ACTUAL_USER" == "$EXPECTED_CN" ]]; then
        echo "  ✅ Bridge identity and username match."
      else
        echo "  ⚠️ Mismatch detected – Bridge CN and actual username differ."
        echo "     → Mosquitto logs show user '$ACTUAL_USER', expected '$EXPECTED_CN'."
        echo "     → This may cause ACL failures if 'use_identity_as_username' is enabled."
      fi
    else
      echo "  ⚠️ Could not determine username from last connection log entry."
    fi
  fi
fi

if [[ "$ROLE" == "master" ]]; then
  echo -e "\n🔍 [MASTER] Showing CA and server certificate:"
  openssl x509 -in /etc/mosquitto/ca/mosq-ca.crt -noout -subject -issuer
  openssl x509 -in /etc/mosquitto/certs/t2s.crt -noout -subject -issuer

  echo -e "\n🧾 [MASTER] CA signature validation:"
  openssl verify -CAfile /etc/mosquitto/ca/mosq-ca.crt /etc/mosquitto/certs/t2s.crt

  echo -e "\n🔍 [MASTER] Reading TLS configuration:"
  grep -E 'cafile|certfile|keyfile|require_certificate|use_identity|tls_version' /etc/mosquitto/conf.d/10-listener-tls.conf

  echo -e "\n✅ [MASTER] Listener configuration:"
  grep -E 'listener|protocol' /etc/mosquitto/conf.d/10-listener-tls.conf

  echo -e "\n📘 [MASTER] Reading ACL file:"
  ACL_FILE="/etc/mosquitto/tts-aclfile"
  if [ -f "$ACL_FILE" ]; then
    echo "→ $ACL_FILE (content below)"
    echo "------------------------------------------------------------"
    cat "$ACL_FILE"
    echo "------------------------------------------------------------"
  else
    echo "  ❌ ACL file not found: $ACL_FILE"
  fi

  # ============================================================
  # 🔒 Security & Permission Validation Block
  # ============================================================
  echo -e "\n🔒 [MASTER] Checking file permissions and ownership:"
  FILES=(
    "/etc/mosquitto/ca/mosq-ca.crt"
    "/etc/mosquitto/certs/t2s.crt"
    "/etc/mosquitto/certs/t2s.key"
  )

  for FILE in "${FILES[@]}"; do
    if [ -f "$FILE" ]; then
      PERM=$(stat -c "%a" "$FILE")
      OWNER=$(stat -c "%U:%G" "$FILE")
      echo "→ $FILE"
      echo "   Permissions: $PERM  Owner:Group = $OWNER"

      case "$FILE" in
        *".key")
          if [[ "$PERM" -le 640 ]]; then
            echo "   ✅ Key file permissions OK (restricted access)"
          else
            echo "   ⚠️ Key file permissions too open – should be max 640"
          fi
          ;;
        *)
          if [[ "$PERM" -le 644 ]]; then
            echo "   ✅ Certificate/CA file permissions OK"
          else
            echo "   ⚠️ Certificate/CA file permissions too open – should be max 644"
          fi
          ;;
      esac
    else
      echo "  ❌ File not found: $FILE"
    fi
  done

  echo -e "\n🔧 [MASTER] Setting WinSCP-compatible directory permissions:"
  for DIR in /etc/mosquitto /etc/mosquitto/ca /etc/mosquitto/certs /etc/mosquitto/conf.d; do
    chmod 0755 "$DIR"
  done
  echo "  ✅ Directory visibility for WinSCP set (files unchanged)."
fi
