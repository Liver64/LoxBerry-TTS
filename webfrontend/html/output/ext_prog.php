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

function ext() {
	global $volume, $MessageStorepath, $MP3path, $messageid, $filename, $infopath, $config, $ttsinfopath, $filepath, $ttspath;
	
	$ttspath = $MessageStorepath;
	$filepath = $MessageStorepath."".$MP3path;
	$ttsinfopath = $MessageStorepath."".$infopath."/";
	
	# prüft ob Verzeichnis für Übergabe existiert
	$is_there = file_exists($ttsinfopath);
	if ($is_there === false)  {
		LOGGING("The info folder seems not to be available!! System now try to create the 'info' folder.", 4);
		mkdir($ttspath."".$infopath);
	} else {
		LOGGING("Folder '".$infopath."' to pass over audio infos is already there (".$ttsinfopath.")", 5);
	}
	# prüft erneut
	$check_is_there = file_exists($ttsinfopath);
	if ($check_is_there === false)  {
		LOGGING("The info folder could not been created!! Please create manually the 'info' folder in '".$ttspath."' or change your storage in Config.", 3);
		exit;
	}
	# Löschen alle vorhandenen Dateien aus dem info folder
	chdir($ttsinfopath);
	foreach (glob("*.*") as $file) {
		LOGGING("File: '".$file."' has been deleted from 'info' folder.",5);
		unlink($file);
	}
	txtfile();
	jsonfile();
	LOGGING("File/source Info for external usage has been successful created", 5);					
}



/**
/* Funktion : txtfile --> Erstellt ein TXT file mit den notwenigen Infos
/* @param: 	leer
/*
/* @return: TXT file für weitere Verwendung
/**/	

function txtfile()  {
	global $volume, $MessageStorepath, $MP3path, $messageid, $filename, $infopath, $config, $ttsinfopath, $filepath, $ttspath;
	
	$fullfilename = $filename.".txt";
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
	fwrite($file, "$ttspath$filename.mp3\n" );
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
	global $volume, $MessageStorepath, $MP3path, $messageid, $filename, $infopath, $config, $ttsinfopath, $filepath, $ttspath;
	
	$fullfilename = $filename.".json";
	$filenamebatch = $ttsinfopath."".$fullfilename;
	
	$files = array();
		if (isset($_GET['jingle']))  {
		$jingle = $_GET['jingle'];
		if (empty($_GET['jingle']))  {
			$jingle = $config['MP3']['file_gong'];
		} else {
			$jingle = $_GET['jingle'].'.mp3';
		}
		array_push($files, $filepath."/".$jingle);
		LOGGING("Source for jingle MP3 '".$filepath."/".$jingle."' has been added to JSON file", 7);
	}
	array_push($files, $ttspath."".$filename.".mp3");
	File_Put_Array_As_JSON($filenamebatch, $files, $zip=false);
	LOGGING("Source for TTS '".$ttspath."".$filename.".mp3' has been added to JSON file", 7);
}
	



?>