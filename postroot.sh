#!/usr/bin/env bash
# postroot.sh — finalize T2S installation (root)
# - Install Piper (if needed)
# - Install the validated Text2Speech MQTT subscriber service
# - Restart Mosquitto after legacy Bridge cleanup, then restart the TTS subscriber
# - Do not modify or restart the central LoxBerry MQTT Gateway
# - Ensure proper log directory ownership for loxberry

set -euo pipefail

INST=false

install_piper() {
    # LBSCONFIG absichern (System-Config-Verzeichnis)
    local LBSCONFIG_LOCAL="${LBSCONFIG:-REPLACELBHOMEDIR/config/system}"
    local piper_root="/usr/local/bin/piper"
    local piper_bin="$piper_root/piper"
    local expected_arch=""
    local url=""
    local archive=""

    # ---- Architektur ermitteln (uname zuerst, Marker nur Fallback) ----
    local uname_arch
    uname_arch="$(uname -m)"

    case "$uname_arch" in
        armv7l)
            expected_arch="armv7l"
            echo "<INFO> Piper install: Detected armv7l via uname."
            ;;
        aarch64|arm64|armv8*)
            expected_arch="aarch64"
            echo "<INFO> Piper install: Detected aarch64 via uname."
            ;;
        x86_64|amd64)
            expected_arch="x86_64"
            echo "<INFO> Piper install: Detected x86_64 via uname."
            ;;
        *)
            echo "<WARNING> Piper install: Unknown architecture '$uname_arch' – trying LoxBerry markers..."
            if [ -e "$LBSCONFIG_LOCAL/is_arch_armv7l.cfg" ]; then
                expected_arch="armv7l"
                echo "<INFO> Piper install: LB marker armv7l."
            elif [ -e "$LBSCONFIG_LOCAL/is_arch_aarch64.cfg" ] || [ -e "$LBSCONFIG_LOCAL/is_raspberry.cfg" ]; then
                expected_arch="aarch64"
                echo "<INFO> Piper install: LB marker aarch64."
            elif [ -e "$LBSCONFIG_LOCAL/is_x64.cfg" ] || [ -e "$LBSCONFIG_LOCAL/is_x86.cfg" ]; then
                expected_arch="x86_64"
                echo "<INFO> Piper install: LB marker x86_64."
            else
                echo "<WARNING> Piper install: Could not determine architecture – skipping automatic Piper install."
                return 0
            fi
            ;;
    esac

    # ---- Bereits existierendes Piper prüfen ----
    if [ -x "$piper_bin" ]; then
        echo "<OK> Piper binary already present at $piper_bin – nothing to do."
        return 0
    fi

    # ---- Download-URL anhand der erwarteten Architektur setzen ----
    case "$expected_arch" in
        armv7l)
            url="https://github.com/rhasspy/piper/releases/download/2023.11.14-2/piper_linux_armv7l.tar.gz"
            archive="piper_linux_armv7l.tar.gz"
            ;;
        aarch64)
            url="https://github.com/rhasspy/piper/releases/download/2023.11.14-2/piper_linux_aarch64.tar.gz"
            archive="piper_linux_aarch64.tar.gz"
            ;;
        x86_64)
            url="https://github.com/rhasspy/piper/releases/download/2023.11.14-2/piper_linux_x86_64.tar.gz"
            archive="piper_linux_x86_64.tar.gz"
            ;;
        *)
            echo "<WARNING> Piper install: No download mapping for arch '$expected_arch'."
            return 0
            ;;
    esac

    echo "<INFO> Piper install: Downloading Piper ($expected_arch) ..."
    mkdir -p /usr/local/bin
    cd /usr/local/bin

    # ---- Download mit Timeout/Retry (gegen "hängendes wget") ----
    local tmp="${archive}.tmp"
    if wget --timeout=20 --tries=3 --progress=dot:giga -O "$tmp" "$url"; then
        mv -f "$tmp" "$archive"

        if tar -xzf "$archive"; then
            rm -f "$archive"
        else
            echo "<ERROR> Piper install: Extraction failed for $archive"
            rm -f "$archive"
            return 1
        fi

        if [ -x "$piper_bin" ]; then
            chmod +x "$piper_bin" || true
            echo "<OK> Piper successfully installed at $piper_bin"
            INST=true
        else
            echo "<ERROR> Piper install: Archive extracted, but '$piper_bin' not found or not executable."
            return 1
        fi
    else
        echo "<ERROR> Piper install: Download failed from $url"
        rm -f "$tmp"
        return 1
    fi
}

