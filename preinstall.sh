#!/usr/bin/env bash

# Bash script which is executed by bash *BEFORE* installation is started (but
# *AFTER* preupdate). Use with caution and remember, that all systems may be
# different!
#
# Exit code must be 0 if executed successfull. 
# Exit code 1 gives a warning but continues installation.
# Exit code 2 cancels installation.
#
# Will be executed as user "loxberry".
#
# You can use all vars from /etc/environment in this script.
#
# We add 5 additional arguments when executing this script:
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# For logging, print to STDOUT. You can use the following tags for showing
# different colorized information during plugin installation:
#
# <OK> This was ok!"
# <INFO> This is just for your information."
# <WARNING> This is a warning!"
# <ERROR> This is an error!"
# <FAIL> This is a fail!"

# To use important variables from command line use the following code:
COMMAND=$0    # Zero argument is shell command
PTEMPDIR=$1   # First argument is temp folder during install
PSHNAME=$2    # Second argument is Plugin-Name for scipts etc.
PDIR=$3       # Third argument is Plugin installation folder
PVERSION=$4   # Forth argument is Plugin version
#LBHOMEDIR=$5 # Comes from /etc/environment now. Fifth argument is
              # Base folder of LoxBerry

# Combine them with /etc/environment
PCGI=$LBPCGI/$PDIR
PHTML=$LBPHTML/$PDIR
PTEMPL=$LBPTEMPL/$PDIR
PDATA=$LBPDATA/$PDIR
PLOG=$LBPLOG/$PDIR # Note! This is stored on a Ramdisk now!
PCONFIG=$LBPCONFIG/$PDIR
PSBIN=$LBPSBIN/$PDIR
PBIN=$LBPBIN/$PDIR

# preinstall.sh — prepare T2S installation without touching system paths (no sudo)
# - Detect bridge-role conflicts
# - Set a volatile skip marker in /dev/shm if a conflict exists
# - Request (defer) creation of /etc/mosquitto/role/t2s-master for postroot.sh (root)

set -euo pipefail

ROLE_DIR="/etc/mosquitto/role"
MASTER_MARKER="$ROLE_DIR/t2s-master"

# Volatile markers in tmpfs
SKIP_DIR="/dev/shm/t2s-installer"
SKIP_FILE="$SKIP_DIR/skip-bridge.t2s"
DEFER_MARKER="$SKIP_DIR/defer-master-marker.t2s"

# Ensure volatile dir exists
mkdir -p "$SKIP_DIR"
chmod 0777 "$SKIP_DIR" || true

# --- Conflict detection (read-only on /etc) ---
OFFENDER="$(find "$ROLE_DIR" -mindepth 1 -maxdepth 1 -type f ! -name 't2s-master' -print -quit 2>/dev/null || true)"

if [[ -n "${OFFENDER}" ]]; then
  echo "<WARNING> Conflicting role marker detected: ${OFFENDER}"
  echo "<WARNING> Will SKIP installing the Mosquitto bridge for T2S to protect broker."

  # Write skip marker with context
  {
    echo "reason=conflicting_role"
    echo "offender=${OFFENDER}"
    echo "ts=$(date -Iseconds)"
    echo "who=t2s-preinstall"
  } > "$SKIP_FILE"
  chmod 0644 "$SKIP_FILE" || true

  # No system writes here (no sudo)
else
  # No conflict → request master marker creation for postroot (root, idempotent)
  {
    echo "ts=$(date -Iseconds)"
    echo "path=$MASTER_MARKER"
    echo "who=t2s-preinstall"
  } > "$DEFER_MARKER"
  chmod 0644 "$DEFER_MARKER" || true

  # Clean up any old skip marker from previous runs
  rm -f "$SKIP_FILE" || true

  echo "<OK> Preinstall ready — defer role marker creation to postroot"
fi

# Exit with Status 0
exit 0
