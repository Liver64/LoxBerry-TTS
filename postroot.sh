#!/usr/bin/env bash
# postroot.sh — finalize T2S installation (root)
# - Install Piper (if needed)
# - Respect skip marker: skip *only* the Mosquitto bridge setup, continue with the rest
# - Optionally create deferred master-role marker
# - Install mqtt-service-tts + mqtt-watchdog (oneshot+timer)
# - Copy uninstall helper

set -euo pipefail

# ===== Piper bootstrap (as you had) =====
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

SETUP_PL="REPLACELBPBINDIR/mqtt/setup-mqtt-interface.pl"

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

# 3) Run your setup-mqtt-interface.pl only if not skipped
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
  cp -p -v REPLACELBPBINDIR/mqtt/mqtt-service-tts.service /etc/systemd/system/mqtt-service-tts.service
  systemctl daemon-reload
  systemctl enable mqtt-service-tts
  systemctl start mqtt-service-tts
  echo "<OK> MQTT Event Service has been installed"
fi

# 5) Install oneshot watchdog + timer (idempotent)
SRV_SRC="REPLACELBPBINDIR/mqtt/mqtt-watchdog.service"
TMR_SRC="REPLACELBPBINDIR/mqtt/mqtt-watchdog.timer"
SRV_DST="/etc/systemd/system/mqtt-watchdog.service"
TMR_DST="/etc/systemd/system/mqtt-watchdog.timer"

echo "<INFO> Installing T2S MQTT Watchdog as oneshot + timer …"

# If an old unit exists, stop/disable best-effort
systemctl stop mqtt-watchdog.service  >/dev/null 2>&1 || true
systemctl disable mqtt-watchdog.service >/dev/null 2>&1 || true

# Replace unit files
install -o root -g root -m 0644 "$SRV_SRC" "$SRV_DST"
install -o root -g root -m 0644 "$TMR_SRC" "$TMR_DST"

# Reload systemd units
systemctl daemon-reload

# Run once now (expected to exit inactive=dead on success)
if systemctl start mqtt-watchdog.service; then
  echo "<OK> MQTT Watchdog executed once successfully (oneshot)."
else
  echo "<ERROR> MQTT Watchdog oneshot execution failed."
fi

# Enable timer (fires once per boot)
if systemctl enable --now mqtt-watchdog.timer; then
  echo "<OK> MQTT Watchdog timer enabled (fires once per boot)."
else
  echo "<WARNING> Could not enable/start mqtt-watchdog.timer."
fi

echo "<OK> Watchdog service/timer installation completed."

# 6) Copy uninstall helper
cp -p -v REPLACELBPBINDIR/uninstall.pl /etc/mosquitto/t2s-uninstall.pl
echo "<OK> uninstall.pl has been copied to /etc/mosquitto"

# (Optional) silent Mosquitto restart (you had it commented)
# REPLACELBHOMEDIR/sbin/mqtt-handler.pl action=restartgateway >/dev/null 2>&1 || true
# echo "<OK> Mosquitto has been restarted."

exit 0
