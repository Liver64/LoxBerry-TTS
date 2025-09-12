#!/usr/bin/php
<?php
/* mqtt-handler.php – TTS Handler + MQTT-Config Responder */

require_once "/opt/loxberry/libs/phplib/loxberry_system.php";
require_once "/opt/loxberry/libs/phplib/loxberry_io.php";
require_once "/opt/loxberry/libs/phplib/loxberry_log.php";
require_once LBPHTMLDIR . "/bin/helper.php";
require_once LBPHTMLDIR . "/bin/phpmqtt/phpMQTT.php";

use Bluerhinos\phpMQTT;

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

/* =======================
 * Grundkonfiguration
 * ======================= */
$logfile            = LBPLOGDIR . "/mqtt.log";
$InterfaceConfigFile= LBPCONFIGDIR . "/interfaces.json";

$subscribeTopic     = 'tts-publish';     // Steuerkanal: eingehende TTS-JSONs
$responseTopic      = 'tts-subscribe';   // Rückkanal: Handler-Antworten
$configRequestTopic = 'mqtt-config';     // Anfrage-Topic für Broker-Config
$configReplySuffix  = '/response';       // Standard-Reply, falls kein backtopic angegeben

/* LoxBerry Logging (Datei für allgemeine Interface-Logs) */
$params = [
    "name"    => "Interface",
    "filename"=> LBPLOGDIR . "/interface.log",
    "append"  => 1,
    "addtime" => 1,
];
$log = LBLog::newLog($params);

/* Globales Logging für MQTT-Status (eigenes, optionales Logfile) */
$enableLogMsg = true; // true aktiviert zusätzlich $logfile-Ausgaben

/* Plugins prüfen (nur starten, wenn aktiv) */
if (file_exists($InterfaceConfigFile)) {
    $checkArray = json_decode(file_get_contents($InterfaceConfigFile), true);
} else {
    $checkArray = [];
    LOGERR("mqtt-handler.php: interfaces.json not found! No plugins loaded.");
}
$plugins = LBSystem::get_plugins();
$plugincheck = false;
foreach ($plugins as $plugin) {
    $title = $plugin['PLUGINDB_TITLE'] ?? null;
    if (!$title) continue;
    if (in_array($title, $checkArray, true)) $plugincheck = true;
}

$GLOBALS['__last_msg_hash'] = null;
$GLOBALS['__last_msg_time'] = 0;

