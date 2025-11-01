#!/usr/bin/php
<?php
/* mqtt-subscribe.php – TTS Handler (ohne MQTT-Config Responder) */

require_once "REPLACELBHOMEDIR/libs/phplib/loxberry_system.php";
require_once "REPLACELBHOMEDIR/libs/phplib/loxberry_io.php";
require_once "REPLACELBHOMEDIR/libs/phplib/loxberry_log.php";
require_once "REPLACELBHOMEDIR/webfrontend/html/plugins/text2speech/bin/helper.php";
require_once "REPLACELBHOMEDIR/webfrontend/html/plugins/text2speech/bin/phpmqtt/phpMQTT.php";

use Bluerhinos\phpMQTT;

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

/* =======================
 * Grundkonfiguration
 * ======================= */
$logfile            = "REPLACELBHOMEDIR/log/plugins/text2speech/mqtt.log";
$responseTopic      = 'tts-subscribe';   // Rückkanal: Handler-Antworten (Default)

/* LoxBerry Logging (Datei für allgemeine Interface-Logs) */
$params = [
    "name"    => "TTS-Interface",
    "filename"=> "REPLACELBHOMEDIR/log/plugins/text2speech/interface.log",
    "append"  => 1,
    "addtime" => 1,
];
$log = LBLog::newLog($params);

/* Globales Logging für MQTT-Status (eigenes, optionales Logfile) */
$enableLogMsg 			= false; // true aktiviert zusätzlich $logfile-Ausgaben
const HANDSHAKE_DEBUG   = false; // ture aktiviert zusätzlich Loxberry Logging

umask(0002); // Dateien entstehen als 664, Ordner als 775

/* =======================
 * Subscribe-Topic (breit)
 * ======================= */
$subscribeTopic = 'tts-publish/#';

$GLOBALS['__last_msg_hash'] = null;
$GLOBALS['__last_msg_time'] = 0;

/* =======================
 * internes Log-Array für optionale Rückgabe
 * ======================= */
$logArray = [];

/* =======================
 * Hilfs-Loggingfunktion
 * ======================= */
function logmsg($level, $message) {
    global $logfile, $logArray, $enableLogMsg;

    if ($enableLogMsg) {
        $emojiMap = [
            'START'=>'🛑','END'=>'🛑','OK'=>'✅','ERROR'=>'❌','INFO'=>'ℹ️','UPDATE'=>'🔄'
        ];
        $emoji = $emojiMap[$level] ?? '';
        $timestamp = date("H:i:s");
        $entry = "$timestamp $emoji";
        $entry .= is_array($message)
            ? json_encode($message, JSON_UNESCAPED_UNICODE)
            : $message;
        $entry .= "\n";
        if (!is_dir(dirname($logfile))) { @mkdir(dirname($logfile), 0775, true); }
        file_put_contents($logfile, mb_convert_encoding($entry, "UTF-8", "auto"), FILE_APPEND | LOCK_EX);
    }

    // MQTT-Logeinträge neutral & einzeilig sammeln
    $clean = $message;
    if (is_array($message)) {
        $parts=[];
        foreach ($message as $k=>$v) {
            if (is_scalar($v) || $v===null) { $parts[]="$k: $v"; }
            else { $parts[]="$k: ".json_encode($v, JSON_UNESCAPED_UNICODE); }
        }
        $clean = implode(', ', $parts);
    } else {
        $clean = preg_replace('/[^\x20-\x7EÄÖÜäöüß]/u', '', $clean);
    }
    $clean = preg_replace('/\s+/', ' ', $clean);
    $logArray[] = "<$level> $clean";
}

/* Startmeldung */
logmsg("START", "Start listening for MQTT publish...");
LOGSTART("Start listening for MQTT publish...");

/* =======================
 * MQTT-Verbindung
 * ======================= */
