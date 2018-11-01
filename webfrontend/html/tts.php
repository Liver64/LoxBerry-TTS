<?php

##############################################################################################################################
#
# Version: 	1.0.6
# Datum: 	30.10.2018
# veröffentlicht in: https://github.com/Liver64/LoxBerry-TTS/releases
# 
##############################################################################################################################

// ToDo
//
// syntax wizard

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
$infopath = "interface";											// path to info for ext. Prog
$Home = getcwd();												// get Plugin Pfad
$fullfilename = "t2s_source.json";								// filename to pass info back to ext. Prog#.
$logging_config = "interface.cfg";								// fixed filename to pass log entries to ext. Prog.
#$interfacefolder = "interface";
$ttsfolder = "tts";
$mp3folder = "mp3";
$lbphtmldir = LBPHTMLDIR;


#echo '<PRE>'; 

global $text, $messageid, $LOGGING, $textstring, $level, $voice, $config, $volume, $time_start, $filename, $MP3path, $mp3, $text_ext, $logging_config, $myConfigFile, $lbhomedir, $params, $logging_config, $jsonfile;

$level = LBSystem::pluginloglevel();
	
$params = [	"name" => "Text2speech",
			"filename" => "$lbplogdir/text2speech.log",
			"append" => 1,
			"addtime" => 1,
			];
LBLog::newLog($params);	

// used for single logging
$plugindata = LBSystem::plugindata();

LOGSTART("PHP started");

$time_start_total = microtime(true);

#-- Start Preparation ------------------------------------------------------------------
	LOGGING("called syntax: ".$myIP."".urldecode($syntax),5);
	// Parsen der Konfigurationsdatei
	if (!file_exists($myConfigFolder.'/tts_all.cfg')) {
		LOGGING('The file tts_all.cfg could not be opened, please try again!', 3);
		exit;
	} else {
		$config = parse_ini_file($myConfigFolder.'/tts_all.cfg', TRUE);
		LOGGING("T2S config has been loaded", 7);
	}
	#print_r($config);
	create_symlinks();
	LOGGING("Config has been successfull loaded",6);
		
	# wählt Sprachdatei für hinterlegte Texte der Add-on's
	$t2s_langfile = "t2s-text_".substr($config['TTS']['messageLang'],0,2).".ini";				// language file for text-speech
	LOGGING("All variables has been collected",6);
	$soundcard = $config['SYSTEM']['card'];
		# prüfen ob Volume in syntax, wenn nicht Std. von Config
		if (!isset($_GET["volume"])) {
			$volume = $config['TTS']['volume'];
			LOGGING("Standardvolume from Config beeen adopted",7);
		} else { 
			if(isset($_GET['volume']) && is_numeric($_GET['volume']) && $_GET['volume'] >= 0 && $_GET['volume'] <= 500) {
				$volume = $_GET["volume"];
				LOGGING("Volume from Syntax beeen adopted",7);
			} else {
				LOGGING("The entered volume is out of range. Please use 0 to 500",4);
				$volume = $config['TTS']['volume'];
				LOGGING("As backup the Standardvolume from Config beeen adopted. Please correct your syntax",4);
			}
		}
		# Volume prozentual für sox (1=100%)
		$volume = $volume / 100;
	#$multilog = get_interface_config();
	#print_r($multilog);
	
	

#-- End Preparation ---------------------------------------------------------------------

	global $soundcard, $config, $text, $time_start_total, $decoded, $greet, $textstring, $filename, $myConfigFile;
	
	# Prüfen ob Request per Interface reinkommt
	$tmp_content = file_get_contents("php://input");
	if ($tmp_content == true)  {
	# *** Lese Daten von ext. Call ***
		require_once('output/interface.php');
		LOGGING("T2S Interface ** POST request has been received and will be processed!", 6);
		process_post_request();
		# Deklaration der variablen
		$text = $decoded['text'];
		$greet = $decoded['greet'];
	} elseif (isset($_GET['json']))  {
	 # *** Lese Daten von URL ***
		require_once('output/interface.php');
		LOGGING("T2S Interface ** JSON is set and will be processed!", 6);
		# Deklaration der variablen
		$text = $_GET['text'];
		isset($_GET['greet']) ?	$greet = $_GET['greet'] : $greet = " ";
	} else {
		create_tts();
	}
	# prüfe of TTS Anbieter und ggf. Stimme gewählt wurde
	if ((empty($config['TTS']['t2s_engine'])) or (empty($config['TTS']['messageLang'])))  {
		LOGGING("There is no T2S engine/language selected in Plugin config. Please select before using T2S functionality.", 3);
	exit();
	}
	# Prüfung ob syntax korrekt eingeben wurde.
	if ((!isset($_GET['text'])) && (!isset($_GET['file'])) && 
		(!isset($_GET['weather'])) && (!isset($_GET['abfall'])) &&
		(!isset($_GET['witz'])) && (!isset($_GET['pollen'])) && 
		(!isset($_GET['warning'])) && (!isset($_GET['bauernregel'])) && 
		(!isset($_GET['distance'])) && (!isset($_GET['clock'])) &&
		(!isset($_GET['calendar']))&& ($text == ' ') && (!isset($data))) {
		LOGGING("Something went wrong. Please try again and check your syntax. (check Wiki)", 3);
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
		case '012':			// USB Soundcard  
			require_once('output/usb.php');
			shell_exec("export AUDIODEV=hw:1,1");
			usb();
		break;
		default;			// Soundcard bcm2835
			require_once('output/alsa.php');
			shell_exec("export AUDIODRIVER=alsa");
			$output = shell_exec("export AUDIODEV=hw:0,0");
		break;
	}
	if ($tmp_content == true || isset($_GET['json']))  {
		create_tts();
	}
	LOGGING("Processing time of the complete T2S request tooks: " . round((microtime(true)-$time_start_total), 2) . " Sek.", 6);
	json($filename);	
	LOGEND("PHP finished"); 
