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

    // 1) Fertige MP3 aus URL (?file=...)
    if (!empty($_GET['file'])) {
        $filename = basename($_GET['file']);
        if (substr($filename, -4) !== '.mp3') $filename .= '.mp3';
        $fullpath = "$mp3path/$filename";
        if (is_file($fullpath)) {
            LOGINF("tts_functions.php: Serving existing MP3 file: $fullpath");
            return $fullpath;
        } else {
            LOGERR("tts_functions.php: Requested file not found: $fullpath");
            return false;
        }
    }

    // 2) Text → TTS
    if (!empty($t2s_param['text'])) {
        $text = trim($t2s_param['text']);

        // bevorzugte ID (vom Aufrufer), sonst md5(text)
        $preferredId = '';
        if (!empty($t2s_param['filename'])) {
            $preferredId = preg_replace('~[^a-f0-9]~i', '', (string)$t2s_param['filename']);
        } elseif (!empty($_GET['filename'])) {
            $preferredId = preg_replace('~[^a-f0-9]~i', '', (string)$_GET['filename']);
        }
        $textHashId = md5($text);

        $messageid = $preferredId !== '' ? $preferredId : $textHashId;
        $mp3file   = "$ttspath/$messageid.mp3";
        $fallback  = "$ttspath/$textHashId.mp3"; // kann == $mp3file sein

        // nocache interpretieren (0/1, true/false, default 0)
        $nocache = 0;
        if (isset($_GET['nocache'])) {
            $raw = (string)$_GET['nocache'];
            if     ($raw === '1') $nocache = 1;
            elseif ($raw === '0' || $raw === '') $nocache = 0;
            else $nocache = filter_var($raw, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        // --- Cache-Hit und nocache != 1 → direkt liefern
        if ($nocache !== 1) {
            if (is_file($mp3file)) {
                LOGINF("tts_functions.php: Found cached MP3: $mp3file");
                return $mp3file;
            }
            if ($fallback !== $mp3file && is_file($fallback)) {
                LOGINF("tts_functions.php: Found cached MP3 (text-hash): $fallback");
                return $fallback;
            }
            // Cache-Miss → erzeugen (weil nocache=0/absent erlaubt Erzeugung bei Miss)
            LOGDEB("tts_functions.php: Cache miss (nocache=0) → creating new MP3: '$mp3file'");
        } else {
            // nocache=1 → immer neu erzeugen
            LOGINF("tts_functions.php: nocache=1 → re-create target: '$mp3file'");
        }

        // Engine laden
        $engine_file = get_engine_file($t2s_param['t2sengine']);
		if (!$engine_file) { LOGERR("tts_functions.php: Invalid TTS engine: " . $t2s_param['t2sengine']); return false; }

		return with_tts_lock($messageid, function() use ($mp3file, $fallback, $engine_file, $t2s_param, $text) {
			// während des Wartens fertig geworden?
			if (is_file($mp3file)) return $mp3file;
			if ($fallback !== $mp3file && is_file($fallback)) return $fallback;

			include_once($engine_file); // <-- nur hier
			LOGINF("tts_functions.php: Generating new MP3 with engine $engine_file for text: '$text'");
			t2s($t2s_param);

			if (is_file($mp3file)) { LOGOK("tts_functions.php: MP3 file successfully saved to $mp3file"); return $mp3file; }
			if ($fallback !== $mp3file && is_file($fallback)) { LOGDEB("tts_functions.php: MP3 created under text-hash: '$fallback' (expected '$mp3file'). Using created file."); return $fallback; }

			LOGERR("tts_functions.php: MP3 could not be created: $mp3file");
			return false;
		});

		
        t2s($t2s_param);

        // 1) bevorzugtes Ziel vorhanden?
        if (is_file($mp3file)) {
            LOGOK("tts_functions.php: MP3 file successfully saved to $mp3file");
            return $mp3file;
        }
        // 2) ggf. unter md5(text) geschrieben
        if ($fallback !== $mp3file && is_file($fallback)) {
            LOGDEB("tts_functions.php: MP3 created under text-hash: '$fallback' (expected '$mp3file'). Using created file.");
            return $fallback;
        }

        LOGERR("tts_functions.php: MP3 could not be created: $mp3file");
        return false;
    }

    // 3) weder file noch text
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



function with_tts_lock(string $messageid, callable $fn) {
    $lockfile = sys_get_temp_dir() . "/tts_" . $messageid . ".lock";
    $fh = fopen($lockfile, 'c');
    if (!$fh) { return $fn(); }           // Fallback ohne Lock

    $ok = flock($fh, LOCK_EX);            // warten bis frei
    try {
        return $fn();
    } finally {
        if ($ok) { flock($fh, LOCK_UN); }
        fclose($fh);
        // kein unlink: lock-file darf bestehen bleiben
    }
}


?>
