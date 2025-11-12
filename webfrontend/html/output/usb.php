<?php
/**
 * Submodul: RPi USB Output (mpg123 + ffmpeg)
 * - MP3 -> mpg123  (schnell & leichtgewichtig)
 * - WAV/sonstiges -> ffmpeg (direkt auf ALSA)
 * - Volume-Mapping: Sox -v (0..∞)  -> mpg123 -f (0..32768), ffmpeg volume=<linear>
 */
function usb() {
    global $volume, $messageid, $filename, $config;

    $mp3path = rtrim($config['SYSTEM']['mp3path'], '/');
    $ttspath = rtrim($config['SYSTEM']['ttspath'], '/');

    // ALSA Devices
    #$alsaDevice     = 'dmix:1,0';          // ffmpeg: -f alsa "$alsaDevice"
    #$mpg123Device   = 'plug:dmix:1,0';     // mpg123: -o alsa -a "$mpg123Device"
	$cardno   = $config['SYSTEM']['usbcardno'] ?? '1';
	$devno    = $config['SYSTEM']['usbdevice'] ?? '0';
	
	// Test if dmix is available for this card
    $dmixTest = trim(shell_exec("aplay -L | grep -E '^dmix:$cardno,$devno' || true"));

    if ($dmixTest !== '') {
        $alsaDevice   = "dmix:$cardno,$devno";
        $mpg123Device = "plug:dmix:$cardno,$devno";
        LOGINF("output/usb.php: Using ALSA device 'dmix:$cardno,$devno'");
    } else {
        $alsaDevice   = "plughw:$cardno,$devno";
        $mpg123Device = "plughw:$cardno,$devno";
        LOGWARN("output/usb.php: Fallback to 'plughw:$cardno,$devno' (no dmix entry found)");
    }

    // Task Spooler Umgebung
    putenv("TS_SOCKET=/dev/shm/ttsplugin.sock");
    putenv("TS_MAXFINISHED=10");
    putenv("TS_MAXCONN=10");
    putenv("TS_MAILTO=");

    // --- Helpers ---
    $bin_exists = static function(string $bin): bool {
        $out = trim((string)shell_exec("command -v ".escapeshellarg($bin)." 2>/dev/null || true"));
        return $out !== '';
    };

    $play = function(string $file) use ($volume, $alsaDevice, $mpg123Device, $bin_exists) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $vol = max(0.0, (float)$volume);

        if ($ext === 'mp3' && $bin_exists('mpg123')) {
            // Sox -v (1.0 = 100%) -> mpg123 -f (32768 = 100%)
            $scale = (int)round(max(0, $vol) * 32768);
            $scale = max(0, min(32768, $scale));
            $cmd = sprintf(
                'tsp -n mpg123 -q -o alsa -a %s -f %d %s',
                escapeshellarg($mpg123Device),
                $scale,
                escapeshellarg($file)
            );
            shell_exec($cmd);
            LOGINF("output/usb.php: Executed mpg123: '$cmd'");
            return;
        }

        // Fallback / Nicht-MP3: ffmpeg direkt auf ALSA
        // ffmpeg volume-Filter: linearer Faktor (1.0 = 0 dB)
        $ffVol = number_format($vol, 3, '.', '');
        $cmd = sprintf(
            'tsp -n ffmpeg -hide_banner -loglevel error -nostats -i %s -filter:a %s -f alsa %s',
            escapeshellarg($file),
            escapeshellarg("volume=${ffVol}"),
            escapeshellarg($alsaDevice)
        );
        shell_exec($cmd);
        LOGINF("output/usb.php: Executed ffmpeg: '$cmd'");
    };

    // --- Jingle-Handling ---
    $jingle = null;
    if (isset($_GET['jingle'])) {
        $jingle = empty($_GET['jingle']) ? ($config['MP3']['file_gong'] ?? '') : ($_GET['jingle'] . '.mp3');
        // Wenn du mp3_files() nur für MP3 hast, zusätzlich Dateiexistenz prüfen:
        $jinglePath = $mp3path . '/' . $jingle;
        if (!$jingle || (!function_exists('mp3_files') ? !is_file($jinglePath) : !mp3_files($jingle))) {
            LOGWARN("output/usb.php: The entered jingle file '$jingle' is not valid.");
            $jingle = null;
        }
    }

    // --- Abspielen: Datei oder TTS ---
    if (isset($_GET['file'])) {
        // Explizite Datei aus dem MP3-Verzeichnis
        $main = $mp3path . '/' . $messageid . '.mp3';
        if ($jingle) $play($mp3path . '/' . $jingle);
        $play($main);
    } elseif (isset($_GET['text'])) {
        // TTS-Datei aus dem TTS-Verzeichnis: bevorzugt MP3, sonst WAV
        $cand = [
            $ttspath . '/' . $filename . '.mp3',
            $ttspath . '/' . $filename . '.wav',
        ];
        $toPlay = null;
        foreach ($cand as $p) {
            if (is_file($p)) { $toPlay = $p; break; }
        }
        if ($jingle) $play($mp3path . '/' . $jingle);
        if ($toPlay) {
            $play($toPlay);
        } else {
            LOGWARN("output/usb.php: No TTS file found for '$filename' (.mp3 or .wav).");
        }
    } else {
        LOGWARN("output/usb.php: No valid input (file or text) provided in request.");
    }
}
?>
