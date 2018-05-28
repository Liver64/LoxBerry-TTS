<?php

##############################################################################################################################
#
# Version: 	0.0.1
# Datum: 	25.05.2018
# veröffentlicht in: https://github.com/Liver64/LoxBerry-TTS/releases
# 
##############################################################################################################################

ini_set('max_execution_time', 90); 							// Max. Skriptlaufzeit auf 900 Sekunden

include("Helper.php");
include('logging.php');

// setze korrekte Zeitzone
date_default_timezone_set(date("e"));

# prepare variables
$home = $lbhomedir;
$hostname = gethostname();										// hostname LoxBerry
$myIP = $_SERVER["SERVER_ADDR"];								// get IP of LoxBerry
$syntax = $_SERVER['REQUEST_URI'];								// get syntax
$psubfolder = $lbpplugindir;									// get pluginfolder
$lbversion = LBSystem::lbversion();								// get LoxBerry Version
$path = LBSCONFIGDIR; 											// get path to general.cfg
$myFolder = "$lbpconfigdir";									// get config folder
$MessageStorepath = "$lbpdatadir/";								// get T2S folder to store
$pathlanguagefile = "$lbphtmldir/voice_engines/langfiles/";		// get languagefiles
$logpath = "$lbplogdir";										// get log folder
$templatepath = "$lbptemplatedir";								// get templatedir
$t2s_text_stand = "t2s-text_en.ini";							// T2S text Standardfile
$sambaini = $lbhomedir.'/system/samba/smb.conf';				// path to Samba file smb.conf
$searchfor = '[plugindata]';									// search for already existing Samba share
$MP3path = "mp3";												// path to preinstalled numeric MP§ files

$card = ($_GET["card"]);
$device = ($_GET["device"]);
$gain = ($_GET["gain"]);

if ($gain == "") {
$gain = "0";
}

if ($card == "") {
$card = "0";
}

if ($device == "") {
$device = "0";
}

$soundcard="alsa:hw:".$card.",".$device;

$Player1 = "mpg321 -q ";
$Player2 = "mpg123 -q ";
$Player3 = "omxplayer -b -o $soundcard --vol ";

echo '<PRE>'; 
	
#echo $logpath;	
#-- Start Preparation ------------------------------------------------------------------
	
	LOGGING("called syntax: ".$myIP."".urldecode($syntax),5);
	
	// Parsen der Konfigurationsdatei
	if (!file_exists($myFolder.'/tts_all.cfg')) {
		LOGGING('The file tts_all.cfg could not be opened, please try again!', 4);
	} else {
		$config = parse_ini_file($myFolder.'/tts_all.cfg', TRUE);
		LOGGING("TTS config has been loaded", 7);
	}
	#print_r($config);
	#exit;

	# select language file for text-to-speech
	$t2s_langfile = "t2s-text_".substr($config['TTS']['messageLang'],0,2).".ini";				// language file for text-speech
	# checking size of LoxBerry logfile
	LOGGING("Perform Logfile size check",7);
	check_size_logfile();
	# Log success
	LOGGING("All variables has been collected",6);
	LOGGING("Config has been successfull loaded",6);


#-- End Preparation ---------------------------------------------------------------------


switch($_GET['action']) {
	case 'say':
		speak();
	break;
	
	case 'getuser':
		echo '<PRE>';
		echo get_current_user();
	break;	
}

# Funktionen für Skripte ------------------------------------------------------

 
/**
* Function : speak --> translate a text into speech for a single zone
*
* @param: Text or messageid (Number)
* @return: 
**/

function speak() {
	global $text, $messageid, $logging, $textstring, $voice, $config, $time_start, $filename, $MP3path;
			
	#$time_start = microtime(true);
	if ((empty($config['TTS']['t2s_engine'])) or (empty($config['TTS']['messageLang'])))  {
		LOGGING("There is no T2S engine/language selected in Plugin config. Please select before using T2S functionality.", 3);
	exit();
	}
	if ((!isset($_GET['text'])) && (!isset($_GET['messageid'])) && 
		(!isset($_GET['weather'])) && (!isset($_GET['abfall'])) &&
		(!isset($_GET['witz'])) && (!isset($_GET['pollen'])) && 
		(!isset($_GET['warning'])) && (!isset($_GET['bauernregel'])) && 
		(!isset($_GET['distance'])) && (!isset($_GET['clock'])) && 
		(!isset($_GET['calendar']))) {
		LOGGING("Wrong Syntax, please correct! Even 'say&text=' or 'say&messageid=' are necessary to play an anouncement. (check Wiki)", 3);	
	exit;
	}
	create_tts();
	#play_tts($messageid);
	delmp3();
	#$time_end = microtime(true);
	#$t2s_time = $time_end - $time_start;
	LOGGING("Deletion of no longer needed MP3 files has been executed", 7);		
	#LOGGING("The requested single T2S tooks ".round($t2s_time, 2)." seconds to be processed.", 5);	
}


 
/**
* Function : create_tts --> creates an MP3 File based on Text Input
*
* @param: 	Text of Messasge ID
* @return: 	MP3 File
**/		