# ===== Aufruf gleich zu Beginn von postroot.sh =====
install_piper

# ---------------------------------------------------------
# Symlink /usr/bin/piper (sicher)
# ---------------------------------------------------------
sym="/usr/bin/piper"

if [ -e "$sym" ] && [ ! -L "$sym" ]; then
    echo "<WARNING> /usr/bin/piper exists but is not a symlink – leaving it untouched."
elif [ ! -L "$sym" ]; then
    chmod +x /usr/local/bin/piper/piper || true
    export PATH=/usr/local/bin/piper:$PATH
    ln -s /usr/local/bin/piper/piper "$sym"
    echo "<OK> Symlink 'piper' has been created in /usr/bin"
fi



# ===== Migration cleanup: remove legacy T2S Bridge artifacts =====
# The former Bridge Mode wrote global Mosquitto files. Remove only artifacts
# that are clearly attributable to Text2Speech; never purge generic Mosquitto
# directories or files belonging to other plugins.
cleanup_legacy_t2s_bridge() {
    echo "<INFO> Checking for legacy Text2Speech Bridge artifacts …"

    # Obsolete Bridge-only units. The core mqtt-service-tts.service is retained.
    local unit
    for unit in \
        mqtt-watchdog.service \
        mqtt-watchdog.timer \
        mqtt-handshake-listener.service \
        mqtt-handshake-tts.service
    do
        systemctl disable --now "$unit" >/dev/null 2>&1 || true
        rm -f "/etc/systemd/system/$unit"
        rm -f "/lib/systemd/system/$unit"
    done

    # Global Mosquitto files generated by the former TTS Bridge setup.
    local file
    for file in \
        /etc/mosquitto/conf.d/00-global-per-listener.conf \
        /etc/mosquitto/conf.d/10-listener-tls.conf \
        /etc/mosquitto/tts-aclfile
    do
        [ -f "$file" ] || continue
        if grep -Eqi 'Text2Speech|T2S|setup-mqtt-interface|generate_mosquitto_certs' "$file"; then
            rm -f "$file"
            echo "<OK> Removed legacy TTS Mosquitto file: $file"
        else
            echo "<WARNING> Kept $file because no TTS ownership marker was found"
        fi
    done

    # Standalone uninstall helpers copied by old TTS versions.
    rm -f \
        /etc/mosquitto/t2s-uninstall.pl \
        /etc/mosquitto/text2speech-uninstall.pl \
        /etc/mosquitto/uninstall-t2s.pl

    # Former role markers. Remove the now-empty role directory when nothing else uses it.
    rm -f \
        /etc/mosquitto/role/t2s-master \
        /etc/mosquitto/role/text2speech-master
    rmdir /etc/mosquitto/role 2>/dev/null || true

    # Persistent CA created by setup-mqtt-interface.pl. Remove only its known
    # TTS files. The standard /etc/mosquitto/ca directory itself must remain.
    rm -f \
        /etc/mosquitto/ca/mosq-ca.crt \
        /etc/mosquitto/ca/mosq-ca.srl \
        /etc/mosquitto/ca/private/mosq-ca.key
    rmdir /etc/mosquitto/ca/private 2>/dev/null || true

    # Server certificate artifacts created by setup-mqtt-interface.pl.
    # The standard /etc/mosquitto/certs directory itself must remain.
    local cert_artifact
    local cert_artifacts=(
        /etc/mosquitto/certs/mosq-ca.crt
        /etc/mosquitto/certs/mosq-ca.key
        /etc/mosquitto/certs/t2s.crt
        /etc/mosquitto/certs/t2s.key
        /etc/mosquitto/certs/t2s.csr
        /etc/mosquitto/certs/sip_bridge.crt
        /etc/mosquitto/certs/sip_bridge.key
        /etc/mosquitto/certs/sip_bridge.csr
    )
    for cert_artifact in "${cert_artifacts[@]}"; do
        if [ -e "$cert_artifact" ] || [ -L "$cert_artifact" ]; then
            rm -f "$cert_artifact"
            echo "<OK> Removed legacy TTS certificate artifact: $cert_artifact"
        fi
    done

    # Client certificate trees created exclusively by the former TTS Bridge.
    # Delete only the two known TTS client identities. Unknown client folders
    # are kept. The standard /etc/mosquitto/certs directory remains untouched.
    local client_dir
    for client_dir in \
        /etc/mosquitto/certs/clients/t2s-bridge \
        /etc/mosquitto/certs/clients/sip_bridge
    do
        if [ -d "$client_dir" ]; then
            rm -rf --one-file-system "$client_dir"
            echo "<OK> Removed legacy TTS Bridge client directory: $client_dir"
        fi
    done
    rmdir /etc/mosquitto/certs/clients 2>/dev/null || true

    # Obsolete bridge bundle from earlier plugin versions.
    rm -f REPLACELBHOMEDIR/config/plugins/text2speech/bridge/t2s-bundle.tar.gz
    rmdir REPLACELBHOMEDIR/config/plugins/text2speech/bridge 2>/dev/null || true

    systemctl daemon-reload
    echo "<OK> Legacy Text2Speech Bridge migration cleanup completed"
}