if ($plugincheck) {
    LOGSTART("Interface");

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
    logmsg("START", "Start MQTT Handler");
    LOGOK("mqtt-handler.php: MQTT Handler started");

    /* =======================
     * MQTT-Verbindung
     * ======================= */
    $creds     = mqtt_connectiondetails();
    $broker    = $creds['brokerhost'] ?? '127.0.0.1';
    $port      = (int)($creds['brokerport'] ?? 1883);
    $user      = $creds['brokeruser'] ?? '';
    $pass      = $creds['brokerpass'] ?? '';
    $client_id = uniqid((gethostname() ?: 'lb') . "_client_");
    $mqtt      = new phpMQTT($broker, $port, $client_id);

    if (!$mqtt->connect(true, NULL, $user, $pass)) {
        logmsg("ERROR", "MQTT connection could not be established. Check broker settings.");
        LOGERR("mqtt-handler.php: MQTT connection could not be established. Check broker settings. Retry in 30 Seconds...");
        sleep(30);
        exit(1);
    }

    logmsg("OK", "MQTT connected – listening to Topics: [$subscribeTopic, $configRequestTopic]");
    LOGINF("mqtt-handler.php: MQTT connected – listening to Topics: [$subscribeTopic, $configRequestTopic]");

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
        ],
        "additionalProperties" => false
    ];

    /* =======================
     * Config-Responder (mqtt-config)
     * ======================= */
    $configCallback = function (string $topic, string $msg) use ($mqtt, $creds, $subscribeTopic, $responseTopic, $configRequestTopic, $configReplySuffix) {
        $backtopic = $configRequestTopic . $configReplySuffix;
        $tag = null;
        if ($msg !== '') {
            $kv = [];
            parse_str($msg, $kv); // z.B. "backtopic=my/reply&tag=req-42"
            if (!empty($kv['backtopic'])) $backtopic = (string)$kv['backtopic'];
            if (isset($kv['tag']))        $tag       = (string)$kv['tag'];
        }
		$myip = LBSystem::get_localip();
        $payload = [
            'ok'     => true,
            'type'   => 'mqtt-config',
            'config' => [
                'host'         => $myip  ?? '127.0.0.1',
                'port'         => (int)($creds['brokerport'] ?? 1883),
                'user'         => $creds['brokeruser']  ?? '',
                'pass'         => $creds['brokerpass']  ?? '',
                'has_password' => !empty($creds['brokerpass']),
                'client'       => gethostname() ?: 'unknown',
                'time'         => date('c'),
                'sendTopic'    => $subscribeTopic,
                'replyTopic'   => $responseTopic,
                'cfgTopic'     => $configRequestTopic,
                'qos'          => 0,
                'retain'       => 1,
            ],
            'tag' => $tag,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $mqtt->publish($backtopic, $json, 0, false);

        // Log ohne Klartext-Passwort
        $dbg = $payload;
        if (!empty($dbg['config']['pass'])) $dbg['config']['pass'] = '***';
        fwrite(STDERR, "[cfg] replied to {$backtopic}: " . json_encode($dbg, JSON_UNESCAPED_UNICODE) . "\n");
    };

    /* =======================
     * TTS-Callback (tts-publish)
     * ======================= */
    $callback = function (string $topic, string $msg) use ($mqtt, $responseTopic, $expectedSchema) {
		global $logArray;
		
		// --- Dedupe-Window: 25s ---
		$now  = time();
		$hash = sha1($msg);
		if ($GLOBALS['__last_msg_hash'] === $hash && ($now - $GLOBALS['__last_msg_time']) < 25) {
			LOGINF("mqtt-handler.php: Duplicate payload within 25s window -> ignored");
			return;
		}
		$GLOBALS['__last_msg_hash'] = $hash;
		$GLOBALS['__last_msg_time'] = $now;

        $logArray = []; // Reset pro Nachricht
        logmsg("INFO", "Payload received from [$topic]: $msg");
        LOGDEB("mqtt-handler.php: Payload received on topic [$topic]: $msg");

        $data = json_decode($msg, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            logmsg("ERROR", ['Invalid JSON received:', json_last_error_msg(), $msg]);
            LOGERR("mqtt-handler.php: Invalid JSON received: " . json_last_error_msg());
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
            LOGERR("mqtt-handler.php: Invalid keys in JSON: " . implode(',', $invalidKeys));
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
            LOGERR("mqtt-handler.php: Invalid or incomplete JSON. Missing: " . implode(',', $missing));
            return sendMqtt($mqtt, $responseTopic, "Invalid or incomplete JSON", [
                'missing'=>$missing,
                'invalid'=>$invalid,
                'original'=>$data
            ]);
        }

        logmsg("OK", "Valid JSON received. Processing TTS request...");
        LOGOK("mqtt-handler.php: Valid JSON received. Processing TTS request...");
        createMessage($data);
    };

    /* Einmalig abonnieren – NACH Definition beider Callbacks */
    $mqtt->subscribe([
        $subscribeTopic     => ['qos'=>0,'function'=>$callback],
        $configRequestTopic => ['qos'=>0,'function'=>$configCallback],
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
                    LOGWARN("mqtt-handler.php: Lost connection to MQTT broker. Reconnect failed. Retrying...");
                    sleep(5);
                    continue;
                }
                // nach Reconnect beide Topics neu abonnieren
                $mqtt->subscribe([
                    $subscribeTopic     => ['qos'=>0,'function'=>$callback],
                    $configRequestTopic => ['qos'=>0,'function'=>$configCallback],
                ]);
            }
            $lastCheck = time();
        }
        usleep(20000);
    }

    $mqtt->close();
    logmsg("INFO", "MQTT connection closed");
    logmsg("END", "End MQTT Handler");
    LOGOK("mqtt-handler.php: MQTT handler stopped successfully");
    LOGEND("Interface");
}

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

    $config = LoadConfig();
    if ($config === null) {
        LOGERR('mqtt-handler.php: t2s_config.json could not be loaded!');
        return sendMqtt($mqtt, $responseTopic, "Config load failed");
    }

    $mp3path    = rtrim($config['SYSTEM']['ttspath'], '/');
    $t2s_param  = GetTTSParameter($config, $data);
    $mp3filename= $t2s_param['filename'] . ".mp3";
    $fullpath   = $mp3path . '/' . $mp3filename;

    clearstatcache(true, $fullpath);

    if (!file_exists($mp3path)) {
        LOGERR("mqtt-handler.php: TTS directory not found: $mp3path");
        return sendMqtt($mqtt, $responseTopic, "Directory not found: $mp3path");
    }
    if (!is_writable($mp3path)) {
        LOGERR("mqtt-handler.php: TTS directory not writable: $mp3path");
        return sendMqtt($mqtt, $responseTopic, "Directory not writable: $mp3path");
    }

    select_t2s_engine($t2s_param['t2sengine']);

    $nocache = isset($data['nocache']) ? (int)$data['nocache'] : 0;
    $minSize = 1024;
    $result  = false;
    $messresponse = '';

    if ($nocache === 1) {
        logmsg("INFO", "nocache=1 -> re-create MP3: $fullpath");
        LOGDEB("mqtt-handler.php: Force MP3 re-creation due to nocache=1.");
        $tmpresult = t2s($t2s_param);

        clearstatcache(true, $fullpath);
        if (!file_exists($fullpath)) {
            LOGERR("mqtt-handler.php: File not created by TTS engine: $fullpath");
            logmsg("ERROR", "File not created by TTS engine: $fullpath");
        } elseif (filesize($fullpath) < $minSize) {
            LOGERR("mqtt-handler.php: MP3 file too small: ".filesize($fullpath)." bytes");
            logmsg("ERROR", "MP3 file too small: ".filesize($fullpath)." bytes");
        } else {
            $result = true;
            $messresponse = "MP3 file re-created successfully.";
            LOGOK("mqtt-handler.php: MP3 file successfully re-created: $mp3filename");
        }

    } elseif (is_file($fullpath)) {
        $messresponse = "MP3 file picked from cache, no need to recreate.";
        $result = true;
        LOGDEB("mqtt-handler.php: MP3 file picked from cache: $mp3filename");

    } else {
        logmsg("INFO", "File not found, creating new MP3: $fullpath");
        LOGDEB("mqtt-handler.php: File not found. Creating new MP3...");
        $tmpresult = t2s($t2s_param);

        $timeout  = 5; $interval = 100000; $elapsed = 0;
        while ((!is_file($fullpath) || filesize($fullpath)<$minSize) && $elapsed<$timeout*1000000) {
            usleep($interval);
            $elapsed += $interval;
        }

        clearstatcache(true, $fullpath);
        if (!is_file($fullpath) || filesize($fullpath)<$minSize) {
            LOGERR("mqtt-handler.php: MP3 creation failed: file missing or too small.");
            logmsg("ERROR", "MP3 creation failed: file missing or too small.");
        } else {
            $messresponse = "MP3 file created successfully.";
            $result = true;
            LOGOK("mqtt-handler.php: MP3 file created successfully: $mp3filename");
        }
    }

    if ($result) {
        logmsg("OK", $messresponse . ": $mp3filename");

        $finalResponse = [
            'status'        => 'done',
            'message'       => $messresponse,
            'file'          => $mp3filename,
            'httpinterface' => $config['SYSTEM']['httpinterface']  ?? null,
            'cifsinterface' => $config['SYSTEM']['cifsinterface']  ?? null,
            'ttspath'       => $config['SYSTEM']['ttspath']        ?? null,
            'mp3path'       => $config['SYSTEM']['mp3path']        ?? null,
            'httpmp3interface'=> $config['SYSTEM']['httpmp3interface'] ?? null,
            'cifsmp3interface'=> $config['SYSTEM']['cifsmp3interface'] ?? null,
            'timestamp'     => date("H:i:s"),
        ];

        if (!empty($data['mp3files']) && (int)$data['mp3files'] === 1) {
            $finalResponse['mp3files'] = getAudioFiles();
        }
        if (!empty($data['logging']) && (int)$data['logging'] === 1) {
            $finalResponse['logs'] = getLogArray();
        }
		#logmsg('ERR', ['response' => $finalResponse]);
        $mqtt->publish($responseTopic, json_encode($finalResponse, JSON_UNESCAPED_UNICODE), 0);
        LOGOK("mqtt-handler.php: OK response sent on Topic: [$responseTopic]");
    } else {
        LOGERR("mqtt-handler.php: MP3 could not be created");
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
    LOGOK("mqtt-handler.php: MQTT Error response sent on Topic: [$topic] - Message: $message");

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
