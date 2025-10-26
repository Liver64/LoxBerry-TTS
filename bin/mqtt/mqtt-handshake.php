#!/usr/bin/php
<?php
/*
 * mqtt_handshake.php — Dedicated T2S handshake listener
 * Version: 1.0.4 (I/O-minimized)
 * Author: Oliver Lewald / ChatGPT
 *
 * Purpose:
 *   Listens to "tts-handshake/request" (lokal/remote gemäß LoxBerry-Setup).
 *   Persistiert unter /etc/mosquitto/tts-role/clients/<client>/<hostname>.json
 *   Sendet Ack nach "tts-handshake/response/<client>/<hostname>".
 *
 *   NEU: Minimierte I/O — schreibt NUR bei Änderung von client ODER version.
 *        Timestamp wird NICHT für die Änderungsprüfung verwendet (nur RAM).
 */

require_once "REPLACELBHOMEDIR/libs/phplib/loxberry_system.php";
require_once "REPLACELBHOMEDIR/libs/phplib/loxberry_io.php";
require_once "REPLACELBHOMEDIR/libs/phplib/loxberry_log.php";
require_once "REPLACELBHOMEDIR/webfrontend/html/plugins/text2speech/bin/phpmqtt/phpMQTT.php";

use Bluerhinos\phpMQTT;

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

/* =======================
 * Configuration
 * ======================= */
$CLIENTS_DIR  = "/etc/mosquitto/tts-role/clients";
$TOPIC_REQ    = "tts-handshake/request";

/* =======================
 * Get MQTT connection details from LoxBerry
 * ======================= */
$creds = mqtt_connectiondetails();
if (!$creds || empty($creds['brokerhost']) || empty($creds['brokerport'])) {
	error_log("FATAL: Could not retrieve MQTT connection details from LoxBerry.");
	exit(1);
}
$client_id = uniqid(gethostname() . "_handshake_");
$mqtt_host = $creds['brokerhost'];
$mqtt_port = (int)$creds['brokerport'];
$mqtt_user = $creds['brokeruser'] ?? null;
$mqtt_pass = $creds['brokerpass'] ?? null;

/* =======================
 * Connect to MQTT Broker
 * ======================= */
$mqtt = new phpMQTT($mqtt_host, $mqtt_port, $client_id);
if (!$mqtt->connect(true, NULL, $mqtt_user, $mqtt_pass)) {
	error_log("ERROR: MQTT connection failed ($mqtt_host:$mqtt_port)");
	exit(1);
}
error_log("INFO: MQTT connected to $mqtt_host:$mqtt_port – listening on [$TOPIC_REQ]");

/* =======================
 * RAM-Cache & Helpers
 * ======================= */

// Prozess-lokaler Cache: key => ['client'=>..., 'version'=>..., 'last_seen'=>...]
static $HS_CACHE = [];

/**
 * Key ableiten: <client>/<identifier>
 */
function hs_key(string $client, string $identifier): string {
	return strtolower(trim($client)) . '/' . strtolower(trim($identifier));
}

/**
 * Bestehende Datei (falls vorhanden) einmalig laden, um unnötige Writes zu vermeiden.
 * Rückgabe: Array der persistierten Daten oder null
 */
function hs_load_existing(string $file): ?array {
	if (!is_readable($file)) return null;
	$json = @file_get_contents($file);
	if ($json === false) return null;
	$data = json_decode($json, true);
	return is_array($data) ? $data : null;
}

/**
 * Atomar JSON schreiben (tmp + rename).
 */
function hs_atomic_write(string $file, array $data): bool {
	$dir = dirname($file);
	if (!is_dir($dir)) {
		if (!@mkdir($dir, 0775, true)) {
			error_log("ERR: Failed to create directory $dir");
			return false;
		}
	}
	$tmp = $file . ".tmp";
	$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if (@file_put_contents($tmp, $json) === false) {
		error_log("ERR: Cannot write temp file $tmp");
		return false;
	}
	if (!@rename($tmp, $file)) {
		error_log("ERR: Failed to finalize file $file");
		@unlink($tmp);
		return false;
	}
	return true;
}

