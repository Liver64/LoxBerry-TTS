#!/usr/bin/php
<?php

require_once "/opt/loxberry/libs/phplib/loxberry_system.php";
require_once "/opt/loxberry/libs/phplib/loxberry_io.php";
require_once "/opt/loxberry/libs/phplib/loxberry_log.php";
require_once LBPHTMLDIR."/bin/helper.php";
require_once LBPHTMLDIR."/bin/phpmqtt/phpMQTT.php";

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// =======================
// Konfiguration
// =======================
$logfile = LBPLOGDIR."/mqtt.log";
$InterfaceConfigFile = LBPCONFIGDIR."/interfaces.json";

// =======================
// Loxberry Logging Konfiguration
// =======================
$params = [
    "name" => "Interface",
    "filename" => LBPLOGDIR."/interface.log",
    "append" => 1,
    "addtime" => 1,
];
$log = LBLog::newLog($params);

LOGSTART("Interface");

// =======================
// Globales Log-Array
// =======================
$logArray = [];

// =======================
// Logging Funktion (MQTT + eigenes Logfile)
// =======================
function logmsg($level, $message) {
    global $logfile, $logArray;

    // Emoji je Level
    $emojiMap = [
        'START'  => '🛑',
        'END'    => '🛑',
        'OK'     => '✅',
        'ERROR'  => '❌',
        'INFO'   => 'ℹ️',
        'UPDATE' => '🔄'
    ];
    $emoji = $emojiMap[$level] ?? '';

    $timestamp = date("Y-m-d H:i:s");
    $entry = "$timestamp $emoji";

    if (is_array($message)) {
        $entry .= json_encode($message, JSON_UNESCAPED_UNICODE);
    } else {
        $entry .= $message;
    }
    $entry .= "\n";

    if (!is_dir(dirname($logfile))) mkdir(dirname($logfile), 0775, true);
    file_put_contents($logfile, mb_convert_encoding($entry, "UTF-8", "auto"), FILE_APPEND | LOCK_EX);

    // Für MQTT Payload ohne Emojis
    $cleanMessage = $message;
    if (is_array($message)) {
        $parts = [];
        foreach ($message as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $parts[] = "$k: $v";
            } else {
                $parts[] = "$k: " . json_encode($v, JSON_UNESCAPED_UNICODE);
            }
        }
        $cleanMessage = implode(', ', $parts);
    } else {
        $cleanMessage = preg_replace('/[^\x20-\x7EÄÖÜäöüß]/u', '', $cleanMessage);
    }
    $cleanMessage = preg_replace('/\s+/', ' ', $cleanMessage);

    $logArray[] = "<$level> $cleanMessage";
}

// =======================
// Startmeldung
// =======================
logmsg("START", "Start MQTT Handler");
LOGOK("mqtt-handler.php: MQTT Handler started");

// =======================
// Plugins prüfen
// =======================
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

// =======================
// MQTT Setup
// =======================
if ($plugincheck) {
    $creds = mqtt_connectiondetails();
    $client_id = uniqid(gethostname() . "_client");
    $subscribeTopic = 'tts-interface';
    $responseTopic  = 'tts-response';

    $mqtt = new Bluerhinos\phpMQTT($creds['brokerhost'], $creds['brokerport'], $client_id);

    if (!$mqtt->connect(true, NULL, $creds['brokeruser'], $creds['brokerpass'])) {
        logmsg("ERROR", "MQTT connection could not be established. Check broker settings.");
        LOGERR("mqtt-handler.php: MQTT connection could not be established. Check broker settings.");
        sleep(30);
        return;
    }

    logmsg("OK", "MQTT connected – listening to Topic: [$subscribeTopic]");
    LOGINF("mqtt-handler.php: MQTT connected – listening to Topic: [$subscribeTopic]");

    // =======================
    // Schema Definition
    // =======================
    $expectedSchema = [
        "type" => "object",
        "required" => ["text"],
        "properties" => [
            "text" => ["type" => "string"],
            "nocache" => ["type" => "number"],
            "logfile" => ["type" => "string"],
            "logfilepath" => ["type" => "string"],
            "plugintitle" => ["type" => "string"],
            "optional" => ["type" => "string"]
        ],
        "additionalProperties" => false
    ];

    // =======================
    // MQTT Callback
    // =======================
    $callback = function ($topic, $msg) use ($mqtt, $responseTopic, $expectedSchema) {
        global $logArray;

        $logArray = []; // Reset before each new publish
        logmsg("INFO", "Message received from [$topic]: $msg");
        LOGINF("mqtt-handler.php: Message received on topic [$topic]: $msg");

        $data = json_decode($msg, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            logmsg("ERROR", ['Invalid JSON received:', json_last_error_msg(), $msg]);
            LOGERR("mqtt-handler.php: Invalid JSON received: " . json_last_error_msg());
            return sendMqtt($mqtt, $responseTopic, "Invalid JSON syntax", [
                'error' => json_last_error_msg(),
                'original' => $msg
            ]);
        }

        // Emojis aus allen Strings entfernen
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
            LOGERR("mqtt-handler.php: Invalid keys found in JSON payload: " . implode(',', $invalidKeys));
            return sendMqtt($mqtt, $responseTopic, "Invalid keys in JSON", [
                'invalid_keys' => array_values($invalidKeys),
                'original' => $data
            ]);
        }

        // Schema Validierung
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
            LOGERR("mqtt-handler.php: Invalid or incomplete JSON received. Missing: " . implode(',', $missing));
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

    $mqtt->subscribe([$subscribeTopic => ['qos'=>0,'function'=>$callback]]);

    $lastCheck = time();
    while ($mqtt->proc()) {
        if (time() - $lastCheck >= 120) {
            if (!$mqtt->ping()) {
                $mqtt->close();
                sleep(5);
                if (!$mqtt->connect(true,NULL,$creds['brokeruser'],$creds['brokerpass'])) {
                    logmsg("ERROR", "Lost connection to MQTT broker. Reconnect failed. Retrying...");
                    LOGERR("mqtt-handler.php: Lost connection to MQTT broker. Reconnect failed. Retrying...");
                    sleep(30);
                    continue;
                }
                $mqtt->subscribe([$subscribeTopic => ['qos'=>0,'function'=>$callback]]);
            }
            $lastCheck = time();
        }
    }

    $mqtt->close(); 
    logmsg("INFO", "MQTT connection closed");
    logmsg("END", "End MQTT Handler");
    LOGOK("mqtt-handler.php: MQTT handler stopped successfully");
	LOGEND("Interface");
}

