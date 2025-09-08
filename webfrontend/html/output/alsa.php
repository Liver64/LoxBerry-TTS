<?php
/**
 * Submodule: Raspberry Pi ALSA Output
 *
 * Plays TTS or MP3 files using the default ALSA device.
 * 
 * - Uses `ffmpeg` to convert WAV → MP3 if needed.
 * - Uses `mpg123` for direct MP3 playback (low CPU usage, low latency).
 * - Supports optional jingle playback before the main audio.
 * - Integrates with Task Spooler (`tsp`) to manage playback jobs cleanly.
 */

function alsa_ob($finalfile) {
    global $volume, $config;

    $mp3path = rtrim($config['SYSTEM']['mp3path'], '/'); // Path to stored MP3 files
    $ttspath = rtrim($config['SYSTEM']['ttspath'], '/'); // Path to TTS temporary files
    $device  = 'alsa'; // Default ALSA device

    // Configure Task Spooler environment variables
    putenv("TS_SOCKET=/dev/shm/ttsplugin.sock");
    putenv("TS_MAXFINISHED=10");
    putenv("TS_MAXCONN=10");
    putenv("TS_MAILTO=\"\"");

    /**
     * Helper function to play an MP3 file using mpg123
     *
     * @param string $file  Absolute path to the MP3 file
     * @param string $label Optional label for logging
     */
    $play = function($file, $label = 'TTS') use ($volume, $device) {
		if (!file_exists($file)) {
			LOGERR("output/alsa.php: File not found: $file");
			return;
		}

		// mpg123 volume scaling
		$scaledVolume = intval(32768 * $volume);

		// Non-blocking command execution
		$cmd = "tsp -n mpg123 -a $device -f $scaledVolume \"$file\" > /dev/null 2>&1 &";

		LOGDEB("output/alsa.php: Executing mpg123 command [$label]: $cmd");
		exec($cmd); // non-blocking
		LOGINF("output/alsa.php: Started playing [$label]");
	};

    /**
     * Helper function to convert a TTS file to MP3 if necessary
     *
     * If the input file is already an MP3, no conversion is done.
     *
     * @param string $ttsFile Path to the TTS file (WAV or MP3)
     * @return string|null Returns the MP3 path or null if conversion failed
     */
    $convertToMp3 = function($ttsFile) use ($ttspath) {
        // Skip conversion if already MP3
        if (strtolower(pathinfo($ttsFile, PATHINFO_EXTENSION)) === 'mp3') {
            LOGDEB("output/alsa.php: No conversion needed, file is already MP3: $ttsFile");
            return $ttsFile;
        }

        // Validate file existence
        if (!file_exists($ttsFile)) {
            LOGERR("output/alsa.php: TTS file not found: $ttsFile");
            return null;
        }

        LOGDEB("output/alsa.php: WAV detected, starting ffmpeg conversion...");

        // Build MP3 output path
        $mp3File = $ttspath . '/' . pathinfo($ttsFile, PATHINFO_FILENAME) . '.mp3';

        // ffmpeg command for fast WAV → MP3 conversion
        $cmd = "ffmpeg -y -nostdin -loglevel error -i ".$wavFile." -codec:a libmp3lame -qscale:a 4 ".$mp3File."";
        shell_exec($cmd);

        if (!file_exists($mp3File)) {
            LOGERR("output/alsa.php: Conversion failed, MP3 not created: $mp3File");
            return null;
        }

        LOGDEB("output/alsa.php: Conversion complete: $mp3File");
        return $mp3File;
    };

    /**
     * Optional jingle playback
     * 
     * A jingle file can be provided via the `jingle` GET parameter.
     */
    $jingle = null;
    if (isset($_GET['jingle'])) {
        $jingle = $_GET['jingle'];
        if (!empty($jingle) && substr($jingle, -4) !== '.mp3') {
            $jingle .= '.mp3';
        }

        $jingleFile = "$mp3path/$jingle";
        if (!mp3_files($jingle) || !file_exists($jingleFile)) {
            LOGWARN("output/alsa.php: Jingle file '$jingle' not found or invalid!");
            $jingle = null;
        } else {
            $jingle = $jingleFile;
        }
    }

    /**
     * Main playback logic:
     * 
     * Priority:
     *  1. `file` GET parameter → play MP3 file
     *  2. `text` GET parameter → play generated TTS file
     */
    if (!empty($_GET['file'])) {
        // Play MP3 file provided via GET parameter
        $fileToPlay = "$mp3path/" . basename($_GET['file']);
        if (substr($fileToPlay, -4) !== '.mp3') {
            $fileToPlay .= '.mp3';
        }

        if ($jingle) $play($jingle, 'Jingle');
        $play($fileToPlay, 'File');

    } elseif (!empty($_GET['text']) && !empty($finalfile)) {
        // TTS playback: convert if WAV, then play MP3
        $mp3Final = $convertToMp3($finalfile);

        if ($mp3Final) {
            if ($jingle) $play($jingle, 'Jingle');
            $play($mp3Final, 'TTS');
        } else {
            LOGERR("output/alsa.php: Unable to play TTS, conversion failed.");
        }

    } else {
        LOGWARN("output/alsa.php: No valid input provided (`file` or `text`).");
    }
}
?>
