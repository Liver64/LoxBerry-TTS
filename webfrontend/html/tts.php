<?php

##############################################################################################################################
#
# Version: 	1.0.8
# Datum: 	15.05.2019
# veröffentlicht in: https://github.com/Liver64/LoxBerry-TTS/releases
# 
##############################################################################################################################

// ToDo
//
// syntax wizard, add Loxone Template
// add debug switch to frontend



ini_set('max_execution_time', 90); 								// Max. Skriptlaufzeit auf 90 Sekunden

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
$myFolder = "$lbpdatadir";										// get data folder
$myConfigFolder = "$lbpconfigdir";								// get config folder
$myConfigFile = "tts_all.cfg";									// get config file
$pathlanguagefile = "$lbphtmldir/voice_engines/langfiles/";		// get languagefiles
$logpath = "$lbplogdir";										// get log folder
$templatepath = "$lbptemplatedir";								// get templatedir
$t2s_text_stand = "t2s-text_en.ini";							// T2S text Standardfile
$sambaini = $lbhomedir.'/system/samba/smb.conf';				// path to Samba file smb.conf
$searchfor = '[plugindata]';									// search for already existing Samba share
$plugindatapath = "plugindata";									// get plugindata folder
$MP3path = "mp3";												// path to preinstalled numeric MP3 files
#$infopath = "interface";										// path to info for ext. Prog
$Home = getcwd();												// get Plugin Pfad
#$fullfilename = "t2s_source.json";								// filename to pass info back to ext. Prog#.
#$logging_config = "interface.cfg";								// fixed filename to pass log entries to ext. Prog.
$interfacefolder = "interface";
$ttsfolder = "tts";
$mp3folder = "mp3";
$lbphtmldir = LBPHTMLDIR;
$lbpbindir = LBPBINDIR;
$logif = array();

ini_set('max_execution_time', 20); 	


#echo '<PRE>'; 

global $text, $messageid, $LOGGING, $data, $lbpbindir, $textstring, $level, $logif, $voice, $config, $volume, $time_start, $filename, $MP3path, $mp3, $text_ext, $logging_config, $myConfigFile, $lbhomedir, $params, $jsonfile;

$level = LBSystem::pluginloglevel();
	
$params = [	"name" => "Text2speech",
			"filename" => "$lbplogdir/text2speech.log",
			"append" => 1,
			"addtime" => 1,
			];
$log = LBLog::newLog($params);	

// used for single logging
$plugindata = LBSystem::plugindata();

LOGSTART("PHP started");

$time_start_total = microtime(true);

#-- Start Preparation ------------------------------------------------------------------
	LOGGING("tts.php: called syntax: ".$myIP."".urldecode($syntax),5);
	// Parsen der Konfigurationsdatei
	if (!file_exists($myConfigFolder.'/tts_all.cfg')) {
		LOGGING('tts.php: The file tts_all.cfg could not be opened, please try again!', 3);
		exit;
	} else {
		$config = parse_ini_file($myConfigFolder.'/tts_all.cfg', TRUE);
		LOGGING("tts.php: T2S config has been loaded", 7);
	}
	#print_r($config);
	create_symlinks();
	LOGGING("tts.php: Config has been successfull loaded",6);
		
	# wählt Sprachdatei für hinterlegte Texte der Add-on's
	$t2s_langfile = "t2s-text_".substr($config['TTS']['messageLang'],0,2).".ini";				// language file for text-speech
	LOGGING("tts.php: All variables has been collected",6);
	$soundcard = $config['SYSTEM']['card'];
		# prüfen ob Volume in syntax, wenn nicht Std. von Config
		if (!isset($_GET["volume"])) {
			$volume = $config['TTS']['volume'];
			LOGGING("tts.php: Standardvolume from Config beeen adopted",7);
		} else { 
			if(isset($_GET['volume']) && is_numeric($_GET['volume']) && $_GET['volume'] >= 0 && $_GET['volume'] <= 500) {
				$volume = $_GET["volume"];
				LOGGING("tts.php: Volume from Syntax beeen adopted",7);
			} else {
				LOGGING("tts.php: The entered volume is out of range. Please use 0 to 500",4);
				$volume = $config['TTS']['volume'];
				LOGGING("tts.php: As backup the Standardvolume from Config beeen adopted. Please correct your syntax",4);
			}
		}
		# Volume prozentual für sox (1=100%)
		$volume = $volume / 100;
		$oldlog = $log->loglevel;
	
	

