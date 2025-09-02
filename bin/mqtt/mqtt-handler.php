#!/usr/bin/php
<?php

require_once "/opt/loxberry/webfrontend/html/plugins/text2speech/bin/phpmqtt/phpMQTT.php";
require_once "/opt/loxberry/libs/phplib/loxberry_system.php";
require_once "/opt/loxberry/libs/phplib/loxberry_io.php";

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Logdatei definieren
$logfile = "/opt/loxberry/log/plugins/text2speech/mqtt.log";
$InterfaceConfigFile 	= "/opt/loxberry/config/plugins/text2speech/interfaces.json";

// Logging-Funktion
function logmsg($level, $message) {
    global $logfile;
    $timestamp = date("Y-m-d H:i:s");
    $entry = "$timestamp $message\n";
    file_put_contents($logfile, $entry, FILE_APPEND);
}

// Startmeldung
logmsg("START", "✅ Start MQTT Handler");

# check if Interface plugins are installed
if (file_exists($InterfaceConfigFile))  {
	$checkArray = json_decode(file_get_contents($InterfaceConfigFile), TRUE);
}

$plugins = LBSystem::get_plugins();
$plugincheck = false;

foreach ($plugins as $plugin) {
    $title = $plugin['PLUGINDB_TITLE'];
    if (!$title) {
        continue;
    }
    // Prüfen, ob Titel in der Liste steht
    if (in_array($title, $checkArray, true)) {
        $plugincheck = true;
    }
}
if ($plugincheck === true)  {
	$creds = mqtt_connectiondetails();
	$client_id = uniqid(gethostname() . "_client");

	$subscribeTopic = 'tts-interface';
	$responseTopic  = 'tts-response';

	// MQTT-Verbindung aufbauen
	$mqtt = new Bluerhinos\phpMQTT($creds['brokerhost'], $creds['brokerport'], $client_id);

	if (!$mqtt->connect(true, NULL, $creds['brokeruser'], $creds['brokerpass'])) {
		logmsg("ERROR", "❌ MQTT-Connection failed – Script is still running");
		sleep(30);
		return;
	}

	logmsg("OK", "✅ MQTT connected – listening to [$subscribeTopic]");

	// Callback-Funktion
	$callback = function ($topic, $msg) use ($mqtt, $responseTopic) {
		logmsg("INFO", "📩 Message received from [$topic]: $msg");

		$data = json_decode($msg, true);
		if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
			$error = json_last_error_msg();
			logmsg("WARN", "❌ Invalid JSON received");
			logmsg("WARN", "🔍 JSON error: $error");

			$response = [
				'status'    => 'error',
				'message'   => 'Invalid JSON syntax',
				'details'   => $error,
				'original'  => $msg,
				'timestamp' => date('c')
			];

			$mqtt->publish($responseTopic, json_encode($response), 0);
			logmsg("INFO", "📤 Syntax error callback sent to [$responseTopic]");
			return;
		}

		logmsg("INFO", "🔍 Received keys: " . implode(', ', array_keys($data)));

		// Schema-Definition
		$expectedSchema = [
		'type'   => 'string',
		'device' => 'string',
		'value'  => 'numeric'
		];

		$validation = validate_json_schema($data, $expectedSchema);
		$missingKeys = $validation['missing'];
		$invalidTypes = $validation['invalid'];

		if (!empty($missingKeys) || !empty($invalidTypes)) {
			logmsg("WARN", "⚠️ JSON validation failed. Missing: " . implode(', ', $missingKeys) . " | Invalid: " . implode(', ', array_keys($invalidTypes)));

			$response = [
				'status'    => 'error',
				'message'   => 'Invalid or incomplete JSON',
				'missing'   => $missingKeys,
				'invalid'   => $invalidTypes,
				'original'  => $data,
				'timestamp' => date('c')
			];

			$mqtt->publish($responseTopic, json_encode($response), 0);
			logmsg("INFO", "📤 Validation error callback sent to [$responseTopic]");
			return;
		}

		$response = [
			'status'    => 'received',
			'original'  => $data,
			'timestamp' => date('c')
		];

		$responseJson = json_encode($response);
		$mqtt->publish($responseTopic, $responseJson, 0);
		logmsg("OK", "✅ Valid JSON received");
		logmsg("INFO", "📤 Callback sent to [$responseTopic]: $responseJson");
	};

	// Topic abonnieren
	$mqtt->subscribe([
		$subscribeTopic => ['qos' => 0, 'function' => $callback]
	]);

	// Verbindung regelmäßig prüfen
	$lastCheck = time();
	while ($mqtt->proc()) {
		if (time() - $lastCheck >= 120) {
			if (!$mqtt->ping()) {
				$mqtt->close();
				sleep(5);
				if (!$mqtt->connect(true, NULL, $creds['brokeruser'], $creds['brokerpass'])) {
					logmsg("ERROR", "❌ Lost connection, reconnect failed. Will retry...");
					sleep(30);
					continue;
				}
				$mqtt->subscribe([
					$subscribeTopic => ['qos' => 0, 'function' => $callback]
				]);
			}
			$lastCheck = time();
		}
	}

	$mqtt->close();
	logmsg("INFO", "🔌 MQTT-Connection closed");
	logmsg("END", "End MQTT Flat Handler");
}

function validate_json_schema(array $data, array $schema): array {
    $missing = array_diff(array_keys($schema), array_keys($data));
    $invalid = [];

    foreach ($schema as $key => $type) {
        if (!array_key_exists($key, $data)) {
            continue;
        }

        $value = $data[$key];

        if ($type === 'string' && !is_string($value)) {
            $invalid[$key] = 'expected string';
        } elseif ($type === 'numeric' && !is_numeric($value)) {
            $invalid[$key] = 'expected numeric';
        } elseif ($type === 'boolean' && !is_bool($value)) {
            $invalid[$key] = 'expected boolean';
        } elseif ($type === 'array' && !is_array($value)) {
            $invalid[$key] = 'expected array';
        }
    }

    return ['missing' => $missing, 'invalid' => $invalid];
}

?>