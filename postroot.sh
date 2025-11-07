#!/usr/bin/env bash
# postroot.sh — finalize T2S installation (root)
# - Install Piper (if needed)
# - Respect skip marker: skip *only* the Mosquitto bridge setup, continue with the rest
# - Optionally create deferred master-role marker
# - Install mqtt-service-tts + mqtt-watchdog (oneshot+timer)
# - Copy uninstall helper
# - Ensure proper log directory ownership for loxberry

set -euo pipefail

# ===== Piper bootstrap =====
INST=false
piper="/usr/local/bin/piper/piper"

if [ ! -e "$piper" ]; then
	if [ -e "$LBSCONFIG/is_raspberry.cfg" ]; then
		echo "<INFO> The hardware architecture is RaspBerry"
		wget -P /usr/local/bin https://github.com/rhasspy/piper/releases/download/2023.11.14-2/piper_linux_aarch64.tar.gz
		cd /usr/local/bin
		tar -xvzf piper_linux_aarch64.tar.gz
		INST=true
		rm -f piper_linux_aarch64.tar.gz
	fi

	if [ -e "$LBSCONFIG/is_x86.cfg" ]; then
		echo "<INFO> The hardware architecture is x86"
		wget -P /usr/local/bin https://github.com/rhasspy/piper/releases/download/2023.11.14-2/piper_linux_x86_64.tar.gz
		cd /usr/local/bin
		tar -xvzf piper_linux_x86_64.tar.gz
		rm -f piper_linux_x86_64.tar.gz
	fi

	if [ -e "$LBSCONFIG/is_x64.cfg" ]; then
		echo "<INFO> The hardware architecture is x64"
		if [ "$INST" != true ]; then
			wget -P /usr/local/bin https://github.com/rhasspy/piper/releases/download/2023.11.14-2/piper_linux_aarch64.tar.gz
			cd /usr/local/bin
			tar -xvzf piper_linux_aarch64.tar.gz
			rm -f piper_linux_aarch64.tar.gz
		else
			echo "<INFO> Piper TTS has already been installed upfront"
		fi
	fi
else
	echo "<INFO> Piper TTS is already installed, nothing to do..."
	echo "<INFO> Symlink 'piper' is already available in /usr/bin"
fi

sym="/usr/bin/piper"
if [ ! -L "$sym" ]; then
	chmod +x /usr/local/bin/piper/piper || true
	export PATH=/usr/local/bin/piper:$PATH
	ln -s /usr/local/bin/piper/piper "$sym"
	echo "<OK> Symlink 'piper' has been created in /usr/bin"
fi

# ===== T2S/Mosquitto bits =====
ROLE_DIR="/etc/mosquitto/role"
MASTER_MARKER="$ROLE_DIR/t2s-master"

# Volatile markers (from preinstall)
SKIP_DIR="/dev/shm/t2s-installer"
SKIP_FILE="$SKIP_DIR/skip-bridge.t2s"
DEFER_MARKER="$SKIP_DIR/defer-master-marker.t2s"

SETUP_PL="REPLACELBHOMEDIR/bin/plugins/text2speech/mqtt/setup-mqtt-interface.pl"

cleanup() {
  rm -f "$SKIP_FILE" "$DEFER_MARKER" 2>/dev/null || true
}
trap cleanup EXIT

# 1) Fulfill deferred master-role marker creation (idempotent)
if [[ -f "$DEFER_MARKER" ]]; then
  echo "<INFO> Creating deferred role marker for T2S Master"
  install -d -o root -g root -m 0755 "$ROLE_DIR"
  if [[ ! -f "$MASTER_MARKER" ]]; then
    install -m 0644 -o root -g root /dev/null "$MASTER_MARKER"
    echo "<OK> Created role marker: $MASTER_MARKER"
  else
    echo "<INFO> Role marker already present: $MASTER_MARKER"
  fi
fi

# 2) Decide whether to skip only the bridge setup
SKIPPED_BRIDGE=0
if [[ -f "$SKIP_FILE" ]]; then
  REASON="$(grep -E '^reason=' "$SKIP_FILE" 2>/dev/null | cut -d= -f2- || echo '')"
  OFFENDER="$(grep -E '^offender=' "$SKIP_FILE" 2>/dev/null | cut -d= -f2- || echo '')"
  TS="$(grep -E '^ts=' "$SKIP_FILE" 2>/dev/null | cut -d= -f2- || echo '')"
  echo "<INFO> Skip-bridge marker found (${TS:-n/a}, reason=${REASON:-n/a}, offender=${OFFENDER:-n/a})"
  echo "<OK> Skipping Mosquitto Bridge setup for T2S (safeguard)."
  echo "<OK> Skip processed. Marker will be removed."
  SKIPPED_BRIDGE=1
fi

# 3) Run setup-mqtt-interface.pl only if not skipped
if [[ $SKIPPED_BRIDGE -eq 0 ]]; then
  if [[ ! -x "$SETUP_PL" ]]; then
    echo "<ERROR> Missing or non-executable: $SETUP_PL"
    exit 2
  fi
  perl "$SETUP_PL" --write-conf --bundle
  rc=$?
  if [[ $rc -eq 0 ]]; then
    echo "<OK> Mosquitto Master (bridged) for T2S has been installed"
  else
    echo "<FAIL> setup-mqtt-interface.pl returned rc=$rc"
    exit $rc
  fi
