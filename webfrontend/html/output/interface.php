<?php
/**
* Submodul: generisches Interface
*
**/


/**
/* Funktion : receive_post_request --> Funktion zum Erhalten eines POST Requests von externen Programmen/Plugins
/* @param: 	leer
/*
/* @return: array für weitere Verwendung
/*			[0] Text für T2S
/*			[1] greet=0 ohne Grußformel, greet=1 mit Grußformel 
/**/

function receive_post_request() {
	
	// http://thisinterestsme.com/receiving-json-post-data-via-php/
	// http://thisinterestsme.com/sending-json-via-post-php/
	global $text, $decoded, $time_start;
	
	// Make sure that it is a POST request.
	if(strcasecmp($_SERVER['REQUEST_METHOD'], 'POST') != 0){
		LOGGING("T2S Interface ** Request method must be POST!", 3);
		exit;
	}
	// Make sure that the content type of the POST request has been set to application/json
	$contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';
	if(strcasecmp($contentType, 'application/json') != 0){
		LOGGING("T2S Interface ** Content type must be: application/json", 3);
		exit;
	}
	// Receive the RAW post data.
	$content = trim(file_get_contents("php://input"));
	// Attempt to decode the incoming RAW post data from JSON.
	$decoded = json_decode($content, true);
	// If json_decode failed, the JSON is invalid.
	if(!is_array($decoded)){
		LOGGING("T2S Interface ** Received content contained invalid JSON!", 3);
		exit;
	}
	LOGGING("T2S Interface ** POST request has been successful processed!", 5);
	return ($decoded);
}



/**
/* Funktion : jsonfile --> Erstellt ein JSON file mit den notwenigen Infos
/* @param: 	leer
/*
/* @return: JSON file für weitere Verwendung
/**/	

function jsonfile($filename)  {
	global $volume, $MessageStorepath, $MP3path, $messageid, $time_start, $filename, $infopath, $config, $ttsinfopath, $filepath, $ttspath, $myIP, $plugindatapath, $lbhomedir, $psubfolder, $hostname, $fullfilename, $text, $textstring;
	
	$filenamebatch = $ttsinfopath."".$fullfilename;
	$ttspath = $MessageStorepath;
	$filepath = $MessageStorepath."".$MP3path;
	$ttsinfopath = LBPDATADIR."/".$infopath."/";
	
	LOGGING("filename of MP3 file: '".$filename."'", 5);
	# prüft ob Verzeichnis für Übergabe existiert
	$is_there = file_exists($ttsinfopath);
	if ($is_there === false)  {
		LOGGING("The interface folder seems not to be available!! System now try to create the 'share' folder.", 4);
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
	$files = array();
	$StorePath = $config['SYSTEM']['path'];
	$split = explode("/", $StorePath);
	#print_r($split);
	if ($split[3] == "data") {
		$files = array(
						'full-path' => $lbhomedir."/".$plugindatapath."/".$psubfolder."/".$filename.".mp3",
						'path' => $lbhomedir."/".$plugindatapath."/".$psubfolder."/",
						'full-server-URL' => $myIP."/".$plugindatapath."/".$psubfolder."/".$filename.".mp3",
						'server-URL' => $myIP."/".$plugindatapath."/".$psubfolder."/",
						'mp3-filename-MD5' => $filename,
						'text' => $textstring
						);
	} else {
		if ($split[5] == "smb") {
			$files = array(
						'full-path' => $StorePath."/".$hostname."/tts/".$filename.".mp3",
						'path' => $StorePath."/".$hostname."/tts/",
						'full-server-URL' => $split[6]."/".$split[7]."/".$hostname."/tts/".$filename.".mp3",
						'server-URL' => $split[6]."/".$split[7]."/".$hostname."/tts/",
						'mp3-filename-MD5' => $filename,
						'text' => $textstring
						);
		} else {
			LOGGING("USB devices and NetShares could not be interfaced!", 3);
			exit;
		}
	}
	#print_r($files);
	try {
		File_Put_Array_As_JSON($filenamebatch, $files, $zip=false);
	} catch (Exception $e) {
		LOGGING("JSON file could not be saved! Please check your system (permission, credentials, etc.)", 3);
		exit;
	}
	LOGGING("MP3 file has been saved successful at '".$files['path']."'.", 6);
	LOGGING("Information of processed T2S has been added to JSON file", 7);
	
}

	


?>