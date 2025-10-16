<?php
/*****************************************************************************************
 * output/alsa.php – ALSA playback for LoxBerry Text2Speech
 * Minimal-changes version with:
 *  - explicit mpg123 device (-o alsa -a <device>)
 *  - hw: → plughw: mapping for automatic format conversion
 *  - fast WAV→MP3 conversion (only if needed; mono/22.05kHz, VBR q=6)
 *  - jingle selection: ?jingle=... → else config MP3.file_gong (extension normalized)
 *****************************************************************************************/

/* ---------- Helper: read WAV header (channels / sample rate) ---------- */
if (!function_exists('wav_info')) {
    function wav_info(string $file): ?array {
        $fh = @fopen($file, 'rb');
        if (!$fh) return null;
        $hdr = fread($fh, 44);
        fclose($fh);
        if (strlen($hdr) < 36) return null;

        $channels = unpack('v', substr($hdr, 22, 2))[1] ?? null; // UInt16 LE
        $sampler  = unpack('V', substr($hdr, 24, 4))[1] ?? null; // UInt32 LE
        $bits     = unpack('v', substr($hdr, 34, 2))[1] ?? null; // UInt16 LE
        if (!$channels || !$sampler) return null;
        return ['channels' => (int)$channels, 'sample_rate' => (int)$sampler, 'bits' => (int)$bits];
    }
}

/* ---------- Main ALSA output function ---------- */
function alsa_ob($finalfile) {

    global $volume, $config, $myteccard;

    // Resolve output device (prefer plugin device), map hw: -> plughw: for format conversion
    $audioDev = $myteccard ?: 'hw:0,0';
    $audioDev = preg_replace('/^hw:/', 'plughw:', $audioDev);
    LOGDEB("output/alsa.php: Using audio device: " . $audioDev);

    $mp3path = rtrim($config['SYSTEM']['mp3path'] ?? '', '/');
    $ttspath = rtrim($config['SYSTEM']['ttspath'] ?? '', '/');

    // Configure Task Spooler (non-blocking queue)
    putenv("TS_SOCKET=/dev/shm/ttsplugin.sock");
    putenv("TS_MAXFINISHED=10");
    putenv("TS_MAXCONN=10");
    putenv("TS_MAILTO=");

    /**
     * Play an MP3 file via mpg123 (non-blocking, queued by tsp)
     */
    $play = function($file, $label = 'TTS') use ($volume, $audioDev) {
        if (!is_file($file)) {
            LOGERR("output/alsa.php: File not found: $file");
            return;
        }
        $scaledVolume = max(0, (int)(32768 * $volume)); // 0..32768

        $cmd = sprintf(
            "tsp -n mpg123 -q -o alsa -a %s -f %d -- %s >/dev/null 2>&1 &",
            escapeshellarg($audioDev),     // e.g., plughw:CARD=USB,DEV=0
            $scaledVolume,
            escapeshellarg($file)
        );
        LOGDEB("output/alsa.php: Executing mpg123 command [$label]: $cmd");
        shell_exec($cmd);
        LOGINF("output/alsa.php: Started playing [$label]");
    };

    /**
     * Fast WAV -> MP3 conversion (only if needed)
     * - Mono (-ac 1), 22.05 kHz (-ar 22050), VBR q=6 (libmp3lame)
     * - Reads WAV header to avoid unnecessary resampling
     */
    $convertToMp3 = function(string $ttsFile) use ($ttspath) {
        if (strtolower(pathinfo($ttsFile, PATHINFO_EXTENSION)) === 'mp3') {
            LOGDEB("output/alsa.php: No conversion needed (already MP3): $ttsFile");
            return $ttsFile;
        }
        if (!is_file($ttsFile)) {
            LOGERR("output/alsa.php: TTS file not found: $ttsFile");
            return null;
        }

        $mp3File = $ttspath . '/' . pathinfo($ttsFile, PATHINFO_FILENAME) . '.mp3';

        $wi   = wav_info($ttsFile);
        $args = ['-vn','-sn','-dn']; // faster: drop non-audio streams

        // Target profile for speech: mono + 22.05 kHz
        $targetCh = 1;
        $targetSr = 22050;

        if ($wi) {
            if ($wi['channels']    !== $targetCh) { $args[] = '-ac 1'; }
            if ($wi['sample_rate'] !== $targetSr) { $args[] = '-ar 22050'; }
        } else {
            // Unknown header → resample conservatively
            $args[] = '-ac 1';
            $args[] = '-ar 22050';
        }

        // LAME VBR: good quality & fast for speech; for even faster: -q:a 7 or 8
        $cmd = sprintf(
            "ffmpeg -y -nostdin -hide_banner -loglevel error -i %s %s -codec:a libmp3lame -q:a 6 %s",
            escapeshellarg($ttsFile),
            implode(' ', $args),
            escapeshellarg($mp3File)
        );

        LOGDEB("output/alsa.php: Converting WAV -> MP3 with ffmpeg: $cmd");
        shell_exec($cmd);

        if (!is_file($mp3File)) {
            LOGERR("output/alsa.php: Conversion failed (no MP3 created): $mp3File");
            return null;
        }

        LOGDEB("output/alsa.php: Conversion complete: $mp3File");
        return $mp3File;
    };

  	/* -------- Jingle-Handling: nur wenn ?jingle vorhanden --------
	   - ?jingle=FILENAME  → verwende diese Datei (oder absolute URL/Pfad)
	   - ?jingle           → verwende Standard-Jingle aus Config (MP3.file_gong)
	   - kein ?jingle      → kein Jingle
	---------------------------------------------------------------- */
	$jingle = null;
	$playJingle = array_key_exists('jingle', $_GET);  // nur Präsenz zählt

	if ($playJingle) {
		$val = trim((string)($_GET['jingle'] ?? ''));

		if ($val !== '') {
			// expliziter Dateiname/URL
			if (!preg_match('~\.mp3$~i', $val)) { $val .= '.mp3'; }
			if ($val[0] === '/' || preg_match('~^https?://~i', $val)) {
				$cand = $val;
			} else {
				$cand = $mp3path . '/' . basename($val);
			}

			if (is_file($cand)) {
				$jingle = $cand;
				LOGINF("output/alsa.php: Using jingle from URL: '" . basename($val) . "'");
			} else {
				LOGWARN("output/alsa.php: Jingle from URL not found: $cand");
			}
		} else {
			// leerer Wert → Standard-Jingle aus CONFIG
			$std  = (string)($config['MP3']['file_gong'] ?? '');
			if ($std !== '') {
				$base = preg_replace('~\.mp3$~i', '', $std);
				$cand = $mp3path . '/' . $base . '.mp3';
				if (is_file($cand)) {
					$jingle = $cand;
					LOGINF("output/alsa.php: ?jingle (empty) → using default from config: '" . basename($cand) . "'");
				} else {
					LOGWARN("output/alsa.php: Default jingle not found: $cand");
				}
			} else {
				LOGDEB("output/alsa.php: ?jingle present but no default configured.");
			}
		}
	}

	/* -------- Playback -------- */
	$toPlay = $convertToMp3($finalfile) ?: $finalfile;
	if (!is_file($toPlay)) {
		LOGWARN("output/alsa.php: No valid input to play (missing final file).");
		return;
	}
	if ($jingle && realpath($jingle) === realpath($toPlay)) { // doppelt verhindern
		LOGDEB("output/alsa.php: Jingle equals main file – skipping jingle.");
		$jingle = null;
	}
	if ($jingle) { $play($jingle, 'Jingle'); }
	$play($toPlay, 'TTS');
}