#-- End Preparation ---------------------------------------------------------------------

	global $soundcard, $config, $lbpbindir, $text, $data, $log, $time_start_total, $logif, $decoded, $greet, $textstring, $filename, $myConfigFile;
	
	# Prüfen ob Request per Interface reinkommt
	$tmp_content = file_get_contents("php://input");
	if ($tmp_content == true)  {
	# *** Lese Daten von ext. Call ***
		$log->loglevel(7);
		LOGGING("tts.php: Set Loglevel temporally to level 7 to process Interface logging", 5);
		require_once('output/interface.php');
		LOGGING("tts.php: T2S Interface: POST request has been received and will be processed!", 6);
		process_post_request();
		# Deklaration der variablen
		$text = $decoded['text'];
		$greet = $decoded['greet'];
	} elseif (isset($_GET['json']))  {
	 # *** Lese Daten von URL ***
		require_once('output/interface.php');
		LOGGING("tts.php: T2S Interface: JSON is set and will be processed!", 6);
		# Deklaration der variablen
		$text = $_GET['text'];
		isset($_GET['greet']) ?	$greet = $_GET['greet'] : $greet = " ";
	} else {
		create_tts();
	}
	# prüfe of TTS Anbieter und ggf. Stimme gewählt wurde
	if ((empty($config['TTS']['t2s_engine'])) or (empty($config['TTS']['messageLang'])))  {
		LOGGING("tts.php: There is no T2S engine/language selected in Plugin config. Please select before using T2S functionality.", 3);
		exit();
	}
	# Prüfung ob syntax korrekt eingeben wurde.
	if ((!isset($_GET['text'])) && (!isset($_GET['file'])) && 
		(!isset($_GET['weather'])) && (!isset($_GET['abfall'])) &&
		(!isset($_GET['witz'])) && (!isset($_GET['pollen'])) && 
		(!isset($_GET['warning'])) && (!isset($_GET['bauernregel'])) && 
		(!isset($_GET['distance'])) && (!isset($_GET['clock'])) &&
		(!isset($_GET['calendar']))&& ($text == ' ') && (!isset($data))) {
		LOGGING("tts.php: Something went wrong. Please try again and check your syntax. (check Wiki)", 3);
		exit;
	}
	switch ($soundcard) {
		case '001':			// NULL
			exit;
		break;
		case '002':			// Soundcard bcm2835
			require_once('output/alsa.php');
			shell_exec("export AUDIODRIVER=alsa");
			shell_exec("export AUDIODEV=default:0");
			alsa_ob();
		break;
		case '003':			// Soundcard bcm2835 IEC958/HDMI]
			require_once('output/alsa.php');
			shell_exec("export AUDIODRIVER=alsa");
			shell_exec("export AUDIODEV=sysdefault:0");
			alsa_ob();
		break;
		case '004':			// 
			require_once('output/alsa.php');
			shell_exec("export AUDIODRIVER=alsa");
			shell_exec("export AUDIODEV=dmix:0,0");
			alsa_ob();
		break;
		case '005':			// 
			require_once('output/alsa.php');
			shell_exec("export AUDIODRIVER=alsa");
			shell_exec("export AUDIODEV=dmix:0,1");
			alsa_ob();
		break;
		case '006':			// 
			require_once('output/alsa.php');
			shell_exec("export AUDIODRIVER=alsa");
			shell_exec("export AUDIODEV=dsnoop:0,0");
			alsa_ob();
		break;
		case '007':			// 
			require_once('output/alsa.php');
			shell_exec("export AUDIODRIVER=alsa");
			shell_exec("export AUDIODEV=dsnoop:0,1");
			alsa_ob();
		break;
		case '008':			// 
			require_once('output/alsa.php');
			shell_exec("export AUDIODRIVER=alsa");
			shell_exec("export AUDIODEV=hw:0,0");
			alsa_ob();
		break;
		case '009':			// 
			require_once('output/alsa.php');
			shell_exec("export AUDIODRIVER=alsa");
			shell_exec("export AUDIODEV=hw:0,1");
			alsa_ob();
		break;
		case '010':			// 
			require_once('output/alsa.php');
			shell_exec("export AUDIODRIVER=alsa");
			shell_exec("export AUDIODEV=plughw:0,0");
			alsa_ob();
		break;
		case '011':			// 
			require_once('output/alsa.php');
			shell_exec("export AUDIODRIVER=alsa");
			shell_exec("export AUDIODEV=plughw:0,1");
			alsa_ob();
		break;
		case '012':			// USB Soundcard 1
			//require_once('output/usb.php');
			require_once('output/alsa.php');
			$deviceno = $config['SYSTEM']['usbdevice'];
			getusbcard();
			shell_exec("export AUDIODEV=".$myteccard.",1,".$deviceno);
			alsa_ob();
		break;
		case '013':			// USB Soundcard 2	
			//require_once('output/usb.php');
			require_once('output/alsa.php');
			$deviceno = $config['SYSTEM']['usbdevice'];
			getusbcard();
			shell_exec("export AUDIODEV=".$myteccard.",2,".$deviceno);
			alsa_ob();
		break;
		default;			// Soundcard bcm2835
			require_once('output/alsa.php');
			shell_exec("export AUDIODRIVER=alsa");
			$output = shell_exec("export AUDIODEV=hw:0,0");
		break;
		# The hw:X,Y comes from this mapping of your hardware -- in this case, X is the card number, while Y is the device number.
		# https://superuser.com/questions/53957/what-do-alsa-devices-like-hw0-0-mean-how-do-i-figure-out-which-to-use
		# hw:CARD=sndrpihifiberry,DEV=0   ist device number
		# https://www.alsa-project.org/main/index.php/Asoundrc
	}
	if ($tmp_content == true || isset($_GET['json']))  {
		create_tts();
	}
	LOGGING("tts.php: Processing time of the complete T2S request tooks: " . round((microtime(true)-$time_start_total), 2) . " Sek.", 6);
	if ($tmp_content == true)  {
		json($filename);
	}	
	LOGEND("PHP finished"); 
