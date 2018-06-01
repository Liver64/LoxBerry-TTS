<?php

##############################################################################################################################
#
# Version: 	0.0.4
# Datum: 	25.05.2018
# veröffentlicht in: https://github.com/Liver64/LoxBerry-TTS/releases
# 
##############################################################################################################################

ini_set('max_execution_time', 90); 							// Max. Skriptlaufzeit auf 900 Sekunden

include("helper.php");
include('logging.php');

// setze korrekte Zeitzone
date_default_timezone_set(date("e"));

# prepare variables
$home = $lbhomedir;												// get Folder 
$hostname = gethostname();										// hostname LoxBerry 
$myIP = $_SERVER["SERVER_ADDR"];								// get IP of LoxBerry
$syntax = $_SERVER['REQUEST_URI'];								// get syntax 
$psubfolder = $lbpplugindir;									// get pluginfolder 
$lbversion = LBSystem::lbversion();								// get LoxBerry Version
$path = LBSCONFIGDIR; 											// get path to general.cfg
$myFolder = "$lbpconfigdir";									// get config folder
#$MessageStorepath = "$lbpdatadir/";								// get T2S folder to store
$pathlanguagefile = "$lbphtmldir/voice_engines/langfiles/";		// get languagefiles
$logpath = "$lbplogdir";										// get log folder
$templatepath = "$lbptemplatedir";								// get templatedir
$t2s_text_stand = "t2s-text_en.ini";							// T2S text Standardfile
$sambaini = $lbhomedir.'/system/samba/smb.conf';				// path to Samba file smb.conf
$searchfor = '[plugindata]';									// search for already existing Samba share
$MP3path = "mp3";												// path to preinstalled numeric MP§ files
$Home = getcwd();												// get Plugin Pfad (/opt/loxberry/webfrontend/html/plugins/tts_all)

echo '<PRE>'; 
global $mp3;
	
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
	LOGGING("Config has been successfull loaded",6);
	$folderpeace = explode("/",$config['SYSTEM']['path']);
	if ($folderpeace[3] != "data") {
		// wenn NICHT local dir als Speichermedium selektiert wurde
		$MessageStorepath = $config['SYSTEM']['path']."/".$hostname."/tts/";
	} else {
		// wenn local dir als Speichermedium selektiert wurde
		$MessageStorepath = $config['SYSTEM']['path']."/";
	}
	#print_r($config);
	#exit;
	$soundcard = $config['SYSTEM']['card'];
	
switch ($soundcard) {
	case '001':			// Soundcard bcm2835
		$card = "0";
		$device = "0";
	break;
	case '002':			// Soundcard bcm2835 IEC958/HDMI]
		$card = "0";
		$device = "1";
	break;
	case '003':			// USB Soundcard  
		$card = "1";
		$device = "0";
	break;
	case '004':			// ext. Programm
		$card = "";
		$device = "";
	break;
	default;			// Soundcard bcm2835
		$card = "0";
		$device = "0";
	break;
}
	# select language file for text-to-speech
	$t2s_langfile = "t2s-text_".substr($config['TTS']['messageLang'],0,2).".ini";				// language file for text-speech
	LOGGING("All variables has been collected",6);
	
	if ($soundcard != "004")  {			// Ausgabe an Soundkarte
		$soundcard="alsa:hw:".$card.",".$device;
				
		# prüfen ob Volume in syntax, wenn nicht Std. von Config
		If (!isset($_GET["volume"])) {
			$volume = $config['TTS']['volume'];
			LOGGING("Standardvolume from Config beeen adopted",7);
		} else { 
			$volume = $_GET["volume"];
			LOGGING("Volume from Syntax beeen adopted",7);
		}
		speak();
		# http://sox.sourceforge.net/sox.html
		$sox = shell_exec("play -v 2.0 $MessageStorepath$filename.mp3");
		LOGGING("SoX command has been executed: 'play $MessageStorepath$filename.mp3'", 7);
	} else {							// Ausgabe für ext. Program
		
	}
	# checking size of LoxBerry logfile
	LOGGING("Perform Logfile size check",7);
	check_size_logfile();
	exit;

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
	global $text, $messageid, $logging, $textstring, $voice, $config, $volume, $time_start, $filename, $MP3path;
			
	#$time_start = microtime(true);
	if ((empty($config['TTS']['t2s_engine'])) or (empty($config['TTS']['messageLang'])))  {
		LOGGING("There is no T2S engine/language selected in Plugin config. Please select before using T2S functionality.", 3);
	exit();
	}
	if ((!isset($_GET['text'])) && (!isset($_GET['file'])) && 
		(!isset($_GET['weather'])) && (!isset($_GET['abfall'])) &&
		(!isset($_GET['witz'])) && (!isset($_GET['pollen'])) && 
		(!isset($_GET['warning'])) && (!isset($_GET['bauernregel'])) && 
		(!isset($_GET['distance'])) && (!isset($_GET['clock'])) && 
		(!isset($_GET['calendar']))) {
		LOGGING("Wrong Syntax, please correct! Even 'say&text=' or 'say&messageid=' are necessary to play an anouncement. (check Wiki)", 3);	
	exit;
	}
	create_tts();
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
						
	$messageid = !empty($_GET['file']) ? $_GET['file'] : '0';
		
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
		$messageid = $_GET['file'];
		if (file_exists($MessageStorepath."".$MP3path."/".$messageid.".mp3") === true)  {
			LOGGING("File '".$messageid."' has been entered", 7);
		} else {
			LOGGING("The corrosponding file '".$messageid.".mp3' does not exist or could not be played. Please check your directory or syntax!", 3);
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

?>

