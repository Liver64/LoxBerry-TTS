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
require_once("bin/logging.php");
require_once("output/usb.php");
require_once("bin/tts_functions.php"); // alle TTS-Funktionen

register_shutdown_function('shutdown');
date_default_timezone_set(date("e"));

# ------------------ Variablen ------------------
$hostname       = gethostname();
$myIP           = $_SERVER["SERVER_ADDR"] ?? '';
$syntax         = $_SERVER['REQUEST_URI'] ?? '';
$lbversion      = LBSystem::lbversion();
$myConfigFolder = $lbpconfigdir;
$myFolder       = $lbpdatadir;

# ------------------ Logging initialisieren ------------------
$params = [
    "name"    => "Text2speech",
    "filename"=> "$lbplogdir/text2speech.log",
    "append"  => 1,
    "addtime" => 1,
];
$log = LBLog::newLog($params);
$time_start = microtime(true);

LOGSTART("PHP started");

# ------------------ Config laden ------------------
if (file_exists($myConfigFolder . "/t2s_config.json")) {
    $config = json_decode(file_get_contents($myConfigFolder . "/t2s_config.json"), true);
} else {
    LOGERR('tts.php: t2s_config.json konnte nicht geladen werden!');
    exit;
}

# ------------------ Syntax-Validierung: Nur erlaubte GET-Parameter ------------------
$allowedParams = [
    'text', 'file', 'function',
    'playbatch', 'greet', 't2sengine', 'filename', 'apikey',
    'secretkey', 'language', 'voice', 'testfile', 'volume',
    'jingle', 'nocache', 'to'
];

$blockedAddonNames = [
	'weather', 'clock', 'abfall', 
	'pollen', 'warning', 'distance', 'calendar'
];
$ignoredParams = ['_']; // z.B. von jQuery oder internen Redirects
# ------------------ Syntax-Validierung: Nur erlaubte GET-Parameter ------------------

foreach ($_GET as $key => $value) {
    if (in_array($key, $ignoredParams, true)) {
        continue; // einfach überspringen
    }
    if (!in_array($key, $allowedParams, true)) {
        LOGERR("tts.php: Invalid Parameter in URL detected: '$key'. Aborting.");
        exit;
    }
}

LOGINF("tts.php: called syntax: " . $myIP . urldecode($syntax));

# ------------------ T2S Parameter vorbereiten ------------------
$t2s_param = [
    'text'       => $_GET['text'] ?? null,
    't2sengine'  => $_GET['t2sengine'] ?? $config['TTS']['t2s_engine'],
    'filename'   => $_GET['filename'] ?? md5(trim($_GET['text'] ?? '')),
    'apikey'     => $_GET['apikey'] ?? $config['TTS']['apikey'],
    'secretkey'  => $_GET['secretkey'] ?? $config['TTS']['secretkey'],
    // 'access_token' => $_GET['access_token'] ?? $config['TTS']['access_token'],
    'language'   => $_GET['language'] ?? $config['TTS']['messageLang'],
    'voice'      => $_GET['voice'] ?? $config['TTS']['voice'],
    'testfile'   => $_GET['testfile'] ?? null
];

# ------------------ Volume ------------------
$volume = $_GET['volume'] ?? $config['TTS']['volume'];
if (!is_numeric($volume) || $volume < 0 || $volume > 500) {
    LOGINF("tts.php: Volume invalid, falling back to default.", $volume);
    $volume = $config['TTS']['volume'];
}
$volume = $volume / 100;

# ------------------ Cache/Buffers minimal leeren ------------------
delete_all_cache();

# ------------------ Flags/State ------------------
$filenameProvided = isset($_GET['filename']) && $_GET['filename'] !== '';
$greetFlag        = filter_has_var(INPUT_GET, 'greet');

# ------------------ Eingabe auflösen (Addons, Datei, Text) ------------------
$resolve = tts_resolve_input($_GET, (string)($t2s_param['text'] ?? ''), $config);