$creds     = mqtt_connectiondetails();
#$broker    = $creds['brokerhost'] ?? $creds['brokeraddress'] ?? '127.0.0.1';
# ---- New -----
$port      = (int)($creds['brokerport'] ?? 1883);
// Wenn kein Plain-Listener existiert, TLS auf localhost erzwingen
$brokerHost = $creds['brokerhost'] ?? $creds['brokeraddress'] ?? '127.0.0.1';
$useTls     = ($port != 1883); // simple Heuristik für „TLS-only-Broker“
$broker     = $useTls ? "tls://$brokerHost" : $brokerHost;
# ---- End New ----
$user      = $creds['brokeruser'] ?? $creds['mqttuser'] ?? '';
$pass      = $creds['brokerpass'] ?? $creds['mqttpass'] ?? '';
$client_id = uniqid((gethostname() ?: 'lb') . "_client_");
$mqtt      = new phpMQTT($broker, $port, $client_id);

/* === Retry-Schleife (alle 5s) statt Exit === */
$__retries = 0;
while (!$mqtt->connect(true, NULL, $user, $pass)) {
    if ($__retries % 6 === 0) {
        logmsg("ERROR", "MQTT connect failed – will retry.");
        LOGWARN("mqtt-subscribe.php: MQTT connect failed – retrying in 5s");
    }
    $__retries++;
    sleep(5);
}

logmsg("OK", "MQTT connected – listening to Topics: [$subscribeTopic]");
LOGINF("mqtt-subscribe.php: MQTT connected – listening to Topics: [$subscribeTopic]");

/* =======================
 * Schema für TTS-JSON
 * ======================= */
$expectedSchema = [
    "type" => "object",
    "required" => ["text"],
    "properties" => [
        "text"     => ["type" => "string"],
        "nocache"  => ["type" => "number"],
        "logging"  => ["type" => "number"],
        "mp3files" => ["type" => "number"],

        // optional, nur validieren:
        "client"   => ["type" => "string"],
        "instance" => ["type" => "string"],
        "corr"     => ["type" => "string"],
        "reply_to" => ["type" => "string"],
    ],
    "additionalProperties" => false
];

/* =======================
 * TTS-Callback (tts-publish)
 * ======================= */
$callback = function (string $topic, string $msg) use ($mqtt, $responseTopic, $expectedSchema) {
    global $logArray;

    // --- Dedupe-Window: 25s ---
    $now  = time();
    $hash = sha1($msg);
    if ($GLOBALS['__last_msg_hash'] === $hash && ($now - $GLOBALS['__last_msg_time']) < 25) {
        LOGINF("mqtt-subscribe.php: Duplicate payload within 25s window -> ignored");
        return;
    }
    $GLOBALS['__last_msg_hash'] = $hash;
    $GLOBALS['__last_msg_time'] = $now;

    $logArray = []; // Reset pro Nachricht
    logmsg("INFO", "Payload received from [$topic]: $msg");
    LOGDEB("mqtt-subscribe.php: Payload received on topic [$topic]: $msg");

    $data = json_decode($msg, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        logmsg("ERROR", ['Invalid JSON received:', json_last_error_msg(), $msg]);
        LOGERR("mqtt-subscribe.php: Invalid JSON received: " . json_last_error_msg());
        return sendMqtt($mqtt, $responseTopic, "Invalid JSON syntax", [
            'error'    => json_last_error_msg(),
            'original' => $msg
        ]);
    }

    // Emojis/Steuerzeichen aus allen Strings entfernen
    array_walk_recursive($data, function (&$item) {
        if (is_string($item)) {
            $item = preg_replace('/[^\x20-\x7EÄÖÜäöüß]/u', '', $item);
        }
    });

    // Ungültige Keys prüfen
    $allowedKeys = array_keys($expectedSchema['properties']);
    $inputKeys   = array_keys($data);
    $invalidKeys = array_diff($inputKeys, $allowedKeys);
    if (!empty($invalidKeys)) {
        logmsg("ERROR", ['Invalid keys', $invalidKeys]);
        LOGERR("mqtt-subscribe.php: Invalid keys in JSON: " . implode(',', $invalidKeys));
        return sendMqtt($mqtt, $responseTopic, "Invalid keys in JSON", [
            'invalid_keys' => array_values($invalidKeys),
            'original'     => $data
        ]);
    }

    // Schema-Validierung
    $invalid = [];
    $missing = [];
    foreach ($expectedSchema['required'] as $key) {
        if (!array_key_exists($key, $data)) $missing[] = $key;
    }
    foreach ($data as $key => $value) {
        $def = $expectedSchema['properties'][$key] ?? null;
        if (!$def) continue;
        if (!validate_type($value, $def['type'])) $invalid[$key] = "expected " . $def['type'];
    }
    if (!empty($missing) || !empty($invalid)) {
        logmsg("ERROR", ['Invalid or incomplete JSON', 'missing'=>$missing, 'invalid'=>$invalid]);
        LOGERR("mqtt-subscribe.php: Invalid or incomplete JSON. Missing: " . implode(',', $missing));
        return sendMqtt($mqtt, $responseTopic, "Invalid or incomplete JSON", [
            'missing'=>$missing,
            'invalid'=>$invalid,
            'original'=>$data
        ]);
    }

    logmsg("OK", "Valid JSON received. Processing TTS request...");
    LOGOK("mqtt-subscribe.php: Valid JSON received. Processing TTS request...");
    createMessage($data);
};

