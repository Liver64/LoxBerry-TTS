#!/usr/bin/env bash
# Text2Speech pre-install hook.
# No private Mosquitto listener, TLS, ACL or broker-role setup is required.
# The plugin uses the existing LoxBerry MQTT broker through its own validated
# subscriber service (mqtt-service-tts.service).

set -euo pipefail

echo "<OK> Text2Speech pre-install checks completed"
exit 0
