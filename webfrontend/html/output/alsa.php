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
	
	#echo $output;
	
	# wenn MP3 file ohne jingle
	if ((isset($_GET['file'])) and (!isset($_GET['jingle'])))  {
		$sox = shell_exec("sox -v $volume $MessageStorepath$MP3path/$messageid.mp3 -t alsa");
		LOGGING("SoX command has been executed: 'sox -v $volume $MessageStorepath$MP3path/$messageid.mp3 -t alsa -d'", 7);
	}
	# wenn TTS ohne jingle
	elseif ((isset($_GET['text'])) and (!isset($_GET['jingle'])))  {
		$sox = shell_exec("sox -v $volume $MessageStorepath$filename.mp3 -t alsa -d");
		LOGGING("SoX command has been executed: 'sox -v $volume $MessageStorepath$filename.mp3 -t alsa -d'", 7);
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
			$sox = shell_exec("sox -v $volume $MessageStorepath$MP3path/$jingle -t alsa -d");
			$sox = shell_exec("sox -v $volume $MessageStorepath$filename.mp3 -t alsa -d");
			LOGGING("first SoX command (jingle) has been executed: 'sox -v $volume $MessageStorepath$MP3path/$jingle -t alsa -d'", 7);
			LOGGING("second SoX command has been executed: 'sox -v $volume $MessageStorepath$filename.mp3 -t alsa -d'", 7);
		} else {
			LOGGING("The entered jingle file '".$jingle."' is not valid, please correct your syntax! ", 4);
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
			$sox = shell_exec("sox -v $volume $MessageStorepath$MP3path/$jingle -t alsa -d");
			$sox = shell_exec("sox -v $volume $MessageStorepath$MP3path/$messageid.mp3 -t alsa -d");
			LOGGING("first SoX command (jingle) has been executed: 'sox -v $volume $MessageStorepath$MP3path/$jingle -t alsa -d'", 7);
			LOGGING("second SoX command has been executed: 'sox -v $volume $MessageStorepath$MP3path/$messageid.mp3 -t alsa -d'", 7);
		} else {
			LOGGING("The entered jingle file '".$jingle."' is not valid, please correct your syntax! ", 4);
		}
	}
	return;
}


?>