else
  echo "<INFO> Bridge setup step skipped; continuing with service installs…"
fi

# 4) Install MQTT event handler as service (idempotent)
if systemctl cat mqtt-service-tts >/dev/null 2>&1; then
  echo "<INFO> MQTT Event Service already installed"
else
  cp -p -v REPLACELBHOMEDIR/bin/plugins/text2speech/mqtt/mqtt-service-tts.service /etc/systemd/system/mqtt-service-tts.service
  systemctl daemon-reload
  systemctl enable mqtt-service-tts
  systemctl start mqtt-service-tts
  echo "<OK> MQTT Event Service has been installed"
fi

# ============================================================
# 5) Install MQTT Watchdog + Handshake Listener (idempotent)
# ============================================================
SRV_SRC="REPLACELBHOMEDIR/bin/plugins/text2speech/mqtt/mqtt-watchdog.service"
TMR_SRC="REPLACELBHOMEDIR/bin/plugins/text2speech/mqtt/mqtt-watchdog.timer"
SRV_DST="/etc/systemd/system/mqtt-watchdog.service"
TMR_DST="/etc/systemd/system/mqtt-watchdog.timer"

HS_SRC="REPLACELBHOMEDIR/bin/plugins/text2speech/mqtt/mqtt-handshake-listener.php"
HS_SRV_SRC="REPLACELBHOMEDIR/bin/plugins/text2speech/mqtt/mqtt-handshake-listener.service"
HS_SRV_DST="/etc/systemd/system/mqtt-handshake-listener.service"

echo "<INFO> Installing T2S MQTT Watchdog and Handshake Listener …"

# --- Watchdog Service ---
systemctl stop mqtt-watchdog.service >/dev/null 2>&1 || true
systemctl disable mqtt-watchdog.service >/dev/null 2>&1 || true
install -o root -g root -m 0644 "$SRV_SRC" "$SRV_DST"
install -o root -g root -m 0644 "$TMR_SRC" "$TMR_DST"

# Reload + Run oneshot
systemctl daemon-reload
if systemctl start mqtt-watchdog.service; then
  echo "<OK> MQTT Watchdog executed once successfully (oneshot)."
else
  echo "<ERROR> MQTT Watchdog oneshot execution failed."
fi
if systemctl enable --now mqtt-watchdog.timer; then
  echo "<OK> MQTT Watchdog timer enabled (fires once per boot)."
else
  echo "<WARNING> Could not enable/start mqtt-watchdog.timer."
fi

# --- Handshake Listener Service ---
echo "<INFO> Installing MQTT Handshake Listener …"

# Falls alter Dienst läuft → stoppen
systemctl stop mqtt-handshake-listener.service >/dev/null 2>&1 || true
systemctl disable mqtt-handshake-listener.service >/dev/null 2>&1 || true

# PHP-Daemon prüfen
if [ ! -x "$HS_SRC" ]; then
  echo "<ERROR> Missing or non-executable $HS_SRC"
else
  install -o root -g root -m 0644 "$HS_SRV_SRC" "$HS_SRV_DST"
  systemctl daemon-reload

  if systemctl enable --now mqtt-handshake-listener.service; then
    echo "<OK> MQTT Handshake Listener service installed and started."
  else
    echo "<ERROR> Failed to enable/start mqtt-handshake-listener.service."
  fi
fi

echo "<OK> Watchdog + Handshake Listener installation completed."

# 6) Copy uninstall helper
cp -p -v REPLACELBHOMEDIR/bin/plugins/text2speech/t2s-uninstall.pl /etc/mosquitto/t2s-uninstall.pl
echo "<OK> t2s-uninstall.pl has been copied to /etc/mosquitto"

# ===== Ensure correct permissions for log directory =====
LOGDIR="REPLACELBHOMEDIR/log/plugins/text2speech"

if [ -d "$LOGDIR" ]; then
    echo "<INFO> Adjusting permissions for $LOGDIR ..."
    chown -R loxberry:loxberry "$LOGDIR"
    chmod -R 775 "$LOGDIR"
    echo "<OK> Log directory ownership and permissions corrected."
else
    echo "<WARNING> Log directory $LOGDIR does not exist – creating now."
    install -d -o loxberry -g loxberry -m 0775 "$LOGDIR"
    echo "<OK> Created missing log directory."
fi

# ===== Verify MQTT Gateway process status =====
echo "<INFO> Checking MQTT Gateway runtime state …"
MQTT_PROC="REPLACELBHOMEDIR/sbin/mqttgateway.pl"

# Prüfen, ob der Prozess läuft
if pgrep -f "$MQTT_PROC" >/dev/null 2>&1; then
    PID=$(pgrep -f "$MQTT_PROC" | head -n1)
    echo "<OK> MQTT Gateway is active (PID $PID)"
else
    echo "<WARNING> MQTT Gateway not running – attempting to start manually ..."
    REPLACELBHOMEDIR/sbin/mqtt-handler.pl action=startgateway >/dev/null 2>&1
    sleep 2
    if pgrep -f "$MQTT_PROC" >/dev/null 2>&1; then
        PID=$(pgrep -f "$MQTT_PROC" | head -n1)
        echo "<OK> MQTT Gateway started successfully (PID $PID)"
    else
        echo "<ERROR> Could not start MQTT Gateway – please check REPLACELBHOMEDIR/log/system_tmpfs/mqttgateway.log"
    fi
fi

exit 0