// =======================
// Typprüfung Funktion
// =======================
function validate_type($value, string $type): bool {
    switch ($type) {
        case 'string': return is_string($value);
        case 'number': return is_numeric($value);
        case 'boolean': return is_bool($value);
        case 'array': return is_array($value);
        default: return false;
    }
}

// =======================
// Create Message / MP3
// =======================
function createMessage(array $data) {
    global $config, $t2s_param, $mqtt, $timestamp, $responseTopic, $logArray;

    $config = LoadConfig();
    if ($config === null) {
        LOGERR('mqtt-handler.php: t2s_config.json could not be loaded!');
        return sendMqtt($mqtt, $responseTopic, "Config load failed");
    }

    $mp3path = rtrim($config['SYSTEM']['ttspath'], '/');
    $t2s_param = GetTTSParameter($config, $data);
    $mp3filename = $t2s_param['filename'] . ".mp3";
    $fullpath = $mp3path . '/' . $mp3filename;

    clearstatcache(true, $fullpath);

    // Pfad prüfen
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
    $result = false;

    if ($nocache === 1) {
        logmsg("INFO", "Parameter 'nocache' received, force MP3 re-creation: $fullpath");
        LOGINF("mqtt-handler.php: Force MP3 re-creation due to nocache parameter.");
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
        LOGINF("mqtt-handler.php: MP3 file picked from cache: $mp3filename");
    } else {
        logmsg("INFO", "File not found, creating new MP3: $fullpath");
        LOGINF("mqtt-handler.php: File not found. Creating new MP3...");
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
        $audioFiles = getAudioFiles();
        $finalResponse = [
            'status'        => 'done',
            'message'       => $messresponse,
            'file'          => $mp3filename,
            'httpinterface' => $config['SYSTEM']['httpinterface'] ?? null,
            'cifsinterface' => $config['SYSTEM']['cifsinterface'] ?? null,
            'ttspath'       => $config['SYSTEM']['ttspath'] ?? null,
            'mp3path'       => $config['SYSTEM']['mp3path'] ?? null,
            'mp3files'      => $audioFiles,
            'logs'          => getLogArray(),
            'timestamp'     => date("Y-m-d H:i:s")
        ];
        $mqtt->publish($responseTopic, json_encode($finalResponse, JSON_UNESCAPED_UNICODE), 0);
		LOGOK("mqtt-handler.php: OK response send on Topic: [tts-response]");
    } else {
		LOGERR("mqtt-handler.php: MP3 could not be created");
        return sendMqtt($mqtt, $responseTopic, "MP3 could not be created");
    }
}

// =======================
// Zentrale MQTT-Error-Funktion
// =======================
function sendMqtt($mqtt, string $topic, string $message, array $details = []) {
    global $logArray;

    $logArray = []; // Reset before publish

    $response = [
        'status'    => 'error',
        'message'   => $message,
        'details'   => $details,
        'logs'      => $logArray,
        'timestamp' => date('c')
    ];

    array_walk_recursive($response, function (&$item) {
        if (is_string($item)) {
            $item = preg_replace('/[^\x20-\x7EÄÖÜäöüß]/u', '', $item);
        }
    });

    logmsg("ERROR", [$message, $details]);
    LOGOK("mqtt-handler.php: MQTT Error response sent on Topic: [tts-response] - Message: $message");

    $mqtt->publish($topic, json_encode($response, JSON_UNESCAPED_UNICODE), 0);

    return false;
}

// =======================
// MP3 Dateien auslesen
// =======================
function getAudioFiles(): array {
    global $config;
    $mp3Dir = $config['SYSTEM']['mp3path'];
    $filesArray = [];
    if (is_dir($mp3Dir)) {
        foreach (scandir($mp3Dir) as $file) {
            if ($file === '.' || $file === '..') continue;
            if (is_file($mp3Dir.'/'.$file) && in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['mp3','wav'])) {
                $filesArray[] = $file;
            }
        }
    }
    return $filesArray;
}

// =======================
// Log-Array abrufen
// =======================
function getLogArray(): array {
    global $logArray;
    return $logArray;
}
