<?php
##############################################################################################################################
# tts_functions.php - Funktionen für Text2Speech
# Version: 2.0.0 Optimized
##############################################################################################################################


global $config, $t2s_param, $lbpdatadir;

// =================== create_tts ===================
function create_tts() {
	
    global $config, $t2s_param;

    $ttspath  = rtrim($config['SYSTEM']['ttspath'], '/');
    $mp3path  = rtrim($config['SYSTEM']['mp3path'], '/');

    // ------------------ 1) Fertige MP3-Datei aus URL ------------------
    if (!empty($_GET['file'])) {
        $filename = basename($_GET['file']); // Schutz vor Pfadangriffen

        // Nur .mp3 anhängen, wenn es noch nicht vorhanden ist
        if (substr($filename, -4) !== '.mp3') {
            $filename .= '.mp3';
        }

        $fullpath = "$mp3path/$filename";

        if (file_exists($fullpath)) {
            LOGDEB("tts_functions.php: Serving existing MP3 file: $fullpath");
            return $fullpath;
        } else {
            LOGERR("tts_functions.php: Requested file not found: $fullpath");
            return false;
        }
    }

    // ------------------ 2) Text in TTS umwandeln ------------------
    if (!empty($t2s_param['text'])) {
        $text = trim($t2s_param['text']);
        $messageid = md5($text);
        $mp3file = "$ttspath/$messageid.mp3";

        // Cache prüfen
        if (file_exists($mp3file) && !isset($_GET['nocache'])) {
            LOGDEB("tts_functions.php: Found cached MP3: $mp3file");
            return $mp3file;
        } else {
			LOGDEB("tts_functions.php: 'nocache' Parameter received. Forced to re-create: '$mp3file'");
		}

        // Engine-Datei laden
        $engine_file = get_engine_file($t2s_param['t2sengine']);
        if (!$engine_file) {
            LOGERR("tts_functions.php: Invalid TTS engine: " . $t2s_param['t2sengine']);
            return false;
        }

        include_once($engine_file);

        LOGINF("tts_functions.php: Generating new MP3 with engine $engine_file for text: '$text'");
        t2s($t2s_param);

        if (!file_exists($mp3file)) {
            LOGERR("tts_functions.php: MP3 could not be created: $mp3file");
            return false;
        }

        return $mp3file;
    }

    // ------------------ 3) Weder file noch text angegeben ------------------
    LOGERR("tts_functions.php: Neither 'file' nor 'text' provided in request");
    return false;
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