/* ============================================================
 * Handshake-Callback (tts-handshake/request/#)
 * ============================================================ */
$handshakeCb = function (string $topic, string $msg) use ($mqtt) {
    // Kein zweiter Logger – bestehende Helfer nutzen:
    logmsg("INFO", "Handshake request on [$topic]: $msg");
	if (HANDSHAKE_DEBUG) { LOGDEB("mqtt-subscribe.php: Handshake request received on $topic"); }

    $data = json_decode($msg, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        logmsg("ERROR", ['Invalid handshake JSON', json_last_error_msg()]);
		if (HANDSHAKE_DEBUG) { LOGWARN("mqtt-subscribe.php: Invalid handshake JSON: " . json_last_error_msg()); }
        return;
    }

    if (empty($data['client'])) {
        logmsg("WARNING", "Handshake payload missing 'client'");
		if (HANDSHAKE_DEBUG) { LOGWARN("mqtt-subscribe.php: Handshake payload missing 'client'"); }
        return;
    }

    // Client-Teil für Topic absichern (nur harmlose Zeichen)
    $client = preg_replace('/[^A-Za-z0-9._-]/', '', (string)$data['client']);
    $corr   = isset($data['corr']) ? (string)$data['corr'] : (string)time();

    $replyTopic = "tts-handshake/response/$client";
    $resp = [
        'status'    => 'ok',
        'server'    => (gethostname() ?: 'unknown'),
        'timestamp' => date('c'),
        'corr'      => $corr,
    ];

    $mqtt->publish($replyTopic, json_encode($resp, JSON_UNESCAPED_UNICODE), 0);
    logmsg("OK", "Handshake response sent to [$replyTopic] (corr=$corr)");
	if (HANDSHAKE_DEBUG) { LOGOK("mqtt-subscribe.php: Handshake response sent to $replyTopic (corr=$corr)"); }
};

/* Einmalig abonnieren – TTS + Handshake */
$mqtt->subscribe([
    $subscribeTopic         => ['qos'=>0,'function'=>$callback],
    'tts-handshake/request/#' => ['qos'=>0,'function'=>$handshakeCb],
]);

/* Event-Loop + Reconnect-Handling */
$lastCheck = time();
while ($mqtt->proc()) {
    if (time() - $lastCheck >= 120) {
        if (!$mqtt->ping()) {
            $mqtt->close();
            usleep(500000);
            if (!$mqtt->connect(true, NULL, $user, $pass)) {
                logmsg("ERROR", "Lost connection to MQTT broker. Reconnect failed. Retrying...");
                LOGWARN("mqtt-subscribe.php: Lost connection to MQTT broker. Reconnect failed. Retrying...");
                sleep(5);
                continue;
            }
            // nach Reconnect Topic neu abonnieren
            $mqtt->subscribe([
				$subscribeTopic           => ['qos'=>0,'function'=>$callback],
				'tts-handshake/request/#' => ['qos'=>0,'function'=>$handshakeCb],
			]);

        }
        $lastCheck = time();
    }
    usleep(20000);
}

$mqtt->close();
logmsg("INFO", "MQTT connection closed");
logmsg("END", "End MQTT Handler");
LOGOK("mqtt-subscribe.php: MQTT handler stopped successfully");
LOGEND("Interface");

/* =======================
 * Hilfsfunktionen
 * ======================= */
