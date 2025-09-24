<?php
/**
 * Submodul: RpI ALSA Output
 * 
 * Spielt TTS- oder MP3-Dateien über Standard-ALSA-Gerät ab.
 */

function alsa_ob($finalfile) {
	
    global $volume, $config;

    $mp3path = rtrim($config['SYSTEM']['mp3path'], '/');
    $ttspath = rtrim($config['SYSTEM']['ttspath'], '/');
    $device  = 'alsa'; // Standard ALSA Gerät

    // Task-Spooler-Umgebungsvariablen
    putenv("TS_SOCKET=/dev/shm/ttsplugin.sock");
    putenv("TS_MAXFINISHED=10");
    putenv("TS_MAXCONN=10");
    putenv("TS_MAILTO=\"\"");

    // Hilfsfunktion zum Abspielen
    $play = function($file, $label = 'TTS') use ($volume, $device) {
        #LOGDEB("Play request [$label]: $file");

        if (!file_exists($file)) {
            LOGERR("output/alsa.php: Datei nicht gefunden: $file");
            return;
        }
		$cmd = "tsp -n sox -v $volume \"$file\" -t $device";
		LOGDEB("Executing SoX command [$label]: $cmd");
		shell_exec($cmd);
		LOGINF("output/alsa.php: Finished playing [$label]");
    };

    // Jingle vorbereiten
    $jingle = null;
    if (isset($_GET['jingle'])) {
        $jingle = $_GET['jingle'];
        if (!empty($jingle) && substr($jingle, -4) !== '.mp3') {
            $jingle .= '.mp3';
        }

        $jingleFile = "$mp3path/$jingle";
        if (!mp3_files($jingle) || !file_exists($jingleFile)) {
            LOGWARN("output/alsa.php: Jingle-Datei '$jingle' nicht gefunden oder ungültig!");
            $jingle = null;
        } else {
            $jingle = $jingleFile;
        }
    }

    // MP3 abspielen
    if (!empty($_GET['file'])) {
        // Datei aus URL
        $fileToPlay = "$mp3path/" . basename($_GET['file']);
        if (substr($fileToPlay, -4) !== '.mp3') $fileToPlay .= '.mp3';

        if ($jingle) $play($jingle, 'Jingle');
        $play($fileToPlay, 'File');
    } elseif (!empty($_GET['text']) && !empty($finalfile)) {
        // TTS-Datei
        if ($jingle) $play($jingle, 'Jingle');
        $play($finalfile, 'TTS');
    } else {
        LOGWARN("output/alsa.php: Kein gültiger Input (file oder text) angegeben.");
    }
}


?>
