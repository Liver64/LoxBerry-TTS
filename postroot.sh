#!/usr/bin/env bash
# Will be executed as user "root".

INST=false
piper="/usr/local/bin/piper/piper"

#test -L /usr/bin/piper && echo "piper is a symbolic link" || echo "piper is NOT a symbolic link"
if [ ! -e $piper ]; then
	if [ -e $LBSCONFIG/is_raspberry.cfg ]; then
		echo "<INFO> The hardware architecture is RaspBerry"
		wget -P /usr/local/bin https://github.com/rhasspy/piper/releases/download/2023.11.14-2/piper_linux_aarch64.tar.gz
		cd /usr/local/bin
		tar -xvzf piper_linux_aarch64.tar.gz
		INST=true
		rm piper_linux_aarch64.tar.gz
	fi

	if [ -e $LBSCONFIG/is_x86.cfg ]; then
		echo "<INFO> The hardware architecture is x86"
		wget -P /usr/local/bin https://github.com/rhasspy/piper/releases/download/2023.11.14-2/piper_linux_x86_64.tar.gz
		cd /usr/local/bin
		tar -xvzf piper_linux_x86_64.tar.gz
		rm piper_linux_x86_64.tar.gz
	fi

	if [ -e $LBSCONFIG/is_x64.cfg ]; then
		echo "<INFO> The hardware architecture is x64"
		if [ "$INST" != true ]; then
			wget -P /usr/local/bin https://github.com/rhasspy/piper/releases/download/2023.11.14-2/piper_linux_aarch64.tar.gz
			cd /usr/local/bin
			tar -xvzf piper_linux_aarch64.tar.gz
			rm piper_linux_aarch64.tar.gz
		else
			echo "<INFO> Piper TTS has already been installed upfront"
		fi
	fi
else
	echo "<INFO> Piper TTS is already installed, nothing to do..."
	echo "<INFO> Symlink 'piper' is already available in /usr/bin"
fi

sym="/usr/bin/piper"
if [ ! -L /usr/bin/piper ]; then
	chmod +x /usr/local/bin/piper/piper
	export PATH=/usr/local/bin/piper:$PATH
	ln -s /usr/local/bin/piper/piper /usr/bin/piper
	echo "<INFO> Symlink 'piper' has been created in /usr/bin"
fi


# postroot.sh — finalize T2S installation (root)
# - Fulfill deferred creation of /etc/mosquitto/role/t2s-master
# - Respect skip marker to avoid installing bridge when conflicting
# - Run setup-mqtt-interface.pl (deine bestehende Version) unverändert

set -euo pipefail

ROLE_DIR="/etc/mosquitto/role"
MASTER_MARKER="$ROLE_DIR/t2s-master"

# Volatile markers matching preinstall.sh
SKIP_DIR="/dev/shm/t2s-installer"
SKIP_FILE="$SKIP_DIR/skip-bridge.t2s"
DEFER_MARKER="$SKIP_DIR/defer-master-marker.t2s"

SETUP_PL="REPLACELBPBINDIR/mqtt/setup-mqtt-interface.pl"

cleanup() {
  # Always remove volatile markers at the end to avoid stale state
  rm -f "$SKIP_FILE" "$DEFER_MARKER" 2>/dev/null || true
}
trap cleanup EXIT

# ---- 1) Fulfill deferred master-role marker creation (idempotent) ----
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

# ---- 2) Bridge setup (skip if marker present) ----
if [[ -f "$SKIP_FILE" ]]; then
  REASON="$(grep -E '^reason=' "$SKIP_FILE" 2>/dev/null | cut -d= -f2- || echo '')"
  OFFENDER="$(grep -E '^offender=' "$SKIP_FILE" 2>/dev/null | cut -d= -f2- || echo '')"
  TS="$(grep -E '^ts=' "$SKIP_FILE" 2>/dev/null | cut -d= -f2- || echo '')"

  echo "<INFO> Skip-bridge marker found ($TS, reason=$REASON, offender=$OFFENDER)"
  echo "<OK> Skipping Mosquitto Bridge setup for T2S (safeguard)."
  echo "<OK> Skip processed. Marker will be removed."
  exit 0
