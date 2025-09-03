<?php

/**
* Submodul: RpI ALSA Output
*
**/


/**
/* Funktion : alsa_ob --> Funktion zum abspielen von TTS info auf Standard RpI
/* @param: 	leer
/*
/* @return: 
/**/

function alsa_ob() {
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
		$sox = shell_exec("tsp -n sox -v $volume $mp3path/$messageid.mp3 -t alsa");
		LOGINF("output/alsa.php: SoX command has been executed: 'sox -v $volume $mp3path/$messageid.mp3 -t alsa'");
	}
	# wenn TTS ohne jingle
	elseif ((isset($_GET['text'])) and (!isset($_GET['jingle'])))  {
		$sox = shell_exec("tsp -n sox -v $volume $ttspath/$filename.mp3 -t alsa");
		LOGINF("output/alsa.php: SoX command has been executed: 'sox -v $volume $ttspath/$filename.mp3 -t alsa'");
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
			$sox = shell_exec("tsp -n sox -v $volume $mp3path/$jingle -t alsa");
			$sox = shell_exec("tsp -n sox -v $volume $ttspath/$filename.mp3 -t alsa");
			LOGINF("output/alsa.php: first SoX command (jingle) has been executed: 'sox -v $volume $mp3path/$jingle -t alsa'");
			LOGINF("output/alsa.php: second SoX command has been executed: 'sox -v $volume $ttspath/$filename.mp3 -t alsa'");
		} else {
			LOGWARN("output/alsa.php: The entered jingle file '".$jingle."' is not valid, please correct your syntax! ");
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
			$sox = shell_exec("tsp -n sox -v $volume $mp3path/$jingle -t alsa");
			$sox = shell_exec("tsp -n sox -v $volume $mp3path/$messageid.mp3 -t alsa");
			LOGINF("output/alsa.php: first SoX command (jingle) has been executed: 'sox -v $volume $mp3path/$jingle -t alsa'");
			LOGINF("output/alsa.php: second SoX command has been executed: 'sox -v $volume $mp3path/$messageid.mp3 -t alsa'");
		} else {
			LOGWARN("output/alsa.php: The entered jingle file '".$jingle."' is not valid, please correct your syntax! ");
		}
	}
	return;
}


?>