exit;




/**
* Function : getusbcard --> get technical name of USB Card to process output
*
* @param: 	None
* @return: 	tech. name
**/	

function getusbcard()  {
	
	global $config, $lbpbindir, $log, $myteccard;

	$json = file_get_contents($lbpbindir."/hats.json");
	$cfg = json_decode($json, True);
	$mycard = $config['SYSTEM']['usbcard'];
	$myteccard = $cfg[$mycard]['output'];
	return($myteccard);
}

 
/**
* Function : create_tts --> creates an MP3 File based on Text Input
*
* @param: 	Text of Messasge ID
* @return: 	MP3 File
**/		

function create_tts() {
	
	global $config, $filename, $log, $data, $messageid, $textstring, $logif, $home, $time_start, $tmp_batch, $MP3path, $text, $greet, $time_start_total;
	
	$start_create_tts = microtime(true);
	
	if (isset($_GET['greet']) or ($greet == 1))  {
		$Stunden = intval(strftime("%H"));
		$TL = LOAD_T2S_TEXT();
		switch ($Stunden) {
			# Gruß von 04:00 bis 10:00h
			case $Stunden >=4 && $Stunden <10:
				$greet = $TL['GREETINGS']['MORNING_'.mt_rand (1, 5)];
			break;
			# Gruß von 10:00 bis 17:00h
			case $Stunden >=10 && $Stunden <17:
				$greet = $TL['GREETINGS']['DAY_'.mt_rand (1, 5)];
			break;
			# Gruß von 17:00 bis 22:00h
			case $Stunden >=17 && $Stunden <22:
				$greet = $TL['GREETINGS']['EVENING_'.mt_rand (1, 5)];
			break;
			# Gruß nach 22:00h
			case $Stunden >=22:
				$greet = $TL['GREETINGS']['NIGHT_'.mt_rand (1, 5)];
			break;
			default:
				$greet = "";
			break;
		}
	} else {
		$greet = "";
	}
	$messageid = !empty($_GET['file']) ? $_GET['file'] : '0';
	isset($_GET['text']) ? $text = $_GET['text'] : $text;
	
	#echo 'CREATE_TTS: '.$text.'<br>';
	
	if(isset($_GET['weather']) or ($text == "weather")) {
		// calls the weather-to-speech Function
		include_once("addon/weather-to-speech.php");
		$textstring = substr(w2s(), 0, 500);
		LOGGING("tts.php: weather-to-speech plugin has been called", 7);
		} 
	elseif (isset($_GET['clock']) or ($text == "clock")) {
		// calls the clock-to-speech Function
		include_once("addon/clock-to-speech.php");
		$textstring = c2s();
		LOGGING("tts.php: clock-to-speech plugin has been called", 7);
		}
	elseif (isset($_GET['pollen']) or ($text == "pollen")) {
		// calls the pollen-to-speech Function
		include_once("addon/pollen-to-speach.php");
		$textstring = substr(p2s(), 0, 500);
		LOGGING("tts.php: pollen-to-speech plugin has been called", 7);
		}
	elseif (isset($_GET['warning']) or ($text == "warning")) {
		// calls the weather warning-to-speech Function
		include_once("addon/weather-warning-to-speech.php");
		$textstring = substr(ww2s(), 0, 500);
		LOGGING("tts.php: weather warning-to-speech plugin has been called", 7);
	}
	elseif (isset($_GET['distance']) or ($text == "distance")) {
		// calls the time-to-destination-speech Function
		include_once("addon/time-to-destination-speech.php");
		$textstring = substr(tt2t(), 0, 500);
		LOGGING("tts.php: time-to-distance speech plugin has been called", 7);
		}
	elseif (isset($_GET['witz']) or ($text == "witz")) {
		// calls the weather warning-to-speech Function
		include_once("addon/gimmicks.php");
		$textstring = substr(GetWitz(), 0, 1000);
		LOGGING("tts.php: Joke plugin has been called", 7);
		}
	elseif (isset($_GET['abfall']) or ($text == "abfall")) {
		// calls the wastecalendar-to-speech Function
		include_once("addon/waste-calendar-to-speech.php");
		$textstring = substr(muellkalender(), 0, 500);
		LOGGING("tts.php: waste calendar-to-speech plugin has been called", 7);
		}
	elseif (isset($_GET['calendar']) or ($text == "calendar")) {
		// calls the calendar-to-speech Function
		include_once("addon/waste-calendar-to-speech.php");
		$textstring = substr(calendar(), 0, 500);
		LOGGING("tts.php: calendar-to-speech plugin has been called", 7);
		}
	elseif ((empty($messageid)) && (!isset($_GET['text'])) and (isset($_GET['playbatch']))) {
		LOGGING("tts.php: No text has been entered", 3);
		exit();
		}
	elseif (!empty($messageid)) { # && ($rawtext != '')) {
		// takes the messageid
		$messageid = $_GET['file'];
		if (file_exists($config['SYSTEM']['mp3path']."/".$messageid.".mp3") === true)  {
			LOGGING("tts.php: File '".$messageid."' has been entered", 7);
		} else {
			LOGGING("tts.php: The corrosponding file '".$messageid.".mp3' does not exist or could not be played. Please check your directory or syntax!", 3);
			exit;
		}	
		}
	elseif ((empty($messageid)) && ($text <> '')) {
		// prepares the T2S message
		if (empty($greet))  {
			$textstring = $text;
		} else {
			$textstring = $greet.". ".$text;
		LOGGING("tts.php: Textstring has been entered", 7);		
		}	
	}
	
	// Get md5 of full text
	$textstring = trim($textstring);
	$fullmessageid = md5($textstring);
	LOGGING("tts.php: fullmessageid: $fullmessageid textstring: $textstring", 7);
	
	// if full text is cached, directly return the md5
	if(file_exists($config['SYSTEM']['ttspath']."/".$fullmessageid.".mp3") && empty($_GET['nocache'])) {
		LOGGING("tts.php: File already there, grabbed from cache: $textstring ", 6);
		#LOGINF("Processing time just of create_tts() tooks: " . (microtime(true)-$start_create_tts)*1000 . " ms");
		$messageid = $fullmessageid;
		$filename = $messageid;
		return ($fullmessageid);
	} else {
		LOGGING("tts.php: Processing time of creating MP3 file tooks: " . (microtime(true)-$start_create_tts)*1000 . " ms", 6);
		
	}
	
	if (!empty($_GET['nocache'])) {
		LOGGING("tts.php: Overriding cache because 'nocache' parameter was given", 6);
	}
	
	// The original text is set in a one-element array as default
	$textstrings = array ( $textstring );
	
	// encrypt MP3 file as MD5 Hash
	#echo 'messageid: '.$messageid.'<br>';
	#echo 'textstring: '.$textstring.'<br>';
	#echo 'filename: '.$filename.'<br>';
	
	// calls the various T2S engines depending on config)
	if (($messageid == '0') && ($textstring != '')) {
		if ($config['TTS']['t2s_engine'] == 1001) {
			include_once("voice_engines/VoiceRSS.php");
			LOGGING("tts.php: VoiceRSS has been successful selected", 7);		
		}
		if ($config['TTS']['t2s_engine'] == 3001) {
			include_once("voice_engines/MAC_OSX.php");
			LOGGING("tts.php: /MAC_OSX has been successful selected", 7);		
		}
		if ($config['TTS']['t2s_engine'] == 6001) {
			include_once("voice_engines/ResponsiveVoice.php");
			LOGGING("tts.php: ResponsiveVoice has been successful selected", 7);		
		}
		if ($config['TTS']['t2s_engine'] == 7001) {
			include_once("voice_engines/GoogleCloud.php");
			LOGGING("tts.php: Google Cloud has been successful selected", 7);		
		}
		if ($config['TTS']['t2s_engine'] == 5001) {
			include_once("voice_engines/Pico_tts.php");
			LOGGING("tts.php: Pico has been successful selected", 7);		
		}
		if ($config['TTS']['t2s_engine'] == 4001) {
			include_once("voice_engines/Polly.php");
			LOGGING("tts.php: AWS Polly has been successful selected", 7);		
		}
		if ($config['TTS']['t2s_engine'] == 9001) {
			include_once("voice_engines/MS_Azure.php");
			LOGGING("tts.php: MS Azure has been successful selected", 7);		
		}
		
		// Christians sentence splitter
		// The splitter splits up by sentence and fills the $textstrings array
		
		// The sentence recognition tendends to false-positives with abbreviations
		if(!empty($config['MP3']['splitsentences']) && is_enabled($config['MP3']['splitsentences'])) {
			LOGGING("tts.php: Splitting sentences", 6);
			$textstring = trim($textstring); // . ' ';
			$textstrings = array ( );
			$tempstrings = preg_split( '/(?<!\.\.\.)(?<!Dr\.)(?<=[.?!]|\.\)|\.")\s+(?=[a-zA-Z"\(])/i', $textstring, -1);
			
			// Handle corner cases
			$merge = FALSE;
			foreach($tempstrings as $key => $text) {
				$dont_push = FALSE;
				if($merge == TRUE) {
					$textstrings[count($textstrings)-1] .= " " . $text;
					$dont_push = TRUE;
					$merge = FALSE;
				}
				
				// get last char
				$last_char = substr($text, -1, 1);
				//echo "Last char: '$last_char'<br>";
				
				if($last_char == ".") {
					// Get last word
					$last_word_start = strrpos($text, ' '); // +1 so we don't include the space in our result
					$last_word = substr($text, $last_word_start); 
					// echo "Last word: '$last_word'<br>\n";
					
					// Handle: 21. Oktober
					if(is_numeric($last_word)) { 
						$merge = TRUE; 
						LOGDEB("Last word is numeric - merge"); 
					}
				}
				if (!$dont_push) array_push($textstrings, " " . $text);
			}
		}
		
		// Loop the T2S request 
		$filenames = array ( );
		$messageids = array ( );
		foreach($textstrings as $text) {
			$text = trim($text);
			if(empty($text)) continue;
			// echo "'$text' <br>\n";
			$messageid  = md5($text);
			$filename = $messageid;
			$resultmp3 = $config['SYSTEM']['ttspath']."/".$filename.".mp3";
			LOGGING("tts.php: Expected filename: $resultmp3", 7);
			if(file_exists($resultmp3) && empty($_GET['nocache'])) {
				LOGGING("tts.php: Text in cache: $text", 6);
				#next;
			}
			LOGGING("tts.php: T2S will be called with '$text'", 7);
				
			t2s($messageid, $config['SYSTEM']['ttspath'], $text, $filename);
			if(!file_exists($resultmp3)) {
				LOGGING("tts.php: File $filename.mp3 was not created (Text: '$text' Path: $resultmp3)", 3);
				exit;
			}
			//require_once("bin/getid3/getid3.php");
			//$getID3 = new getID3;
			//write_MP3_IDTag($text);
						
			array_push($filenames, $resultmp3);
			array_push($messageids, $messageid);
		}
		// In the case we have splitted the text, we have to merge the result
		if(count($textstrings)>1) {
			$messageid = $fullmessageid;
			$filename = $fullmessageid;
			LOGGING ("More than one sentence: Merging mp3's", 6);
			$mergecommand = "sox " . implode(" ", $filenames) . " " . $config['SYSTEM']['ttspath']."/".$filename.".mp3";
			LOGGING ("Mergecommand: '$mergecommand'", 7);
			$output = shell_exec($mergecommand);
			LOGGING (($output), 7);
			if(!file_exists($config['SYSTEM']['ttspath']."/".$filename.".mp3")) {
				LOGGING ("Merged MP3 file $fullmessageid.mp3 could not be found", 2);
				LOGGING("tts.php: Processing time of create_tts() (merging) tooks: " . (microtime(true)-$start_create_tts)*1000 . " ms", 6);
				$messageid = null;
				$filename = null;
				return;
			} else {
				//write_MP3_IDTag($textstring);
				LOGGING ("Created merged file $filename.mp3", 7);
			}
			// The $messageid is set to the $fullmessageid from the top 
		}
	}
	return $messageid;
}