/* =======================
 * Handshake Handler (I/O-minimized)
 * ======================= */
function onHandshake($topic, $payload)
{
	global $CLIENTS_DIR, $mqtt, $HS_CACHE;

	$data = json_decode((string)$payload, true);
	if (!is_array($data)) {
		error_log("WARN: Handshake received invalid JSON on $topic");
		return;
	}

	$client   = strtolower(trim($data['client']   ?? ''));
	$version  = trim($data['version']  ?? '');
	$hostname = strtolower(trim($data['hostname'] ?? ''));
	$ip       = trim($data['ip']       ?? '');
	$ts       = trim($data['timestamp']?? ''); // wird für die *Entscheidung* ignoriert

	if (!$client || !$version || !$ts || (!$hostname && !$ip)) {
		error_log("WARN: Handshake missing required fields: " . json_encode($data));
		return;
	}

	$identifier = $hostname ?: $ip;
	$dir  = "$CLIENTS_DIR/$client";
	$file = "$dir/$identifier.json";
	$key  = hs_key($client, $identifier);

	// RAM: last_seen aktualisieren
	$now = time();
	$prev_ram = $HS_CACHE[$key] ?? null;

	// Bei erster Sichtung: einmalig von Disk laden, um unnötigen Write zu vermeiden
	$prev_disk = null;
	if ($prev_ram === null) {
		$prev_disk = hs_load_existing($file);
		if ($prev_disk && is_array($prev_disk)) {
			$HS_CACHE[$key] = [
				'client'    => (string)($prev_disk['client']  ?? $client),
				'version'   => (string)($prev_disk['version'] ?? $version),
				'last_seen' => $now,
			];
			$prev_ram = $HS_CACHE[$key];
		}
	}

	// Wenn immer noch kein RAM-Entry: minimalen Startzustand anlegen
	if ($prev_ram === null) {
		$HS_CACHE[$key] = [
			'client'    => $client,
			'version'   => $version, // wird unten ggf. überschrieben, aber ok
			'last_seen' => $now,
		];
		$prev_ram = $HS_CACHE[$key];
	} else {
		$HS_CACHE[$key]['last_seen'] = $now; // Timestamp bleibt RAM-only
	}

	// **Signifikante Änderung nur über client/version**
	$prev_client  = (string)($prev_ram['client']  ?? $client);
	$prev_version = (string)($prev_ram['version'] ?? $version);

	$changed = ($prev_client !== $client) || ($prev_version !== $version);

	// Persistiere NUR bei Änderung (timestamp bleibt unberücksichtigt)
	if ($changed) {
		$entry = [
			"client"    => $client,
			"version"   => $version,
			"hostname"  => $hostname,
			"ip"        => $ip,
			"timestamp" => $ts,              // darf weiterhin gespeichert werden
			"status"    => "handshake_ok"
		];

		if (hs_atomic_write($file, $entry)) {
			// RAM-Cache an den neuen Zustand anpassen
			$HS_CACHE[$key]['client']  = $client;
			$HS_CACHE[$key]['version'] = $version;
			error_log("OK: Handshake stored (changed) at $file");
		} else {
			error_log("ERR: Failed to persist handshake at $file");
			// trotzdem ack senden – der Client muss nicht erneut senden
		}
	} else {
		// Keine I/O bei unverändertem client/version
		error_log("INFO: Handshake unchanged for $key (no disk write)");
	}

	// Ack antworten (immer)
	$responseTopic = "tts-handshake/response/$client/$identifier";
	$respPayload = json_encode([
		"status"      => "done",
		"server_time" => gmdate("Y-m-d\TH:i:s\Z")
	]);
	$mqtt->publish($responseTopic, $respPayload, 0, false);
	error_log("OK: Handshake response sent to $responseTopic");
}

/* =======================
 * Main Loop
 * ======================= */
$mqtt->subscribe([
	$TOPIC_REQ => ['qos' => 0, 'function' => 'onHandshake']
]);

while ($mqtt->proc()) {
	usleep(100000);
}

$mqtt->close();
error_log("INFO: MQTT handshake listener stopped.");
?>