exit;



 
/**
* Function : create_tts --> creates an MP3 File based on Text Input
*
* @param: 	Text of Messasge ID
* @return: 	MP3 File
**/		

function create_tts() {
	
	global $config, $filename, $messageid, $textstring, $home, $time_start, $tmp_batch, $MP3path, $text, $greet, $time_start_total;
	
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
		LOGGING("weather-to-speech plugin has been called", 7);
		} 
	elseif (isset($_GET['clock']) or ($text == "clock")) {
		// calls the clock-to-speech Function
		include_once("addon/clock-to-speech.php");
		$textstring = c2s();
		LOGGING("clock-to-speech plugin has been called", 7);
		}
	elseif (isset($_GET['pollen']) or ($text == "pollen")) {
		// calls the pollen-to-speech Function
		include_once("addon/pollen-to-speach.php");
		$textstring = substr(p2s(), 0, 500);
		LOGGING("pollen-to-speech plugin has been called", 7);
		}
	elseif (isset($_GET['warning']) or ($text == "warning")) {
		// calls the weather warning-to-speech Function
		include_once("addon/weather-warning-to-speech.php");
		$textstring = substr(ww2s(), 0, 500);
		LOGGING("weather warning-to-speech plugin has been called", 7);
	}
	elseif (isset($_GET['distance']) or ($text == "distance")) {
		// calls the time-to-destination-speech Function
		include_once("addon/time-to-destination-speech.php");
		$textstring = substr(tt2t(), 0, 500);
		LOGGING("time-to-distance speech plugin has been called", 7);
		}
	elseif (isset($_GET['witz']) or ($text == "witz")) {
		// calls the weather warning-to-speech Function
		include_once("addon/gimmicks.php");
		$textstring = substr(GetWitz(), 0, 1000);
		LOGGING("Joke plugin has been called", 7);
		}
	elseif (isset($_GET['bauernregel']) or ($text == "bauernregel")) {
		// calls the weather warning-to-speech Function
		include_once("addon/gimmicks.php");
		$textstring = substr(GetTodayBauernregel(), 0, 500);
		LOGGING("Bauernregeln plugin has been called", 7);
		}
	elseif (isset($_GET['abfall']) or ($text == "abfall")) {
		// calls the wastecalendar-to-speech Function
		include_once("addon/waste-calendar-to-speech.php");
		$textstring = substr(muellkalender(), 0, 500);
		LOGGING("waste calendar-to-speech plugin has been called", 7);
		}
	elseif (isset($_GET['calendar']) or ($text == "calendar")) {
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
		if (file_exists($config['SYSTEM']['mp3path']."/".$messageid.".mp3") === true)  {
			LOGGING("File '".$messageid."' has been entered", 7);
		} else {
			LOGGING("The corrosponding file '".$messageid.".mp3' does not exist or could not be played. Please check your directory or syntax!", 3);
			exit;
		}	
		}
	elseif ((empty($messageid)) && ($text <> '')) {
		// prepares the T2S message
		if (empty($greet))  {
			$textstring = $text;
		} else {
			$textstring = $greet.". ".$text;
		LOGGING("Textstring has been entered", 7);		
		}	
	}
	
	// Get md5 of full text
	$textstring = trim($textstring);
	$fullmessageid = md5($textstring);
	LOGGING("fullmessageid: $fullmessageid textstring: $textstring", 7);
	
	// if full text is cached, directly return the md5
	if(file_exists($config['SYSTEM']['ttspath']."/".$fullmessageid.".mp3") && empty($_GET['nocache'])) {
		LOGGING("Grabbed from cache: '$textstring' ", 6);
		#LOGINF("Processing time just of create_tts() tooks: " . (microtime(true)-$start_create_tts)*1000 . " ms");
		$messageid = $fullmessageid;
		$filename = $messageid;
		return ($fullmessageid);
	} else {
		LOGGING("Processing time of create_tts() tooks: " . (microtime(true)-$start_create_tts)*1000 . " ms", 6);
	}
	
	if (!empty($_GET['nocache'])) {
		LOGGING("Overriding cache because 'nocache' parameter was given", 6);
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
		
		// Christians sentence splitter
		// The splitter splits up by sentence and fills the $textstrings array
		
		// The sentence recognition tendends to false-positives with abbreviations
		if(!empty($config['MP3']['splitsentences']) && is_enabled($config['MP3']['splitsentences'])) {
			LOGGING("Splitting sentences", 6);
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
					if(is_numeric($last_word)) { $merge = TRUE; LOGDEB("Last word is numeric - merge"); }
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
			LOGGING("Expected filename: $resultmp3", 7);
			if(file_exists($resultmp3) && empty($_GET['nocache'])) {
				LOGGING("Text in cache: $text", 6);
				next;
			}
			LOGGING("T2S will be called with '$text'", 7);
			
			t2s($messageid, $config['SYSTEM']['ttspath'], $text, $filename);
			if(!file_exists($resultmp3)) {
				LOGGING("File $filename.mp3 was not created (Text: '$text' Path: $resultmp3)", 3);
			}
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
				LOGGING("Processing time of create_tts() (merging) tooks: " . (microtime(true)-$start_create_tts)*1000 . " ms", 6);
				$messageid = null;
				$filename = null;
				return;
			} else {
				LOGGING ("Created merged file $filename.mp3", 7);
			}
			// The $messageid is set to the $fullmessageid from the top 
		}
	}
	return $messageid;
}




