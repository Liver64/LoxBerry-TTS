#!/usr/bin/env php
<?php
/**
 * mqtt_config_watcher.php
 * Watches LoxBerry general.json (block "Mqtt") and writes a reduced JSON to
 * /opt/loxberry/data/plugins/text2speech/mqtt/remote_mqtt_config.json
 * - First run: always writes once (seed)
 * - Afterwards: writes only on real changes (stable hash)
 */

declare(strict_types=1);

/* ==== Fixed paths ==== */
const SOURCE_FILE = 'REPLACELBPCONFIGDIR/config/system/general.json';
const OUT_DIR     = 'REPLACELBPCONFIGDIR/data/plugins/text2speech/mqtt';
const OUT_FILE    = OUT_DIR . '/remote_mqtt_config.json';
const STATE_FILE  = 'REPLACELBPCONFIGDIR/.mqtt_config.hash';
const LOGFILE     = 'REPLACELBPLOGDIR/mqtt-watchdog.log';
const OWNER_USER  = 'loxberry';
const OWNER_GROUP = 'loxberry';
const SLEEP_SEC   = 3;

/* ==== Logging ==== */
function logmsg(string $level, string $message): void {
    $ts = date('Y-m-d H:i:s');
    @file_put_contents(LOGFILE, "$ts [$level] $message\n", FILE_APPEND);
}

/* ==== Safe umask (group-writable) ==== */
umask(0007);

/* ==== Ensure dirs/files with expected perms ==== */
function ensure_dir(string $dir, int $mode = 0775): void {
    if (!is_dir($dir)) {
        @mkdir($dir, $mode, true);
    }
    @chmod($dir, $mode);
    @chown($dir, OWNER_USER);
    @chgrp($dir, OWNER_GROUP);
}
ensure_dir(OUT_DIR, 0775);
ensure_dir(dirname(STATE_FILE), 0775);
ensure_dir(dirname(LOGFILE), 0775);
@touch(LOGFILE);
@chmod(LOGFILE, 0664);
@chown(LOGFILE, OWNER_USER);
@chgrp(LOGFILE, OWNER_GROUP);

/* ==== Helpers ==== */
function read_json_file(string $path): ?array {
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false) return null;
    // strip UTF-8 BOM if present
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function normalize_mqtt_subset(array $general): array {
    $mqtt = $general['Mqtt'] ?? [];
    $out = [
        'Brokerhost'     => $mqtt['Brokerhost']     ?? null,
        'Brokerpass'     => $mqtt['Brokerpass']     ?? null,
        'Brokerport'     => isset($mqtt['Brokerport']) ? (int)$mqtt['Brokerport'] : null,
        'Brokeruser'     => $mqtt['Brokeruser']     ?? null,
        'Finderdisabled' => isset($mqtt['Finderdisabled']) ? (bool)$mqtt['Finderdisabled'] : null,
        'Udpinport'      => isset($mqtt['Udpinport']) ? (string)$mqtt['Udpinport'] : null,
        'Uselocalbroker' => isset($mqtt['Uselocalbroker'])
                              ? (filter_var($mqtt['Uselocalbroker'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                                 ?? (bool)$mqtt['Uselocalbroker'])
                              : null,
        'Websocketport'  => isset($mqtt['Websocketport']) ? (int)$mqtt['Websocketport'] : null,
    ];
    foreach ($out as $k => $v) {
        if ($v === null) unset($out[$k]);
    }
    return $out;
}

function ksort_recursive(array &$arr): void {
    ksort($arr);
    foreach ($arr as &$v) {
        if (is_array($v)) ksort_recursive($v);
    }
}

function stable_hash(array $payload): string {
    ksort_recursive($payload);
    return sha1(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function atomic_write_json(string $path, array $data): bool {
    $dir = dirname($path);
    if (!is_dir($dir)) return false;
    $tmp  = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    @chmod($tmp, 0664);
    @chown($tmp, OWNER_USER);
    @chgrp($tmp, OWNER_GROUP);
    return @rename($tmp, $path);
}

function atomic_write_string(string $path, string $data): bool {
    $dir = dirname($path);
    if (!is_dir($dir)) return false;
    $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $data, LOCK_EX) === false) return false;
    @chmod($tmp, 0664);
    @chown($tmp, OWNER_USER);
    @chgrp($tmp, OWNER_GROUP);
    return @rename($tmp, $path);
}

/* ==== Main loop ==== */
$prevHash = is_file(STATE_FILE) ? trim((string)@file_get_contents(STATE_FILE)) : '';
$firstRun = !is_file(OUT_FILE); // seed once if file missing

while (true) {
    $general = read_json_file(SOURCE_FILE);
    if ($general !== null) {
        $subset = normalize_mqtt_subset($general);
        $hash   = stable_hash($subset);

        if ($firstRun || $hash !== $prevHash) {
            if (!atomic_write_json(OUT_FILE, $subset)) {
                logmsg('ERROR', "Failed to write " . OUT_FILE);
            } else {
                if (!atomic_write_string(STATE_FILE, $hash . "\n")) {
                    logmsg('WARN', "Failed to update state file " . STATE_FILE);
                }
                logmsg('INFO', $firstRun ? 'Initial export done.' : 'Updated remote_mqtt_config.json');
                $prevHash = $hash;
                $firstRun = false;
            }
        }
    }
    sleep(SLEEP_SEC);
}