/**
/* Funktion : jsonfile --> Erstellt ein JSON Return mit den notwendigen Infos
/* @param: 	leer
/*
/* @return: JSON für weitere Verwendung
/**/	

function json($filename)  {
	global $volume, $plugindata, $oldlog, $config, $data, $log, $MP3path, $interfacefolder, $logif, $messageid, $lbpplugindir, $notice, $level, $time_start_total, $filename, $infopath, $myFolder, $fullfilename, $config, $ttsinfopath, $filepath, $ttspath, $myIP, $plugindatapath, $lbhomedir, $files, $psubfolder, $hostname, $fullfilename, $text, $textstring, $duration;
	
	$ttspath = $config['SYSTEM']['ttspath'];
			
	// ** get details of MP3 **
	// https://github.com/JamesHeinrich/getID3/archive/master.zip
	require_once("bin/getid3/getid3.php");
    $MP3filename = $ttspath."/".$messageid.".mp3";
	$getID3 = new getID3;
    $file = $getID3->analyze($MP3filename);
	# success = 1 (everything OK), success = 2 (Warning), success = 3 (failed), 
	if (isset($file['error'])) {
		LOGGING("tts.php: Reading of MP3 Info failed by '".$file['error'][0]."'", 4);
		$duration = 0;
		$bitrate = 0;
		$sample_rate = 0;
		$notice = $file['error'][0];
		$success = 3;
		copybadfile($MP3filename);
	} else {
		if ($data['message'] === null)  {
			$notice = "";
			$success = 3;
		} else {
			$notice = $data['message'];
			LOGGING($data['message'], 3);
			$success = 3;
			#copybadfile($filename);
		}
		if (file_exists($MP3filename)) {
			$success = 1;
		} else {
			$notice = "The file $filename does not exist";
			LOGGING("tts.php: The file $filename does not exist", 3);
			$success = 3;
		}		
		if (isset($file['playtime_seconds'])) {
            $duration = round($file['playtime_seconds'] * 1000, 0);
        } else {
			$duration = 0;
		}
		if (isset($file['bitrate'])) {
            $bitrate = $file['bitrate'];
        } else {
			$bitrate = 0;
		}
		if (isset($file['mpeg']['audio']['sample_rate'])) {
            $sample_rate = $file['mpeg']['audio']['sample_rate'];
        } else {
			$sample_rate = 0;
		}
	}
	#write_MP3_IDTag();
	// ** End MP3 details **
	LOGGING("tts.php: filename of MP3 file: ".$filename, 7);
	$localip = LBSystem::get_localip();
	$jsonfilename = $filename.".json";
	$jsonlogfile = $filename."_log.json";
	$files = array(['DETAILS']);
	$files = array(
				'fullttspath' => $config['SYSTEM']['ttspath']."/".$filename.".mp3",
				'path' => $config['SYSTEM']['ttspath']."/",
				'fullcifsinterface' => '&#92;&#92;' . $localip .'&#92;plugindata&#92;'.$lbpplugindir.'&#92;interfacedownload&#92;'.$filename.'.mp3',
				'cifsinterface' => "&#92;&#92;" . $localip ."&#92;plugindata&#92;".$lbpplugindir."&#92;interfacedownload&#92;",
				'fullhttpinterface' => "http://" . $localip . ":80/plugins/".$lbpplugindir."/interfacedownload/".$filename.".mp3",
				'httpinterface' => "http://" . $localip . ":80/plugins/".$lbpplugindir."/interfacedownload/",
				'mp3filenameMD5' => $filename,
				'jsonfilenameMD5' => $jsonfilename,
				'jsonlogfileMD5' => $jsonlogfile,
				'durationms' => $duration,
				'bitrate' => $bitrate,
				'samplerate' => $sample_rate,
				'text' => $textstring,
				'warning' => $notice,
				'success' => $success
			);
	# save files
	$toBeSaved = $myFolder."/interfacedownload/".$jsonfilename;
	$toBeSavedlog = $myFolder."/interfacedownload/".$jsonlogfile;
	LOGGING("tts.php: T2S Interface: JSON has been successfully responded to Request",5);
	file_put_contents($toBeSaved, json_encode($files));
	file_put_contents($toBeSavedlog, json_encode($logif));
	# Prepare output for echo
	$final = array(
				'fullttspath' => $config['SYSTEM']['ttspath']."/".$filename.".mp3",
				'path' => $config['SYSTEM']['ttspath']."/",
				'fullcifsinterface' => '&#92;&#92;' . $localip .'&#92;plugindata&#92;'.$lbpplugindir.'&#92;interfacedownload&#92;'.$filename.'.mp3',
				'cifsinterface' => "&#92;&#92;" . $localip ."&#92;plugindata&#92;".$lbpplugindir."&#92;interfacedownload&#92;",
				'fullhttpinterface' => "http://" . $localip . ":80/plugins/".$lbpplugindir."/interfacedownload/".$filename.".mp3",
				'httpinterface' => "http://" . $localip . ":80/plugins/".$lbpplugindir."/interfacedownload/",
				'mp3filenameMD5' => $filename,
				'jsonfilenameMD5' => $jsonfilename,
				'jsonlogfileMD5' => $jsonlogfile,
				'durationms' => $duration,
				'bitrate' => $bitrate,
				'samplerate' => $sample_rate,
				'text' => $textstring,
				'warning' => $notice,
				'success' => $success,
				#'warning' => "Test",
				#'success' => "2",
				'logging' => $logif
			);
	#print_r($logif);
	$final = json_encode($final);
	header('Content-Type: application/json');
	echo $final;
	LOGGING("tts.php: Set Loglevel back to ".$oldlog, 6);
	$log->loglevel($plugindata['PLUGINDB_LOGLEVEL']);
	return $final;	
}