function create_tts() {
	global $config, $filename, $MessageStorepath, $messageid, $textstring, $home, $time_start, $tmp_batch, $MP3path;
						
	$messageid = !empty($_GET['messageid']) ? $_GET['messageid'] : '0';
		
	isset($_GET['text']) ? $text = $_GET['text'] : $text = '';
	if(isset($_GET['weather'])) {
		// calls the weather-to-speech Function
		include_once("addon/weather-to-speech.php");
		$textstring = substr(w2s(), 0, 500);
		LOGGING("weather-to-speech plugin has been called", 7);
		} 
	elseif (isset($_GET['clock'])) {
		// calls the clock-to-speech Function
		include_once("addon/clock-to-speech.php");
		$textstring = c2s();
		LOGGING("clock-to-speech plugin has been called", 7);
		}
	elseif (isset($_GET['pollen'])) {
		// calls the pollen-to-speech Function
		include_once("addon/pollen-to-speach.php");
		$textstring = substr(p2s(), 0, 500);
		LOGGING("pollen-to-speech plugin has been called", 7);
		}
	elseif (isset($_GET['warning'])) {
		// calls the weather warning-to-speech Function
		include_once("addon/weather-warning-to-speech.php");
		$textstring = substr(ww2s(), 0, 500);
		LOGGING("weather warning-to-speech plugin has been called", 7);
	}
	elseif (isset($_GET['distance'])) {
		// calls the time-to-destination-speech Function
		include_once("addon/time-to-destination-speech.php");
		$textstring = substr(tt2t(), 0, 500);
		LOGGING("time-to-distance speech plugin has been called", 7);
		}
	elseif (isset($_GET['witz'])) {
		// calls the weather warning-to-speech Function
		include_once("addon/gimmicks.php");
		$textstring = substr(GetWitz(), 0, 1000);
		LOGGING("Joke plugin has been called", 7);
		}
	elseif (isset($_GET['bauernregel'])) {
		// calls the weather warning-to-speech Function
		include_once("addon/gimmicks.php");
		$textstring = substr(GetTodayBauernregel(), 0, 500);
		LOGGING("Bauernregeln plugin has been called", 7);
		}
	elseif (isset($_GET['abfall'])) {
		// calls the wastecalendar-to-speech Function
		include_once("addon/waste-calendar-to-speech.php");
		$textstring = substr(muellkalender(), 0, 500);
		LOGGING("waste calendar-to-speech  plugin has been called", 7);
		}
	elseif (isset($_GET['calendar'])) {
		// calls the calendar-to-speech Function
		include_once("addon/waste-calendar-to-speech.php");
		$textstring = substr(calendar(), 0, 500);
		LOGGING("calendar-to-speech plugin has been called", 7);
		}
	elseif ((empty($messageid)) && (!isset($_GET['text'])) and (isset($_GET['playbatch']))) {
		LOGGING("No text has been entered", 3);
		exit();
		}
	elseif (!empty($messageid)) { # && ($rawtext != '')) {
		// takes the messageid
		$messageid = $_GET['messageid'];
		if (file_exists($MessageStorepath."".$MP3path."/".$messageid.".mp3") === true)  {
			LOGGING("Messageid '".$messageid."' has been entered", 7);
		} else {
			LOGGING("The corrosponding messageid file '".$messageid.".mp3' does not exist or could not be played. Please check your directory or syntax!", 3);
			exit;
		}	
		}
	elseif ((empty($messageid)) && ($text <> '')) {
		// prepares the T2S message
		$textstring = (substr($_GET['text'], 0, 500));
		LOGGING("Textstring has been entered", 7);		
		}	
	
	// encrypt MP3 file as MD5 Hash
	$filename  = md5($textstring);
	#echo 'messageid: '.$messageid.'<br>';
	#echo 'textstring: '.$textstring.'<br>';
	#echo 'filename: '.$filename.'<br>';
	// calls the various T2S engines depending on config)
	if (($messageid == '0') && ($textstring != '')) {
		if ($config['TTS']['t2s_engine'] == 1001) {
			include_once("voice_engines/VoiceRSS.php");
			LOGGING("VoiceRSS has been successful selected", 7);		
		}
		if ($config['TTS']['t2s_engine'] == 3001) {
			include_once("voice_engines/MAC_OSX.php");
			LOGGING("/MAC_OSX has been successful selected", 7);		
		}
		if ($config['TTS']['t2s_engine'] == 6001) {
			include_once("voice_engines/ResponsiveVoice.php");
			LOGGING("ResponsiveVoice has been successful selected", 7);		
		}
		if ($config['TTS']['t2s_engine'] == 7001) {
			include_once("voice_engines/Google.php");
			LOGGING("Google has been successful selected", 7);		
		}
		if ($config['TTS']['t2s_engine'] == 5001) {
			include_once("voice_engines/Pico_tts.php");
			LOGGING("Pico has been successful selected", 7);		
		}
		if ($config['TTS']['t2s_engine'] == 4001) {
			include_once("voice_engines/Polly.php");
			LOGGING("AWS Polly has been successful selected", 7);		
		}
	t2s($messageid, $MessageStorepath, $textstring, $filename);
	return $messageid;
	}
}




