<?php
##############################################################################################################################
# tts_functions.php - Funktionen für Text2Speech
# Version: 2.0.1
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
        if (substr($filename, -4) !== '.mp3') {
            $filename .= '.mp3';
        }
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
            if     ($raw === '1') { $nocache = 1; }
            elseif ($raw === '0' || $raw === '') { $nocache = 0; }
            else { $nocache = filter_var($raw, FILTER_VALIDATE_BOOLEAN) ? 1 : 0; }
        }

        // Bei nocache=1 alte Dateien entfernen, damit wirklich neu erzeugt wird
        if ($nocache === 1) {
            if (is_file($mp3file))  @unlink($mp3file);
            if ($fallback !== $mp3file && is_file($fallback)) @unlink($fallback);
            LOGINF("tts_functions.php: nocache=1 → re-create target: '$mp3file'");
        } else {
            // --- Cache-Hit und nocache != 1 → direkt liefern
            if (is_file($mp3file)) {
                LOGINF("tts_functions.php: Found cached MP3: $mp3file");
                return $mp3file;
            }
            if ($fallback !== $mp3file && is_file($fallback)) {
                LOGINF("tts_functions.php: Found cached MP3 (text-hash): $fallback");
                return $fallback;
            }
            // Cache-Miss → erzeugen
            LOGDEB("tts_functions.php: Cache miss (nocache=0) → creating new MP3: '$mp3file'");
        }

        // Engine laden
        $engine_file = get_engine_file($t2s_param['t2sengine']);
        if (!$engine_file) {
            LOGERR("tts_functions.php: Invalid TTS engine: " . $t2s_param['t2sengine']);
            return false;
        }

        // Erzeugung unter Lock
        return with_tts_lock($messageid, function() use ($mp3file, $fallback, $engine_file, $t2s_param, $text, $nocache) {

            // Nur bei nocache!=1 sofort liefern; sonst Neu-Erzeugung erzwingen
            if ($nocache !== 1) {
                if (is_file($mp3file)) return $mp3file;
                if ($fallback !== $mp3file && is_file($fallback)) return $fallback;
            }

            include_once($engine_file);
            LOGINF("tts_functions.php: Generating new MP3 with engine $engine_file for text: '$text'");
            t2s($t2s_param);

            // Ziel prüfen
            $target = null;
            if (is_file($mp3file)) {
                $target = $mp3file;
            } elseif ($fallback !== $mp3file && is_file($fallback)) {
                LOGDEB("tts_functions.php: MP3 created under text-hash: '$fallback' (expected '$mp3file'). Using created file.");
                $target = $fallback;
            }

            if ($target !== null) {
                // Kurze Sanity-Checks (Größe/MP3-Header)
                $fh = @fopen($target, 'rb');
				if ($fh) {
					$head3 = fread($fh, 3); // 3 Bytes lesen
					fclose($fh);

					if ($head3 === false || strlen($head3) < 2) {
						LOGWARN("tts_functions.php: Could not read header: $target");
					} else {
						$sig3 = substr($head3, 0, 3);     // z.B. "ID3"
						$sig2 = substr($head3, 0, 2);     // z.B. 0xFF 0xFB / 0xF3 / 0xF2

						if ($sig3 !== "ID3" && !in_array($sig2, ["\xFF\xFB", "\xFF\xF3", "\xFF\xF2"], true)) {
							LOGWARN("tts_functions.php: File does not look like MP3 (header=".bin2hex($head3)."): $target");
						}
					}
				}
                LOGOK("tts_functions.php: MP3 file successfully saved to $target");
                return $target;
            }
            LOGERR("tts_functions.php: MP3 could not be created: $mp3file");
            return false;
        });
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