if ($resolve['type'] === 'text') {
    $t2s_param['text'] = $_GET['text'] = $resolve['text'];
} elseif ($resolve['type'] === 'file') {
    $_GET['file'] = $resolve['file'];
    unset($t2s_param['text']);
} else {
    // Z. B. playbatch ohne Text – sauber beenden
    if (($resolve['error'] ?? '') === 'no_text_playbatch') {
        exit;
    }
    #LOGWARN("tts.php: Nothing to play.");
    exit;
}

# ------------------ Greeting (optional) nach Addon-Auflösung ------------------
if ($greetFlag && !empty($t2s_param['text'])) {
    $TL   = load_t2s_text($t2s_param['language'] ?? null);
    $hour = (int)strftime("%H");
    $greeting = '';
    if ($hour >= 4 && $hour < 10) {
        $greeting = $TL['GREETINGS']['MORNING_' . mt_rand(1, 5)] ?? '';
    } elseif ($hour >= 10 && $hour < 17) {
        $greeting = $TL['GREETINGS']['DAY_' . mt_rand(1, 5)] ?? '';
    } elseif ($hour >= 17 && $hour < 22) {
        $greeting = $TL['GREETINGS']['EVENING_' . mt_rand(1, 5)] ?? '';
    } else {
        $greeting = $TL['GREETINGS']['NIGHT_' . mt_rand(1, 5)] ?? '';
    }
    $greeting = trim($greeting);
    if ($greeting !== '') {
        $t2s_param['text'] = trim($greeting . ' ' . $t2s_param['text']);
        $_GET['text']      = $t2s_param['text']; // finaler Text für create_tts()
        LOGDEB("tts.php: Prefixed greeting (lang=" . ($t2s_param['language'] ?? '') . ")");
    }
}