/**
/* Funktion : jsonfile --> Erstellt ein JSON file mit den notwenigen Infos
/* @param: 	leer
/*
/* @return: JSON file für weitere Verwendung
/**/	

function json($filename)  {
	global $volume, $config, $MP3path, $messageid, $warning, $level, $time_start_total, $filename, $infopath, $myFolder, $fullfilename, $config, $ttsinfopath, $filepath, $ttspath, $myIP, $plugindatapath, $lbhomedir, $files, $psubfolder, $hostname, $fullfilename, $text, $textstring, $duration;
	
	$ttspath = $config['SYSTEM']['ttspath'];
	#$filenamebatch = $config['SYSTEM']['interfacepath']."/".$fullfilename;
	#$filepath = $config['SYSTEM']['mp3path'];
	#$ttsinfopath = $config['SYSTEM']['interfacepath']."/";
		
	// ** get details of MP3 **
	// https://github.com/JamesHeinrich/getID3/archive/master.zip
	require_once("bin/getid3/getid3.php");
    $MP3filename = $ttspath."/".$messageid.".mp3";
	$warning = "";
	
	#$duration = @round($file['playtime_seconds'] * 1000, 0);
	#$bitrate = @$file['bitrate'];
	#$sample_rate = @$file['mpeg']['audio']['sample_rate'];
	
	set_error_handler("warning_handler", E_WARNING);
	
	$getID3 = new getID3;
    $file = @$getID3->analyze($MP3filename);
	$duration = round($file['playtime_seconds'] * 1000, 0);
	$bitrate = $file['bitrate'];
	$sample_rate = $file['mpeg']['audio']['sample_rate'];
	
	restore_error_handler();
	
	// ** End MP3 details **
    	
	LOGGING("filename of MP3 file: '".$filename."'", 5);
	
	// OLD: processing of request via file
	
	# prüft ob Verzeichnis für Übergabe existiert
	#$is_there = file_exists($ttsinfopath);
	#if ($is_there === false)  {
	#	LOGGING("The interface folder seems not to be available!! System now try to create the 'share' folder", 4);
	#	mkdir($ttsinfopath);
	#	LOGGING("Folder '".$ttsinfopath."' has been succesful created.", 5);
	#} else {
	#	LOGGING("Folder '".$infopath."' to pass over audio infos is already there (".$ttsinfopath.")", 5);
	#}
	# Löschen alle vorhandenen Dateien aus dem info folder
	#chdir($ttsinfopath);
	#foreach (glob("*.*") as $file) {
	#	LOGGING("File: '".$file."' has been deleted from '".$infopath."' folder",5);
	#	#unlink($file);
	#}
	$files = array(
					'full-ttspath' => $config['SYSTEM']['ttspath']."/".$filename.".mp3",
					'path' => $config['SYSTEM']['path']."/",
					'full-cifsinterface' => $config['SYSTEM']['cifsinterface']."/".$filename.".mp3",
					'cifsinterface' => $config['SYSTEM']['cifsinterface']."/",
					'full-httpinterface' => $config['SYSTEM']['httpinterface']."/".$filename.".mp3",
					'httpinterface' => $config['SYSTEM']['httpinterface']."/",
					'mp3-filename-MD5' => $filename,
					'duration-ms' => $duration,
					'bitrate' => $bitrate,
					'sample-rate' => $sample_rate,
					'text' => $textstring,
					'warning' => $warning,
					'success' => 1
					);
	$json = json_encode($files);
	header('Content-Type: application/json');
	echo $json;
	LOGGING("JSON has been successfully responded to Requester",5);
	#LOGGING("MP3 file has been saved successful at '".$files['path']."'.", 6);
	return $files;	
}


function warning_handler($errno, $errstr) { 
	global $warning;
	
	$warning = "Even duration, sample rate or bit rate could not be determined.";
	LOGGINE("Even duration, sample rate or bit rate could not be determined.", 4);
	return $warning;
	
	}

?>