/** 
/*
/* Funktion : copybadfile --> rename corrupted MP3 file
/* @param: 	leer
/*
/* @return: Message
/**/
function copybadfile($filename)  {
	
	global $config, $log;
	
	$heute = date("Y-m-d"); 
	$time = date("His"); 
	if (file_exists($config['SYSTEM']['ttspath']."/".$filename.".mp3"))   {
		rename($config['SYSTEM']['ttspath']."/".$filename.".mp3", $config['SYSTEM']['ttspath']."/".$filename."_FAILED_T2S_".$heute."_".$time.".mp3");
		LOGGING("tts.php: Something went wrong :-( the message could not be saved. The corrupted file has been renamed to: ".$config['SYSTEM']['ttspath']."/".$filename."_FAILED_T2S_".$heute."_".$time.".mp3", 3);	
		LOGGING("tts.php: Please try again :-) if no success at all please check your Plugin settings, Internet connection as well as T2S Provider, try reboot LoxBerry", 5);
	} else {
		LOGGING("tts.php: Something went wrong :-( the file could not be created. Please check your settings and T2S Provider", 3);
	}
	#$filename = "t2s_not_available";
	#copy($config['SYSTEM']['mp3path']."/t2s_not_available.mp3", $config['SYSTEM']['ttspath']."/t2s_not_available.mp3");
	return;
}



