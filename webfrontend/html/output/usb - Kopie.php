<?php
/**
 * Submodul: RpI USB Output
 * 
 * Funktion: usb
 * Spielt TTS- oder MP3-Dateien über eine USB-Soundkarte ab (via ALSA dmix:1,0).
 *
 */

function usb() {
	
    global $volume, $messageid, $filename, $config;

    $mp3path = rtrim($config['SYSTEM']['mp3path'], '/');
    $ttspath = rtrim($config['SYSTEM']['ttspath'], '/');
    $device  = 'alsa dmix:1,0';

    // Task-Spooler-Umgebungsvariablen setzen
    putenv("TS_SOCKET=/dev/shm/ttsplugin.sock");
    putenv("TS_MAXFINISHED=10");
    putenv("TS_MAXCONN=10");
    putenv("TS_MAILTO=\"\"");

    // Hilfsfunktion für SoX-Aufruf
    $play = function($file) use ($volume, $device) {
        $cmd = "tsp -n sox -v $volume \"$file\" -t $device";
        shell_exec($cmd);
        LOGINF("output/usb.php: Executed SoX command: '$cmd'");
    };

    // Jingle-Handling
    $jingle = null;
    if (isset($_GET['jingle'])) {
        $jingle = empty($_GET['jingle']) ? $config['MP3']['file_gong'] : $_GET['jingle'] . '.mp3';
        if (!mp3_files($jingle)) {
            LOGWARN("output/usb.php: The entered jingle file '$jingle' is not valid, please correct your syntax!");
            $jingle = null; // ungültig -> nicht abspielen
        }
    }

    // Datei oder TTS abspielen
    if (isset($_GET['file'])) {
        // MP3-Datei
        if ($jingle) $play("$mp3path/$jingle");
        $play("$mp3path/$messageid.mp3");
    } elseif (isset($_GET['text'])) {
        // TTS-Datei
        if ($jingle) $play("$mp3path/$jingle");
        $play("$ttspath/$filename.mp3");
    } else {
        LOGWARN("output/usb.php: No valid input (file or text) provided in request.");
    }
}
?>