/**
/* Funktion : delmp3 --> löscht die hash5 codierten MP3 Dateien aus dem Verzeichnis 'messageStorePath'
/*
/* @param:  nichts
/* @return: nichts
**/

 function delmp3() {
	global $config, $debug, $time_start, $MessageStorepath;
	
	# http://www.php-space.info/php-tutorials/75-datei,nach,alter,loeschen.html	
	$dir = $MessageStorepath;
    $folder = dir($dir);
	$store = '-'.$config['MP3']['MP3store'].' days';
	while ($dateiname = $folder->read()) {
	    if (filetype($dir.$dateiname) != "dir") {
            if (strtotime($store) > @filemtime($dir.$dateiname)) {
					if (strlen($dateiname) == 36) {
						if (@unlink($dir.$dateiname) != false)
							LOGGING($dateiname.' has been deleted<br>', 7);
						else
							LOGGING($dateiname.' could not be deleted<br>', 7);
					}
			}
        }
    }
	LOGGING("All files according to criteria were successfully deleted", 7);
    $folder->close();
    return; 	 
 }


/** --> NOT IN USE
*
* Function : play_tts --> play T2S or MP3 File
*
* @param: 	MessageID, Parameter zur Unterscheidung ob Gruppen oder EInzeldurchsage
* @return: empty
**/		

function play_tts($messageid) {
	global $volume, $config, $sonos, $text, $messageid, $sonoszone, $sonoszonen, $master, $myMessagepath, $coord, $actual, $player, $time_start, $t2s_batch, $filename, $textstring, $home, $MP3path, $sleeptimegong, $lbpplugindir, $logpath, $try_play, $MessageStorepath;
		
	if (isset($_GET['messageid'])) {
		// Set path if messageid
		$mpath = $myMessagepath."".$MP3path;
		LOGGING("Path for messageid's been adopted", 7);		
	} else {
		// Set path if T2S
		$mpath = $myMessagepath;
		LOGGING("Path for T2S been adopted", 7);	
	}
	// Playgong/jingle to be played upfront
	if(isset($_GET['playgong'])) {
		if ($_GET['playgong'] == 'no')	{
			LOGGING("'playgong=no' could not be used in syntax, only 'playgong=yes' or 'playgong=file' are allowed", 3);
			exit;
		}
		if(empty($config['MP3']['file_gong'])) {
			LOGGING("Standard file for jingle is missing in Plugin config. Please maintain before usage.", 3);
			exit;	
		}
		if (($_GET['playgong'] != "yes") and ($_GET['playgong'] != "no") and ($_GET['playgong'] != " ")) {
			$file = $_GET['playgong'];
			$file = $file.'.mp3';
			$valid = mp3_files($file);
		}
	}
}



 

?>

