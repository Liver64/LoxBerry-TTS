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

# Install Mosquitto Bridge
perl REPLACELBPBINDIR/mqtt/setup-mqtt-interface.pl --write-conf --bundle --debug
echo "<OK> Mosquitto Master (bridged) for T2S has been installed"

# Install MQTT event handler as service
if [ ! -L /etc/systemd/system/mqtt-service-tts.service ]; then
	cp -p -v -r REPLACELBPBINDIR/mqtt/mqtt-service-tts.service /etc/systemd/system/mqtt-service-tts.service
	sudo systemctl daemon-reload
	sudo systemctl enable mqtt-service-tts
	sudo systemctl start mqtt-service-tts
	echo "<OK> MQTT Event handler has been installed"
else
	echo "<INFO> MQTT Event handler is already installed"
fi

# Install MQTT handshake listener as service
if [ ! -L /etc/systemd/system/mqtt-handshake.service ]; then
	chmod 0755 REPLACELBPBINDIR/mqtt/mqtt-handshake.php
	cp -p -v REPLACELBPBINDIR/mqtt/mqtt-handshake.service /etc/systemd/system/mqtt-handshake.service
	sudo systemctl daemon-reload
	sudo systemctl enable mqtt-handshake
	sudo systemctl start mqtt-handshake
	echo "<OK> MQTT Handshake listener has been installed"
else
	echo "<INFO> MQTT Handshake listener is already installed"
fi

# prepare Watchdog listener as service
if [ ! -L REPLACELBPBINDIR/mqtt/mqtt-watchdog.service ]; then
	cp -p -v REPLACELBPBINDIR/mqtt/mqtt-watchdog.service /etc/systemd/system/mqtt-watchdog.service
	sudo systemctl daemon-reload
	sudo systemctl enable mqtt-watchdog
	sudo systemctl start mqtt-watchdog
	echo "<INFO> MQTT Watchdog Initialization has been installed"
else
	echo "<ERROR> MQTT Watchdog Initialization is already installed"
	exit 12
fi

# Copy uninstall.pl to Mosquitto /etc/mosquitto
cp -p -v REPLACELBPBINDIR/uninstall.pl /etc/mosquitto/uninstall.pl
echo "<OK> uninstall.pl has been copied to /etc/mosquitto"


# Restart Moqsuitto
# Silent, non-fatal; won’t spam your installer logs
#sudo timeout 15 REPLACELBHOMEDIR/sbin/mqtt-handler.pl action=restartgateway >/dev/null 2>&1 || true
#echo "<OK> Mosquitto has been restarted."

exit 0