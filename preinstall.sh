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

# precheck_master_install.sh — Run detector before installing T2S Master
# Blocks the install if client-only or mixed artifacts are present.

set -euo pipefail

# --- Detector path (quote all vars) ---
DETECTOR="$5/data/system/tmp/uploads/$1/bin/detect_bridge_config.sh"
DETECTOR_FLAGS="--debug"

# --- Sanity checks ---
if [[ ! -e "$DETECTOR" ]]; then
  echo "<FAIL> Detector not found: $DETECTOR"
  exit 2
fi

# Make sure it's executable (do this *after* existence check)
chmod +x "$DETECTOR" || {
  echo "<FAIL> Could not chmod +x $DETECTOR"
  exit 2
}

if [[ ! -x "$DETECTOR" ]]; then
  echo "<FAIL> Detector not executable: $DETECTOR"
  exit 2
fi

# --- Run detector (shield against 'set -e') ---
if "$DETECTOR" $DETECTOR_FLAGS; then
  rc=0
else
  rc=$?
fi

# --- Decide outcome (use official tags only) ---
case "$rc" in
  0)
    echo "<OK> Mosquitto role check: safe to proceed."
    ;;
  1)
    echo "<WARNING> Client artifacts detected, but continuing due to soft mode."
	echo "<WARNING> Please check if Mosquitto is up and running post Installation."
    ;;
  2)
    echo "<FAIL> TLS Client/Master conflict detected by detector. Installation aborted."
	echo "<FAIL> Please uninstall the Plugin using TLS first and then try again."
    exit 2
    ;;
  3)
    echo "<FAIL> Indeterminate Mosquitto state. Please inspect and fix."
	echo "<FAIL> Please uninstall the Plugin using TLS first and then try again."
    exit 2
    ;;
  *)
    echo "<FAIL> Unexpected detector return code: $rc"
	echo "<FAIL> T2S Plugin could not be installed!" 
    exit 2
    ;;
esac

# Exit with Status 0
exit 0
