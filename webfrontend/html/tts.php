<?php
##############################################################################################################################
# tts.php - LoxBerry Text2Speech
# Version: 2.0.0 Optimized
##############################################################################################################################

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

ini_set('max_execution_time', 90);

require_once("bin/helper.php");
require_once('output/usb.php');
require_once('bin/tts_functions.php'); // alle TTS-Funktionen

date_default_timezone_set(date("e"));

# ------------------ Variablen ------------------
$hostname = gethostname();
$myIP = $_SERVER["SERVER_ADDR"];
$syntax = $_SERVER['REQUEST_URI'];
$lbversion = LBSystem::lbversion();
$myConfigFolder = $lbpconfigdir;
$myFolder = $lbpdatadir;

# Logging initialisieren
$params = [
    "name" => "Text2speech",
    "filename" => "$lbplogdir/text2speech.log",
    "append" => 1,
    "addtime" => 1,
];
$log = LBLog::newLog($params);

LOGSTART("PHP started");

# ------------------ Config laden ------------------
if(file_exists($myConfigFolder . "/t2s_config.json")) {
    $config = json_decode(file_get_contents($myConfigFolder . "/t2s_config.json"), true);
} else {
    LOGERR('tts.php: t2s_config.json konnte nicht geladen werden!');
    exit;
}

# ------------------ T2S Parameter vorbereiten ------------------
$t2s_param = [
    'text'      	=> $_GET['text'] ?? null,
    't2sengine' 	=> $_GET['t2sengine'] ?? $config['TTS']['t2s_engine'],
    'filename'  	=> $_GET['filename'] ?? md5(trim($_GET['text'] ?? '')),
    'apikey'    	=> $_GET['apikey'] ?? $config['TTS']['apikey'],
    'secretkey' 	=> $_GET['secretkey'] ?? $config['TTS']['secretkey'],
	#'access_token'	=> $_GET['access_token'] ?? $config['TTS']['access_token'],
    'language'  	=> $_GET['language'] ?? $config['TTS']['messageLang'],
    'voice'     	=> $_GET['voice'] ?? $config['TTS']['voice'],
    'testfile'  	=> $_GET['testfile'] ?? null
];

# ------------------ Volume ------------------
$volume = $_GET['volume'] ?? $config['TTS']['volume'];
if(!is_numeric($volume) || $volume<0 || $volume>500) {
    LOGINF("tts.php: Volume ungültig, Standard wird verwendet",$volume);
    $volume = $config['TTS']['volume'];
}
$volume = $volume/100;

# ------------------ Cache löschen ------------------
delete_all_cache();

# ------------------ Input prüfen ------------------
$text = $t2s_param['text'];
$greet = $_GET['greet'] ?? "";

# ------------------ Gruß-Logik ------------------
if($greet) {
    $hour = intval(strftime("%H"));
    $TL = LOAD_T2S_TEXT();
    if($hour>=4 && $hour<10) $greet = $TL['GREETINGS']['MORNING_'.mt_rand(1,5)];
    elseif($hour>=10 && $hour<17) $greet = $TL['GREETINGS']['DAY_'.mt_rand(1,5)];
    elseif($hour>=17 && $hour<22) $greet = $TL['GREETINGS']['EVENING_'.mt_rand(1,5)];
    elseif($hour>=22) $greet = $TL['GREETINGS']['NIGHT_'.mt_rand(1,5)];
}

# ------------------ TTS erstellen ------------------
if(empty($config['TTS']['t2s_engine']) || empty($config['TTS']['messageLang'])) {
    LOGERR("tts.php: T2S Engine oder Sprache nicht gesetzt!");
    exit;
}

# ------ wenn Test aus Plugin vorher MP3 löschen -----------
if (is_enabled($t2s_param['testfile'])) {
    $mp3file = $lbpdatadir . "/interfacedownload/" . $t2s_param['filename'] . ".mp3";

    if (file_exists($mp3file)) {
        @unlink($mp3file);
        LOGDEB("tts.php: Test MP3: $mp3file has been deleted");
    } else {
        LOGDEB("tts.php: Test MP3: $mp3file does not exist, nothing to delete");
    }
}

$finalfile = create_tts();

if (isset($_GET['file']) && !empty($_GET['file'])) {
    $messageid = basename($_GET['file']);  // Schutz vor Pfadangaben
}
if (is_disabled($t2s_param['testfile'])) {
	# ------------------ Soundcard abspielen ------------------
	$soundcard = $config['SYSTEM']['card'] ?? '001';
	require_once('output/alsa.php');
	switch($soundcard) {
		case '001': exit; break; # Null
		case '002': case '003': case '004': case '005': case '006': case '007': case '008': case '009': case '010': case '011':
			shell_exec("export AUDIODRIVER=alsa");
			shell_exec("export AUDIODEV=default:0");
			alsa_ob($finalfile);
			break;
		case '012': case '013':
			getusbcard();
			shell_exec("export AUDIODRIVER=alsa");
			shell_exec("export AUDIODEV=".$myteccard);
			alsa_ob($finalfile);
			break;
		default:
			shell_exec("export AUDIODRIVER=alsa");
			shell_exec("export AUDIODEV=hw:1,0");
			alsa_ob($finalfile);
			break;
	}
} else {
	LOGINF("tts.php: Test MP3: listen to your PC speaker...");
}

/**
 * Funktion: delete_all_cache
 * Löscht Output-Buffer, OPcache, APCu und PHP Dateistatus-Cache
 */
function delete_all_cache() {
    // ------------------ Output Buffer ------------------
    if (ob_get_level() > 0) {
        ob_end_clean(); // alle aktiven Buffer schließen und leeren
    }
    ob_start(); // neuen Buffer starten

    // ------------------ Opcache zurücksetzen ------------------
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }

    // ------------------ APCu Cache zurücksetzen ------------------
    if (function_exists('apcu_clear_cache')) {
        apcu_clear_cache();
    }

    // ------------------ PHP Dateistatus Cache ------------------
    clearstatcache();

    // ------------------ Debug ------------------
    LOGDEB("tts.php: All caches and buffers have been deleted. Browser cache will not be used.");
    ob_end_flush();
}


function getusbcard() {
	
    global $config, $lbpbindir, $t2s_param, $myteccard;

    $jsonFile = $lbpbindir . "/hats.json";

    // Prüfen, ob JSON existiert
    if (!file_exists($jsonFile)) {
        LOGERR("tts.php: getusbcard(): JSON file not found: $jsonFile");
        return false;
    }

    // JSON einlesen und parsen
    $cfg = json_decode(file_get_contents($jsonFile), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        LOGERR("tts.php: getusbcard(): Failed to parse JSON file: " . json_last_error_msg());
        return false;
    }

    $mycard = $config['SYSTEM']['usbcard'] ?? '';
    $usbCardNo = $config['SYSTEM']['usbcardno'] ?? '';
    $usbDevice = $config['SYSTEM']['usbdevice'] ?? '';

    // Prüfen, ob die Karte existiert
    if (!isset($cfg[$mycard]['output'])) {
        LOGERR("tts.php: getusbcard(): Card type '$mycard' not found in JSON config.");
        return false;
    }

    // Ergebnis-String zusammenbauen
    if ($mycard === "usb_audio") {
        $myteccard = $cfg[$mycard]['output'] . $usbCardNo . ',' . $usbDevice;
    } else {
        $myteccard = $cfg[$mycard]['output'] . ',DEV=' . $usbDevice;
    }

    LOGINF("tts.php: getusbcard(): Detected card string: $myteccard");
    return $myteccard;
}

LOGEND("PHP finished");
exit;
?>
