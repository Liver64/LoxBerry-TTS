<?php

/**
* Submodul: RpI USB Output
*
**/

/**
/* Funktion : usb --> Funktion zum abspielen von TTS info auf USB Soundkarte
/* @param: 	leer
/*
/* @return: 
/**/
#	Version: 	1.0.0 - Initial Release
#				1.0.1 - Änderung von alsa -d zu alsa dmix:1,0


function usb() {
	global $volume, $MessageStorepath, $MP3path, $messageid, $filename, $output, $config;
	
	$mp3path = $config['SYSTEM']['mp3path'];
	$ttspath = $config['SYSTEM']['ttspath'];
	
	# Umgebungsvariablen für task-spooler setzen (z.B. Socket für eigene Queue)
	putenv("TS_SOCKET=/dev/shm/ttsplugin.sock");
	putenv("TS_MAXFINISHED=10");
	putenv("TS_MAXCONN=10");
	putenv("TS_MAILTO=\"\"");
	
	# wenn MP3 file ohne jingle
	if ((isset($_GET['file'])) and (!isset($_GET['jingle'])))  {
		$sox = shell_exec("tsp -n sox -v $volume $mp3path/$messageid.mp3 -t alsa dmix:1,0");
		LOGGING("output/usb.php: SoX command has been executed: 'sox -v $volume $mp3path/$messageid.mp3 -t alsa dmix:1,0'", 7);
	}
	# wenn TTS ohne jingle
	elseif ((isset($_GET['text'])) and (!isset($_GET['jingle'])))  {
		$sox = shell_exec("tsp -n sox -v $volume $ttspath/$filename.mp3 -t alsa dmix:1,0");
		LOGGING("output/usb.php: SoX command has been executed: 'sox -v $volume $ttspath/$filename.mp3 -t alsa dmix:1,0'", 7);
	}
	# wenn TTS mit jingle
	elseif ((isset($_GET['text'])) and (isset($_GET['jingle'])))  {
		$jingle = $_GET['jingle'];
		if (empty($_GET['jingle']))  {
			$jingle = $config['MP3']['file_gong'];
		} else {
			$jingle = $_GET['jingle'].'.mp3';
		}
		# prüft ob jingle vorhanden ist
		$valid = mp3_files($jingle);
		if ($valid === true) {
			$sox = shell_exec("tsp -n sox -v $volume $mp3path/$jingle -t alsa dmix:1,0");
			$sox = shell_exec("tsp -n sox -v $volume $ttspath/$filename.mp3 -t alsa dmix:1,0");
			LOGGING("output/usb.php: first SoX command (jingle) has been executed: 'sox -v $volume $mp3path/$jingle -t alsa dmix:1,0'", 7);
			LOGGING("output/usb.php: second SoX command has been executed: 'sox -v $volume $ttspath/$filename.mp3 -t alsa dmix:1,0'", 7);
		} else {
			LOGGING("output/usb.php: The entered jingle file '".$jingle."' is not valid, please correct your syntax! ", 4);
		}
	}
	# wenn file mit jingle
	elseif ((isset($_GET['file'])) and (isset($_GET['jingle'])))  {
		$jingle = $_GET['jingle'];
		if (empty($_GET['jingle']))  {
			$jingle = $config['MP3']['file_gong'];
		} else {
			$jingle = $_GET['jingle'].'.mp3';
		}
		# prüft ob jingle vorhanden ist
		$valid = mp3_files($jingle);
		if ($valid === true) {
			$sox = shell_exec("tsp -n sox -v $volume $mp3path/$jingle -t alsa dmix:1,0");
			$sox = shell_exec("tsp -n sox -v $volume $mp3path/$messageid.mp3 -t alsa dmix:1,0");
			LOGGING("output/usb.php: first SoX command (jingle) has been executed: 'sox -v $volume $mp3path/$jingle -t alsa dmix:1,0'", 7);
			LOGGING("output/usb.php: second SoX command has been executed: 'sox -v $volume $mp3path/$messageid.mp3 -t alsa dmix:1,0'", 7);
		} else {
			LOGGING("output/usb.php: The entered jingle file '".$jingle."' is not valid, please correct your syntax! ", 4);
		}
	}
	return;
}


?>