cleanup_legacy_t2s_bridge

# ===== Text2Speech MQTT subscriber service =====
# This service is the validated plugin interface. It subscribes to the existing
# LoxBerry MQTT broker and must not modify the central MQTT Gateway.
MQTT_SERVICE_SRC="REPLACELBHOMEDIR/bin/plugins/text2speech/mqtt/mqtt-service-tts.service"
MQTT_SERVICE_DST="/etc/systemd/system/mqtt-service-tts.service"

echo "<INFO> Installing Text2Speech MQTT subscriber service …"
install -o root -g root -m 0644 "$MQTT_SERVICE_SRC" "$MQTT_SERVICE_DST"
systemctl daemon-reload
systemctl enable mqtt-service-tts.service >/dev/null 2>&1 || true
echo "<OK> Text2Speech MQTT subscriber service installed and enabled"

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

# ===== Finalize MQTT environment =====
# Legacy Bridge files may have been active in Mosquitto before this update.
# Restart the broker only after cleanup is complete, then reconnect the validated
# Text2Speech subscriber. The central LoxBerry MQTT Gateway is not managed here.
echo "<INFO> Restarting Mosquitto after legacy Bridge cleanup …"

if ! systemctl restart mosquitto.service; then
    echo "<WARNING> Mosquitto restart failed – trying to start the service …"
    if ! systemctl start mosquitto.service; then
        echo "<ERROR> Mosquitto could not be restarted or started"
        systemctl status mosquitto.service --no-pager || true
        journalctl -u mosquitto.service -n 30 --no-pager || true
        exit 1
    fi
fi

MOSQUITTO_ACTIVE=false
for attempt in $(seq 1 15); do
    if systemctl is-active --quiet mosquitto.service; then
        MOSQUITTO_ACTIVE=true
        break
    fi
    sleep 1
done

if [ "$MOSQUITTO_ACTIVE" != true ]; then
    echo "<ERROR> Mosquitto is not active after installation"
    systemctl status mosquitto.service --no-pager || true
    journalctl -u mosquitto.service -n 30 --no-pager || true
    exit 1
fi

echo "<OK> Mosquitto is active"
echo "<INFO> Starting Text2Speech MQTT subscriber …"

systemctl reset-failed mqtt-service-tts.service >/dev/null 2>&1 || true
if ! systemctl restart mqtt-service-tts.service; then
    echo "<ERROR> mqtt-service-tts.service could not be restarted"
    systemctl status mqtt-service-tts.service --no-pager || true
    journalctl -u mqtt-service-tts.service -n 30 --no-pager || true
    exit 1
fi

MQTT_TTS_ACTIVE=false
for attempt in $(seq 1 15); do
    if systemctl is-active --quiet mqtt-service-tts.service; then
        MQTT_TTS_ACTIVE=true
        break
    fi
    sleep 1
done

if [ "$MQTT_TTS_ACTIVE" != true ]; then
    echo "<ERROR> mqtt-service-tts.service is not active after installation"
    systemctl status mqtt-service-tts.service --no-pager || true
    journalctl -u mqtt-service-tts.service -n 30 --no-pager || true
    exit 1
fi

echo "<OK> Text2Speech MQTT subscriber is active"
echo "<OK> MQTT environment successfully initialized"

# The plugin deliberately does not start, stop or reconfigure the central MQTT Gateway.

exit 0
