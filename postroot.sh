#!/bin/sh
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
sudo perl REPLACELBPBINDIR/mqtt/generate_mosquitto_certs.pl --write-conf --bundle --debug
echo "<OK> Mosquitto Bridge for T2S has been installed"

# Install MQTT event handler as service + watchdog
if [ ! -L /etc/systemd/system/mqtt-service-tts.service ]; then
	cp -p -v -r REPLACELBPBINDIR/mqtt/mqtt-service-tts.service /etc/systemd/system/mqtt-service-tts.service
	cp -p -v -r REPLACELBPBINDIR/mqtt/mqtt-config-watcher.service /etc/systemd/system/mqtt-config-watcher.service
	sudo systemctl daemon-reload
	sudo systemctl enable mqtt-service-tts
	sudo systemctl start mqtt-service-tts
	sudo systemctl enable mqtt-config-watcher
	sudo systemctl start mqtt-config-watcher
	sudo chmod +x REPLACELBPBINDIR/mqtt/mqtt-watchdog.php
	echo "<OK> MQTT Event handler and config watcher has been installed"
else
	echo "<INFO> MQTT Event handler and config watcher are already installed"
fi


exit 0