/**   NOT ACTIVE ANYMORE
/*
/* Funktion : write_MP3_IDTag --> write MP3-ID Tags to file
/* @param: 	leer
/*
/* @return: Message
/**/	

function write_MP3_IDTag($income_text) {
	
	global $config, $data, $log, $textstring, $filename, $TextEncoding, $text;
	
	require_once("bin/getid3/getid3.php");
	// Initialize getID3 engine
	$getID3 = new getID3;
	$getID3->setOption(array('encoding' => $TextEncoding));
	 
	require_once('bin/getid3/write.php');	
	// Initialize getID3 tag-writing module
	$tagwriter = new getid3_writetags;
	$tagwriter->filename = $config['SYSTEM']['ttspath']."/".$filename.".mp3";
	$tagwriter->tagformats = array('id3v2.3');

	// set various options (optional)
	$tagwriter->overwrite_tags    = true;  // if true will erase existing tag data and write only passed data; if false will merge passed data with existing tag data (experimental)
	$tagwriter->remove_other_tags = false; // if true removes other tag formats (e.g. ID3v1, ID3v2, APE, Lyrics3, etc) that may be present in the file and only write the specified tag format(s). If false leaves any unspecified tag formats as-is.
	$tagwriter->tag_encoding      = $TextEncoding;
	$tagwriter->remove_other_tags = true;

	// populate data array
	$TagData = array(
					'title'                  => array("$income_text"),
					'artist'                 => array('text2speech'),
					'album'                  => array(''),
					'year'                   => array(date("Y")),
					'genre'                  => array('text'),
					'comment'                => array('generated by LoxBerry Plugin'),
					'track'                  => array(''),
				);
	
	$tagwriter->tag_data = $TagData;
	
	// write tags
	if ($tagwriter->WriteTags()) {
	LOGDEB("Successfully wrote id3v2.3 tags");
		if (!empty($tagwriter->warnings)) {
			LOGWARN('There were some warnings:<br>'.implode($tagwriter->warnings));
		}
	} else {
		LOGERR('Failed to write tags!<br>'.implode($tagwriter->errors));
	}
	return ($TagData);
}	



?>