function validate_type($value, string $type): bool {
    switch ($type) {
        case 'string':  return is_string($value);
        case 'number':  return is_numeric($value);
        case 'boolean': return is_bool($value);
        case 'array':   return is_array($value);
        default:        return false;
    }
}

/* Erzeugt die TTS-Datei und sendet eine JSON-Antwort auf $responseTopic */
function createMessage(array $data) {

    global $config, $t2s_param, $mqtt, $responseTopic, $logArray;

    // Config laden
    $config = LoadConfig();
    if ($config === null) {
        LOGERR('mqtt-subscribe.php: t2s_config.json could not be loaded!');
        return sendMqtt($mqtt, $responseTopic ?? 'tts-subscribe', "Config load failed");
    }

    // Antwort-Topic ausschließlich aus Payload ableiten (kein Subscribe hier!)
    if (!empty($data['reply_to']) && is_string($data['reply_to'])) {
        $responseTopic = $data['reply_to'];
    } else {
        $client   = isset($data['client'])   && is_string($data['client'])   ? $data['client']   : 'text2sip';
        $instance = isset($data['instance']) && is_string($data['instance']) ? $data['instance'] : 'default';
        $corr     = isset($data['corr']) ? (string)$data['corr'] : '';
        $responseTopic = $corr !== ''
            ? "tts-subscribe/$client/$instance/$corr"
            : "tts-subscribe";
    }

    // Pfade/Parameter
    $mp3path     = rtrim($config['SYSTEM']['ttspath'], '/');
    $t2s_param   = GetTTSParameter($config, $data);
    $mp3filename = $t2s_param['filename'] . ".mp3";
    $fullpath    = $mp3path . '/' . $mp3filename;

    clearstatcache(true, $fullpath);

    if (!file_exists($mp3path)) {
        LOGERR("mqtt-subscribe.php: TTS directory not found: $mp3path");
        return sendMqtt($mqtt, $responseTopic, "Directory not found: $mp3path");
    }
    if (!is_writable($mp3path)) {
        LOGERR("mqtt-subscribe.php: TTS directory not writable: $mp3path");
        return sendMqtt($mqtt, $responseTopic, "Directory not writable: $mp3path");
    }

    // Engine wählen
    select_t2s_engine($t2s_param['t2sengine']);

    // Cache/Erzeugung
    $nocache   = isset($data['nocache']) ? (int)$data['nocache'] : 0;
    $minSize   = 1024;
    $result    = false;
    $messresponse = '';

    if ($nocache === 1) {
        logmsg("INFO", "nocache=1 -> re-create MP3: $fullpath");
        LOGDEB("mqtt-subscribe.php: Force MP3 re-creation due to nocache=1.");
        $tmpresult = t2s($t2s_param);

        clearstatcache(true, $fullpath);
        if (!file_exists($fullpath)) {
            LOGERR("mqtt-subscribe.php: File not created by TTS engine: $fullpath");
            logmsg("ERROR", "File not created by TTS engine: $fullpath");
        } elseif (filesize($fullpath) < $minSize) {
            LOGERR("mqtt-subscribe.php: MP3 file too small: ".filesize($fullpath)." bytes");
            logmsg("ERROR", "MP3 file too small: ".filesize($fullpath)." bytes");
        } else {
            $result = true;
            $messresponse = "MP3 file re-created successfully.";
            LOGOK("mqtt-subscribe.php: MP3 file successfully re-created: $mp3filename");
        }

    } elseif (is_file($fullpath)) {
        $messresponse = "MP3 file picked from cache, no need to recreate.";
        $result = true;
        LOGDEB("mqtt-subscribe.php: MP3 file picked from cache: $mp3filename");

    } else {
        logmsg("INFO", "File not found, creating new MP3: $fullpath");
        LOGDEB("mqtt-subscribe.php: File not found. Creating new MP3...");
        $tmpresult = t2s($t2s_param);

        $timeout  = 5; $interval = 100000; $elapsed = 0;
        while ((!is_file($fullpath) || filesize($fullpath)<$minSize) && $elapsed<$timeout*1000000) {
            usleep($interval);
            $elapsed += $interval;
        }

        clearstatcache(true, $fullpath);
        if (!is_file($fullpath) || filesize($fullpath)<$minSize) {
            LOGERR("mqtt-subscribe.php: MP3 creation failed: file missing or too small.");
            logmsg("ERROR", "MP3 creation failed: file missing or too small.");
        } else {
            $messresponse = "MP3 file created successfully.";
            @chmod($fullpath, 0664);
            $result = true;
            LOGOK("mqtt-subscribe.php: MP3 file created successfully: $mp3filename");
        }
    }

    // Antwort publishen (QoS 0, nicht retained)
    if ($result) {
        logmsg("OK", $messresponse . ": $mp3filename");

        $httpiface    = $config['SYSTEM']['httpinterface']    ?? null;
        $httpmp3iface = $config['SYSTEM']['httpmp3interface'] ?? null;

        $finalResponse = [
            'status'            => 'done',
            'message'           => $messresponse,
            'file'              => $mp3filename,

            // Legacy/top-level:
            'httpinterface'     => $httpiface,
            'httpmp3interface'  => $httpmp3iface,
            'cifsinterface'     => $config['SYSTEM']['cifsinterface']    ?? null,
            'ttspath'           => $config['SYSTEM']['ttspath']          ?? null,
            'mp3path'           => $config['SYSTEM']['mp3path']          ?? null,
            'cifsmp3interface'  => $config['SYSTEM']['cifsmp3interface'] ?? null,
            'timestamp'         => date("H:i:s"),

            // Korrelation spiegeln (keine Subscribe-Logik nötig)
            'corr'              => isset($data['corr']) ? (string)$data['corr'] : null,

            // Alternatives Interfaces-Objekt:
            'interfaces'        => [
                'httpinterface'    => $httpiface,
                'httpmp3interface' => $httpmp3iface,
            ],

            // Original-Felder zurückspiegeln (optional hilfreich fürs Matching)
            'original'          => [
                'corr'     => isset($data['corr']) ? (string)$data['corr'] : null,
                'reply_to' => isset($data['reply_to']) ? (string)$data['reply_to'] : null,
                'client'   => isset($data['client']) ? (string)$data['client'] : null,
                'instance' => isset($data['instance']) ? (string)$data['instance'] : null,
            ],
        ];

        if (!empty($data['mp3files']) && (int)$data['mp3files'] === 1) {
            $finalResponse['mp3files'] = getAudioFiles();
        }
        if (!empty($data['logging']) && (int)$data['logging'] === 1) {
            $finalResponse['logs'] = getLogArray();
        }

        $mqtt->publish($responseTopic, json_encode($finalResponse, JSON_UNESCAPED_UNICODE), 0);
        LOGOK("mqtt-subscribe.php: OK response sent on Topic: [$responseTopic]");
    } else {
        LOGERR("mqtt-subscribe.php: MP3 could not be created");
        return sendMqtt($mqtt, $responseTopic, "MP3 could not be created");
    }
}