# ------------------ Filename/Cache-Key NACH finalem Text berechnen ------------------
if (!$filenameProvided && !empty($t2s_param['text'])) {
    $hashBasis = json_encode([
        'text'   => (string)$t2s_param['text'],
        'lang'   => (string)($t2s_param['language'] ?? ''),
        'voice'  => (string)($t2s_param['voice'] ?? ''),
        'engine' => (string)($t2s_param['t2sengine'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);
    $t2s_param['filename'] = md5($hashBasis);
    $_GET['filename']      = $t2s_param['filename'];   // create_tts() nutzt diesen Namen
    LOGDEB("tts.php: Recomputed filename from final TTS params: {$t2s_param['filename']}");
}

# ------------------ Testfall: ggf. vorhandene Test-MP3 löschen ------------------
if (is_enabled($t2s_param['testfile'])) {
    $mp3file = rtrim($lbpdatadir, '/') . "/interfacedownload/" . $t2s_param['filename'] . ".mp3";
    if (file_exists($mp3file)) {
        @unlink($mp3file);
        LOGDEB("tts.php: Test MP3: $mp3file has been deleted");
    } else {
        LOGDEB("tts.php: Test MP3: $mp3file does not exist, nothing to delete");
    }
}

# ------------------ TTS erzeugen/aus Cache liefern ------------------
$finalfile = create_tts();

# Fallback, falls Engine unter text-md5 geschrieben hat
if (empty($finalfile) || !is_file($finalfile)) {
    $candidate = rtrim($lbpdatadir, '/') . '/tts/' . $t2s_param['filename'] . '.mp3';
    if (is_file($candidate)) {
        LOGDEB("tts.php: Fixup – using generated MP3: $candidate");
        $finalfile = $candidate;
    }
}

# ------------------ Abspielen ------------------
if (isset($_GET['file']) && !empty($_GET['file'])) {
    $messageid = basename($_GET['file']);  // Schutz vor Pfadangaben
}

if (is_disabled($t2s_param['testfile'])) {
    // Soundkarte wählen
    $soundcard = $config['SYSTEM']['card'] ?? '001';
    require_once('output/alsa.php');

    switch ($soundcard) {
        case '001': // Null / aus
            exit;

        // Onboard (Pi-Klinke usw.)
        case '002': case '003': case '004': case '005':
        case '006': case '007': case '008': case '009':
        case '010': case '011':
            // Karte 0, Device 0 (alsa_ob mappt hw: -> plughw:)
            $myteccard = 'hw:0,0';
            alsa_ob($finalfile);
            break;

        // USB (getusbcard() baut z.B. hw:CARD=USB,DEV=0)
        case '012': case '013':
            getusbcard(); // setzt $myteccard
            alsa_ob($finalfile);
            break;

        // Fallback: zweite Karte
        default:
            $myteccard = 'hw:1,0';
            alsa_ob($finalfile);
            break;
    }
} else {
    LOGINF("tts.php: Test MP3: listen to your PC speaker...");
}

# ------------------ Helpers ------------------

/**
 * Minimales „Cache leeren“: Nur Output-Buffer und Dateistatus-Cache.
 * (Kein OPcache/APCu Reset – Performance!)
 */
function delete_all_cache() {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_start();
    clearstatcache();
    LOGDEB("tts.php: All caches and buffers have been deleted. Browser cache will not be used.");
    ob_end_flush();
}

/**
 * Entscheidet, ob ein Addon gerufen wird, eine Datei gespielt wird,
 * oder ob normaler Text verwendet wird.
 *
 * Rückgabe:
 *  ['type' => 'text', 'text' => string]         // Text an TTS übergeben
 *  ['type' => 'file', 'file' => string]         // Datei aus mp3-Ordner abspielen (basename, mit/ohne .mp3)
 *  ['type' => 'none', 'error' => string|null]   // nichts zu tun (z.B. playbatch ohne text)
 */
function tts_resolve_input(array $get, string $text, array $config): array
{
	global $blockedAddonNames;
	
    $mp3path = rtrim($config['SYSTEM']['mp3path'] ?? '', '/');

    // --- Addons per GET-Flag oder exaktem Textbefehl ---
    if (isset($get['function'])) {
		return load_addon($get['function']);
	}

    // --- Datei aus ?file laden (basename, optional ohne .mp3) ---
    if (!empty($get['file'])) {
        $messageid = basename((string)$get['file']);
        if (substr($messageid, -4) !== '.mp3') {
            $messageid .= '.mp3';
        }
        $full = $mp3path !== '' ? ($mp3path . '/' . $messageid) : $messageid;
        if (is_file($full)) {
            LOGDEB("tts.php: File '$messageid' has been entered and exists.");
            return ['type' => 'file', 'file' => $messageid]; // create_tts() kann damit umgehen
        } else {
            LOGWARN("tts.php: The corresponding file '$messageid' does not exist in mp3 path.");
            return ['type' => 'none', 'error' => "file_not_found"];
        }
    }

    // --- playbatch ohne text ---
    if (empty($get['text']) && isset($get['playbatch'])) {
        LOGWARN("tts.php: No text has been entered (playbatch).");
        return ['type' => 'none', 'error' => "no_text_playbatch"];
    }

    //# --- normaler Text ---
	if (isset($get['text']) && trim($get['text']) !== '') {
		$textValue = trim($get['text']);
		#$blockedAddonNames = ['weather', 'clock', 'abfall', 'pollen', 'warning', 'distance', 'calendar'];

		if (in_array(strtolower($textValue), $blockedAddonNames, true)) {
			LOGERR("tts.php: Text value '$textValue' is reserved for function=... usage. Aborting here and please check your URL!");
			return ['type' => 'none', 'error' => 'reserved_text'];
		}

		LOGDEB("tts.php: Free text has been entered");
		return ['type' => 'text', 'text' => $textValue];
	}

    // --- Fallback: nichts zu tun ---
    LOGWARN("tts.php: Neither addon/file nor text provided.");
    return ['type' => 'none', 'error' => "no_input"];
	}



/**
 * Lädt ein Addon anhand des Funktionsnamens, prüft Existenz und gibt Text zurück.
 *
 * @param string $function Name des Addons (z. B. 'weather', 'calendar')
 * @return array ['type' => 'text', 'text' => string] oder ['type' => 'none', 'error' => string]
 */
function load_addon(string $function): array {
    $addonMap = [
        'weather'   => ['file' => 'addon/weather-to-speech.php',           'func' => 'w2s'],
        'clock'     => ['file' => 'addon/clock-to-speech.php',            'func' => 'c2s'],
        'pollen'    => ['file' => 'addon/pollen-to-speach.php',           'func' => 'p2s'],
        'warning'   => ['file' => 'addon/weather-warning-to-speech.php',  'func' => 'ww2s'],
        'distance'  => ['file' => 'addon/time-to-destination-speech.php', 'func' => 'tt2t'],
        'abfall'    => ['file' => 'addon/waste-calendar-to-speech.php',   'func' => 'muellkalender'],
        'calendar'  => ['file' => 'addon/calendar-to-speech.php',         'func' => 'calendar'],
    ];

    if (!array_key_exists($function, $addonMap)) {
        LOGERR("tts.php: Unknown addon key '$function'. No mapping found.");
        return ['type' => 'none', 'error' => 'invalid_function'];
    }

    $addon = $addonMap[$function];
    $file = $addon['file'];
    $func = $addon['func'];

    if (!file_exists($file)) {
        LOGERR("tts.php: Addon file for key '$function' not found: '$file'.");
        return ['type' => 'none', 'error' => 'file_missing'];
    }

    include_once($file);

    if (!function_exists($func)) {
        LOGERR("tts.php: Addon function for key '$function' not found: '$func' in file '$file'.");
        return ['type' => 'none', 'error' => 'function_missing'];
    }

    $txt = call_user_func($func);
    if (empty($txt)) {
        LOGDEB("tts.php: Addon function '$func' for key '$function' returned empty text.");
        return ['type' => 'none', 'error' => 'empty_output'];
    }

    LOGDEB("tts.php: Addon '$function' executed successfully using function '$func' from '$file'.");
    return ['type' => 'text', 'text' => substr($txt, 0, 500)];
}

/**
 * USB-Karte aus hats.json ermitteln
 */
function getusbcard() {
    global $config, $lbpbindir, $t2s_param, $myteccard;

    $jsonFile = $lbpbindir . "/hats.json";

    if (!file_exists($jsonFile)) {
        LOGERR("tts.php: getusbcard(): JSON file not found: $jsonFile");
        return false;
    }
    $cfg = json_decode(file_get_contents($jsonFile), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        LOGERR("tts.php: getusbcard(): Failed to parse JSON file: " . json_last_error_msg());
        return false;
    }

    $mycard   = $config['SYSTEM']['usbcard']  ?? '';
    $usbCardNo= $config['SYSTEM']['usbcardno']?? '';
    $usbDevice= $config['SYSTEM']['usbdevice']?? '';

    if (!isset($cfg[$mycard]['output'])) {
        LOGERR("tts.php: getusbcard(): Card type '$mycard' not found in JSON config.");
        return false;
    }

    if ($mycard === "usb_audio") {
        $myteccard = $cfg[$mycard]['output'] . $usbCardNo . ',' . $usbDevice;
    } else {
        $myteccard = $cfg[$mycard]['output'] . ',DEV=' . $usbDevice;
    }

    LOGINF("tts.php: getusbcard(): Detected card string: $myteccard");
    return $myteccard;
}

/**
 * Shutdown/Timing
 */
function shutdown() {
    global $time_start, $log;
    $time_end = microtime(true);
    $process_time = $time_end - $time_start;
    LOGINF("Processing request tooks about " . round($process_time, 3) . " seconds.\n");
    LOGEND("PHP finished");
    exit;
}

?>
