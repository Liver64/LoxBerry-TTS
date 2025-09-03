<?php
##############################################################################################################################
# tts_functions.php - Funktionen für Text2Speech
# Version: 1.0.8 Optimized
##############################################################################################################################

//require_once('logging.php');

global $config, $t2s_param, $lbpdatadir;

// =================== create_tts ===================
function create_tts() {
    global $config, $t2s_param, $lbpdatadir, $log;

    $textstring = trim($t2s_param['text']);
    $fullmessageid = md5($textstring);
    $ttspath = $config['SYSTEM']['ttspath'];
    $mp3file = "$ttspath/$fullmessageid.mp3";

    // Cache prüfen
    if(file_exists($mp3file) && empty($_GET['nocache'])) {
        LOGINF("tts_functions.php: File already exists in cache: $mp3file");
        return $mp3file;
    }

    // Plugin-Spezifische Texte
    if ($textstring === "weather") {
        include_once("addon/weather-to-speech.php");
        $textstring = substr(w2s(),0,500);
    } elseif ($textstring === "clock") {
        include_once("addon/clock-to-speech.php");
        $textstring = c2s();
    } elseif ($textstring === "pollen") {
        include_once("addon/pollen-to-speach.php");
        $textstring = substr(p2s(),0,500);
    } elseif ($textstring === "warning") {
        include_once("addon/weather-warning-to-speech.php");
        $textstring = substr(ww2s(),0,500);
    } elseif ($textstring === "distance") {
        include_once("addon/time-to-destination-speech.php");
        $textstring = substr(tt2t(),0,500);
    } elseif ($textstring === "abfall") {
        include_once("addon/waste-calendar-to-speech.php");
        $textstring = substr(muellkalender(),0,500);
    } elseif ($textstring === "calendar") {
        include_once("addon/waste-calendar-to-speech.php");
        $textstring = substr(calendar(),0,500);
    }

    // Splitten falls nötig (optional)
    $textstrings = [$textstring];
	if (isset($_GET['testfile']))    {
		if (file_exists($lbpdatadir."/interfacedownload/".$_GET['filename'].".mp3"))   {
			@unlink($lbpdatadir."/interfacedownload/".$_GET['filename'].".mp3");
			LOGINF("tts.php: Previous Testfile: ".$_GET['filename'].".mp3 has been deleted");
		}
	}
    $filenames = [];
    foreach($textstrings as $text) {
        $text = trim($text);
        if(empty($text)) continue;

        $messageid = md5($text);
        $filename = "$ttspath/$messageid.mp3";

        if(!file_exists($filename) || !empty($_GET['nocache'])) {
            $engine_file = get_engine_file($t2s_param['t2sengine']);
            if(!$engine_file) {
                LOGERR("tts_functions.php: Unknown TTS engine selected");
                return false;
            }
            include_once($engine_file);
            t2s($messageid, $ttspath, $text, $filename, $t2s_param);

            if(!file_exists($filename)) {
                LOGERR("tts_functions.php: MP3 could not be created for text: $text");
                return false;
            }
        }
        array_push($filenames, $filename);
    }

    // Mehrere MP3s zusammenführen (optional)
    if(count($filenames) > 1) {
        $mergefile = "$ttspath/$fullmessageid.mp3";
        $cmd = "sox " . implode(" ", $filenames) . " $mergefile";
        shell_exec($cmd);
        if(!file_exists($mergefile)) {
            LOGERR("tts_functions.php: Merged MP3 file could not be created");
            return false;
        }
        return $mergefile;
    }

    return $filenames[0];
}

// =================== get_engine_file ===================
function get_engine_file($engineid) {
    switch($engineid) {
        case 1001: return "voice_engines/VoiceRSS.php";
        case 3001: return "voice_engines/ElevenLabs.php";
        case 6001: return "voice_engines/ResponsiveVoice.php";
        case 7001: return "voice_engines/GoogleCloud.php";
        case 5001: return "voice_engines/Piper.php";
        case 4001: return "voice_engines/Polly.php";
        case 9001: return "voice_engines/MS_Azure.php";
        default: return false;
    }
}
?>