/* Zentrale MQTT-Error-Response */
function sendMqtt($mqtt, string $topic, string $message, array $details = []) {
    global $logArray;

    $logArray = []; // Reset vor Versand
    $response = [
        'status'    => 'error',
        'message'   => $message,
        'details'   => $details,
        'logs'      => $logArray,
        'timestamp' => date('c'),
    ];

    array_walk_recursive($response, function (&$item) {
        if (is_string($item)) {
            $item = preg_replace('/[^\x20-\x7EÄÖÜäöüß]/u', '', $item);
        }
    });

    logmsg("ERROR", [$message, $details]);
    LOGOK("mqtt-subscribe.php: MQTT Error response sent on Topic: [$topic] - Message: $message");

    $mqtt->publish($topic, json_encode($response, JSON_UNESCAPED_UNICODE), 0);
    return false;
}

/* MP3-Dateiliste */
function getAudioFiles(): array {
    global $config;
    $mp3Dir = $config['SYSTEM']['mp3path'] ?? '';
    $filesArray = [];
    if ($mp3Dir && is_dir($mp3Dir)) {
        foreach (scandir($mp3Dir) as $file) {
            if ($file === '.' || $file === '..') continue;
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (is_file($mp3Dir.'/'.$file) && in_array($ext, ['mp3','wav'], true)) {
                $filesArray[] = $file;
            }
        }
    }
    return $filesArray;
}

/* Log-Array abrufen */
function getLogArray(): array {
    global $logArray;
    return $logArray;
}
