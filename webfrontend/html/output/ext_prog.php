<?php

/**
* Submodul: externe Programme
*
**/



/**
/* Funktion : ext --> Funktion zum Speichern und Übergeben der TTS info an externe Programme/Plugins
/* @param: 	leer
/*
/* @return: TXT und JSON file für weitere Verwendung
/**/

function ext_prog($text) {
	global $volume, $MessageStorepath, $MP3path, $messageid, $filename, $infopath, $config, $ttsinfopath, $filepath, $ttspath, $shortcut, $text, $plugindatapath, $lbhomedir, $psubfolder, $hostname;
	
	$ttspath = $MessageStorepath;
	$filepath = $MessageStorepath."".$MP3path;
	$ttsinfopath = LBPDATADIR."/".$infopath."/";
	
	#echo 'TTS Empfang: '.$text.'<br>';
	$filename  = md5($text);
	# prüft ob Verzeichnis für Übergabe existiert
	$is_there = file_exists($ttsinfopath);
	if ($is_there === false)  {
		LOGGING("The info folder seems not to be available!! System now try to create the 'info' folder.", 4);
		mkdir($ttsinfopath);
		LOGGING("Folder '".$ttsinfopath."' has been succesful created.", 5);
	} else {
		LOGGING("Folder '".$infopath."' to pass over audio infos is already there (".$ttsinfopath.")", 5);
	}
	# Löschen alle vorhandenen Dateien aus dem info folder
	chdir($ttsinfopath);
	foreach (glob("*.*") as $file) {
		LOGGING("File: '".$file."' has been deleted from '".$infopath."' folder.",5);
		#unlink($file);
	}
	#txtfile();
	jsonfile();
	delmp3();
	LOGGING("Source Info for external usage has been successful created", 5);					
}



/**
/* Funktion : txtfile --> Erstellt ein TXT file mit den notwenigen Infos
/* @param: 	leer
/*
/* @return: TXT file für weitere Verwendung
/**/	

function txtfile()  {
	global $volume, $MessageStorepath, $MP3path, $messageid, $filename, $infopath, $config, $ttsinfopath, $filepath, $ttspath;
	
	$fullfilename = "t2s_source.txt";
	$filenamebatch = $ttsinfopath."".$fullfilename;
	$file = fopen($filenamebatch, "a+");
	
	if (isset($_GET['jingle']))  {
		$jingle = $_GET['jingle'];
		if (empty($_GET['jingle']))  {
			$jingle = $config['MP3']['file_gong'];
			LOGGING("Standardjingle from config has been adopted", 7);
		} else {
			$jingle = $_GET['jingle'].'.mp3';
			LOGGING("Individual jingle has been adopted", 7);
		}
		fwrite($file, "$filepath/$jingle\n" );
		LOGGING("Source for jingle MP3 '".$filepath."/".$jingle."' has been added to TXT file", 7);
	}
	if (isset($_GET['file']))  {
		$mp3file = $_GET['file'];
		fwrite($file, "$filepath/$mp3file.mp3\n" );
	} else {
		fwrite($file, "$ttspath$filename.mp3\n" );
	}
	LOGGING("Source for TTS '".$ttspath."".$filename.".mp3' has been added to TXT file", 7);
	fclose($file);
}



/**
/* Funktion : jsonfile --> Erstellt ein JSON file mit den notwenigen Infos
/* @param: 	leer
/*
/* @return: JSON file für weitere Verwendung
/**/	

function jsonfile()  {
	global $volume, $MessageStorepath, $MP3path, $messageid, $filename, $infopath, $config, $ttsinfopath, $filepath, $ttspath, $myIP, $plugindatapath, $lbhomedir, $psubfolder, $hostname;
	
	$fullfilename = "t2s_source.json";
	$filenamebatch = $ttsinfopath."".$fullfilename;
	
	$files = array();
		#if (isset($_GET['jingle']))  {
		#	$jingle = $_GET['jingle'];
		#if (empty($_GET['jingle']))  {
		#	$jingle = $config['MP3']['file_gong'];
		#} else {
		#	$jingle = $_GET['jingle'].'.mp3';
		#}
		#array_push($files, $filepath."/".$jingle);
		#LOGGING("Source for jingle MP3 '".$filepath."/".$jingle."' has been added to JSON file", 7);
	#}
		
	#if (isset($_GET['file']))  {
	#	$mp3file = $_GET['file'];
	#	array_push($files, $filepath."/".$mp3file.".mp3");
	#} else {
		$StorePath = $config['SYSTEM']['path'];
		$split = explode("/", $StorePath);
		print_r($split);
		if ($split[3] == "data") {
			$intData = $lbhomedir."/".$plugindatapath."/".$psubfolder."/".$filename.".mp3";
			$extData = $myIP."/".$plugindatapath."/".$psubfolder."/".$filename.".mp3";
		} else {
			if ($split[5] == "smb") {
				$intData = $StorePath."/".$hostname."/tts/".$filename.".mp3";
				$extData = $split[6]."/".$split[7]."/".$hostname."/tts/".$filename.".mp3";
			} else {
				LOGGING("USB devices and NetShares could not be interfaced!", 3);
				exit;
			}
		}
		#echo "IntData: ".$intData."<br>";
		#echo "ExtData: ".$extData."<br>";
		array_push($files, $intData);
		array_push($files, $extData);
		LOGGING("Internal source for TTS '".$intData."' has been added to JSON file", 7);
		LOGGING("External source for TTS '".$extData."' has been added to JSON file", 7);
	#}
	File_Put_Array_As_JSON($filenamebatch, $files, $zip=false);
}

	


?>