fi

# ---- 3) Run your existing setup-mqtt-interface.pl (unchanged) ----
if [[ ! -x "$SETUP_PL" ]]; then
  echo "<ERROR> Missing or non-executable: $SETUP_PL"
  exit 2
fi

# Execute exactly as before (your script kümmert sich selbst um Restart etc.)
perl "$SETUP_PL" --write-conf --bundle
rc=$?

if [[ $rc -eq 0 ]]; then
  echo "<OK> Mosquitto Master (bridged) for T2S has been installed"
else
  echo "<FAIL> setup-mqtt-interface.pl returned rc=$rc"
  exit $rc
fi


# Install MQTT event handler as service
if [ ! -L /etc/systemd/system/mqtt-service-tts.service ]; then
	cp -p -v -r REPLACELBPBINDIR/mqtt/mqtt-service-tts.service /etc/systemd/system/mqtt-service-tts.service
	sudo systemctl daemon-reload
	sudo systemctl enable mqtt-service-tts
	sudo systemctl start mqtt-service-tts
	echo "<OK> MQTT Event Service has been installed"
else
	echo "<INFO> MQTT Event Service is already installed"
fi

#!/usr/bin/env bash
# postroot: install oneshot watchdog + timer, remove old daemon version

# --- Paths (replace via your packager placeholders) ---
SRV_SRC="REPLACELBPBINDIR/mqtt/mqtt-watchdog.service"
TMR_SRC="REPLACELBPBINDIR/mqtt/mqtt-watchdog.timer"
SRV_DST="/etc/systemd/system/mqtt-watchdog.service"
TMR_DST="/etc/systemd/system/mqtt-watchdog.timer"

echo "<INFO> Installing T2S MQTT Watchdog as oneshot + timer …"

# --- 1) If an old daemon-style unit exists, stop/disable it first ---
if systemctl list-unit-files | grep -q '^mqtt-watchdog.service'; then
  # Best-effort stop/disable (ok if not active)
  systemctl stop mqtt-watchdog.service  >/dev/null 2>&1 || true
  systemctl disable mqtt-watchdog.service >/dev/null 2>&1 || true
fi

# Remove any old unit file that might conflict (best-effort)
if [ -e "$SRV_DST" ]; then
  rm -f "$SRV_DST" || true
fi
if [ -e "$TMR_DST" ]; then
  rm -f "$TMR_DST" || true
fi

# --- 2) Install oneshot service + timer (owned by root:root, 0644) ---
install -o root -g root -m 0644 "$SRV_SRC" "$SRV_DST"
install -o root -g root -m 0644 "$TMR_SRC" "$TMR_DST"

# --- 3) Reload systemd units ---
systemctl daemon-reload

# --- 4) Run once NOW (expected to exit and show inactive/dead = SUCCESS) ---
if systemctl start mqtt-watchdog.service; then
  echo "<OK> MQTT Watchdog executed once successfully (oneshot)."
else
  echo "<ERROR> MQTT Watchdog oneshot execution failed."
fi

# --- 5) Enable timer (runs once after every boot) ---
if systemctl enable --now mqtt-watchdog.timer; then
  echo "<OK> MQTT Watchdog timer enabled (fires once per boot)."
else
  echo "<WARNING> Could not enable/start mqtt-watchdog.timer."
fi

echo "<OK> Watchdog service/timer installation completed."


# Copy uninstall.pl to Mosquitto /etc/mosquitto
cp -p -v REPLACELBPBINDIR/uninstall.pl /etc/mosquitto/t2s-uninstall.pl
echo "<OK> uninstall.pl has been copied to /etc/mosquitto"

# Restart Moqsuitto silent, non-fatal; won’t spam your installer logs
#sudo REPLACELBHOMEDIR/sbin/mqtt-handler.pl action=restartgateway >/dev/null 2>&1 || true
#echo "<OK> Mosquitto has been restarted."

exit 0