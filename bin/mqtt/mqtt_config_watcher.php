#!/usr/bin/env php
<?php
/**
 * mqtt_config_watcher.php
 * Überwacht LoxBerry general.json (Block "Mqtt") und schreibt bei Änderungen
 * eine reduzierte JSON-Datei nach /opt/loxberry/data/plugins/text2speech/mqtt/remote_mqtt_config.json
 *
 * Aufruf: php mqtt_config_watcher.php
 */

declare(strict_types=1);

require_once "REPLACELBHOMEDIR/libs/phplib/loxberry_system.php";

$service = "mqtt-config-watcher";
$timestamp = date("d-m-Y H:i:s");


// ==== Konfiguration ====
$SOURCE_FILE = 'REPLACELBHOMEDIR/config/system/general.json'; // <— HIER deinen gemounteten Pfad eintragen
$OUT_DIR     = 'REPLACELBPDATADIR/mqtt';
$OUT_FILE    = $OUT_DIR . '/remote_mqtt_config.json';
$STATE_FILE  = $OUT_DIR . '/.mqtt_config.hash';
$LOGFILE 	 = "REPLACELBPLOGDIR/mqtt-watchdog.log";
$SLEEP_SEC   = 3;                     // Poll-Intervall
$OWNER_USER  = 'loxberry';
$OWNER_GROUP = 'loxberry';

// Logging-Funktion
function logmsg($level, $message) {
    global $LOGFILE, $timestamp;
    $entry = "$timestamp $message\n";
    file_put_contents($LOGFILE, $entry, FILE_APPEND);
}

// Sichere Default-Umask
umask(0007);

// Stelle Zielverzeichnis sicher
if (!is_dir($OUT_DIR) && !mkdir($OUT_DIR, 0770, true) && !is_dir($OUT_DIR)) {
	logmsg("ERROR", "❌ Cannot create directory $OUT_DIR");
    exit(1);
}

function read_json_file(string $path): ?array {
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    // BOM entfernen
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function normalize_mqtt_subset(array $general): array {
    $mqtt = $general['Mqtt'] ?? [];

    // Originalkeys beibehalten; Typen sinnvoll normalisieren
    $out = [
        'Brokerhost'     => $mqtt['Brokerhost']     ?? null,
        'Brokerpass'     => $mqtt['Brokerpass']     ?? null,
        'Brokerport'     => isset($mqtt['Brokerport']) ? (int)$mqtt['Brokerport'] : null,
        'Brokeruser'     => $mqtt['Brokeruser']     ?? null,
        'Finderdisabled' => isset($mqtt['Finderdisabled']) ? (bool)$mqtt['Finderdisabled'] : null,
        'Udpinport'      => isset($mqtt['Udpinport']) ? (string)$mqtt['Udpinport'] : null,
        'Uselocalbroker' => isset($mqtt['Uselocalbroker']) ? (filter_var($mqtt['Uselocalbroker'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool)$mqtt['Uselocalbroker']) : null,
        'Websocketport'  => isset($mqtt['Websocketport']) ? (int)$mqtt['Websocketport'] : null,
    ];

    // Optional: Felder mit null entfernen
    foreach ($out as $k => $v) {
        if ($v === null) unset($out[$k]);
    }

    return $out;
}

function stable_hash(array $payload): string {
    // Stabile Normalisierung für Hash
    ksort($payload);
    return sha1(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function atomic_write_json(string $path, array $data, ?string $ownerUser, ?string $ownerGroup): bool {
    $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0664);
    if ($ownerUser !== null || $ownerGroup !== null) {
        // chown/chgrp nur versuchen, wenn möglich
        @chown($tmp, $ownerUser ?? '');
        @chgrp($tmp, $ownerGroup ?? '');
    }
    return @rename($tmp, $path);
}

function atomic_write_string(string $path, string $data, ?string $ownerUser, ?string $ownerGroup): bool {
    $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $data, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0660);
    if ($ownerUser !== null || $ownerGroup !== null) {
        @chown($tmp, $ownerUser ?? '');
        @chgrp($tmp, $ownerGroup ?? '');
    }
    return @rename($tmp, $path);
}

#echo "[mqtt_config_watcher] Monitoring: $SOURCE_FILE -> $OUT_FILE (every {$SLEEP_SEC}s)\n";
#logmsg("INFO", "✅ Monitoring: $SOURCE_FILE -> $OUT_FILE (every {$SLEEP_SEC}s)");

$prevHash = is_file($STATE_FILE) ? trim((string)@file_get_contents($STATE_FILE)) : '';

while (true) {
    $general = read_json_file($SOURCE_FILE);
    if ($general !== null) {
        $subset = normalize_mqtt_subset($general);
        $hash = stable_hash($subset);

        if ($hash !== $prevHash) {
            // Schreiben
            if (!atomic_write_json($OUT_FILE, $subset, $OWNER_USER, $OWNER_GROUP)) {
				logmsg("ERROR", "❌ Failed to write $OUT_FILE");
            } else {
                // Hash aktualisieren
                if (!atomic_write_string($STATE_FILE, $hash . "\n", $OWNER_USER, $OWNER_GROUP)) {
					logmsg("WARN", "⚠️ Failed to update state file $STATE_FILE");
                }
				logmsg("INFO", "✅ Updated remote_mqtt_config.json");
            }
            $prevHash = $hash;
        }
    } else {
        // Quelle (noch) nicht vorhanden/lesbar – schweigend weiterprobieren
    }

    // kleines Debounce / Poll
    sleep($SLEEP_SEC);
}
?>