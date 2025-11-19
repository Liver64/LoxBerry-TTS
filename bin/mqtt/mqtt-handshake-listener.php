#!/usr/bin/php
<?php
/**
 * mqtt-handshake-listener.php – Permanent MQTT listener for handshake requests
 * Text2Speech (T2S) Master / LoxBerry environment
 *
 * Version: 2.4
 * - FULL AUTO-ACL MARKER SUPPORT
 * - Inserts between ### BEGIN AUTO-ACL ### and ### END AUTO-ACL ###
 * - Duplicate detection
 * - Clean logging (RAM + symlink)
 * - QoS 1 subscribe for robust delivery
 * - Auto-Reconnect after mosquitto reloads
 * - ACL-SYNC FULL (keine Desyncs mehr zwischen health.json und ACL)
 */

require_once "REPLACELBHOMEDIR/libs/phplib/loxberry_system.php";
require_once "REPLACELBHOMEDIR/libs/phplib/loxberry_io.php";
require_once "REPLACELBHOMEDIR/webfrontend/html/plugins/text2speech/bin/phpmqtt/phpMQTT.php";

use Bluerhinos\phpMQTT;

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE);

/* =====================================================
 * Constants
 * ===================================================== */
const HANDSHAKE_TOPIC = 'tts-handshake/request/#';
const ACLFILE         = '/etc/mosquitto/tts-aclfile';
const HANDSHAKE_DEBUG = false;

/* =====================================================
 * Logging (RAM + Symlink)
 * ===================================================== */
$ramlog = "/dev/shm/text2speech/handshake-listener.log";
$stdlog = "REPLACELBHOMEDIR/log/plugins/text2speech/handshake-listener.log";

if (!is_dir(dirname($ramlog))) {
    mkdir(dirname($ramlog), 0775, true);
}
@touch($ramlog);
@chmod($ramlog, 0664);

if (!is_link($stdlog)) {
    @unlink($stdlog);
    @symlink($ramlog, $stdlog);
}

function logmsg(string $level, string $msg): void {
    global $ramlog;
    $ts = date('Y-m-d H:i:s');
    file_put_contents($ramlog, "[$ts] <$level> $msg\n", FILE_APPEND);
}

/* =====================================================
 * MQTT Connection
 * ===================================================== */
$creds = mqtt_connectiondetails();
$host = $creds['brokerhost'] ?? '127.0.0.1';
$port = (int)($creds['brokerport'] ?? 1883);
$user = $creds['brokeruser'] ?? '';
$pass = $creds['brokerpass'] ?? '';
$useTls = ($port != 1883);

$broker = $useTls ? "tls://$host" : $host;

$client_id = uniqid(gethostname() . "_hshake_");
$mqtt = new phpMQTT($broker, $port, $client_id);

if (property_exists($mqtt, 'debug')) {
    $mqtt->debug = false;
}

logmsg("INFO", "Starting MQTT Handshake Listener …");
logmsg("INFO", "Connecting to $host:$port");

while (!$mqtt->connect(true, NULL, $user, $pass)) {
    logmsg("WARN", "MQTT connect failed — retrying in 5s …");
    sleep(5);
}
logmsg("OK", "Connected to MQTT broker (topic=" . HANDSHAKE_TOPIC . ")");

/* =====================================================
 * AUTO-ACL Helper Functions
 * ===================================================== */
function get_acl_text(): string {
    return file_exists(ACLFILE) ? file_get_contents(ACLFILE) : '';
}

function acl_contains_user(string $acl, string $userBlockId): bool {
    return preg_match('/^user\s+' . preg_quote($userBlockId, '/') . '\s*$/m', $acl) === 1;
}

function build_acl_block(string $client, string $hostname): string {
    return
"# Auto-added SIP Bridge ($hostname)
user {$client}
topic write tts-publish/# 
topic read  tts-subscribe/# 
topic write tts-subscribe/# 
topic read  tts-publish/#

";
}

function insert_into_marker(string $acl, string $block): string {
    $begin = "### BEGIN AUTO-ACL ###";
    $end   = "### END AUTO-ACL ###";

    $startPos = strpos($acl, $begin);
    $endPos   = strpos($acl, $end);

    if ($startPos === false || $endPos === false) {
        logmsg("ERROR", "AUTO-ACL markers not found!");
        return $acl;
    }

    $before = substr($acl, 0, $startPos + strlen($begin));
    $middle = substr($acl, $startPos + strlen($begin), $endPos - ($startPos + strlen($begin)));
    $after  = substr($acl, $endPos);

    return
        rtrim($before) . "\n" .
        rtrim($middle) . "\n" .
        $block . "\n" .
        $after;
}

function write_aclfile(string $content): void {
    $ok = @file_put_contents(ACLFILE, $content);
    if ($ok === false) {
        logmsg("ERROR", "Could not write ACL file (permissions?)");
    }
    @chmod(ACLFILE, 0644);
}

/* =====================================================
 * ACL-Sync: ensures that for each health entry, ACL exists
 * ===================================================== */
function acl_sync_if_missing(string $client, string $hostname): void {

    $acl = get_acl_text();
    $blockUser = "{$client}";

    if (acl_contains_user($acl, $blockUser)) {
        return; // nothing to fix
    }

    logmsg("WARN", "ACL-SYNC: Missing ACL block for [$blockUser] — creating now.");

    $block = build_acl_block($client, $hostname);
    $newAcl = insert_into_marker($acl, $block);
    write_aclfile($newAcl);

    system("sudo systemctl reload mosquitto");
    logmsg("OK", "ACL-SYNC: Block added + Mosquitto reloaded");
}

/* =====================================================
 * Callback for incoming handshake
 * ===================================================== */
$callback = function (string $topic, string $msg) use ($mqtt) {

    $payload = json_decode($msg, true);
    if (!is_array($payload)) {
        logmsg("ERROR", "Invalid JSON on $topic");
        return;
    }

    $client   = preg_replace('/[^A-Za-z0-9._-]/', '', (string)($payload['client'] ?? ''));
    $hostname = preg_replace('/[^A-Za-z0-9._-]/', '', (string)($payload['hostname'] ?? 'unknown'));
    $corr     = $payload['corr'] ?? time();

    logmsg("INFO", "Received handshake: client=$client host=$hostname corr=$corr");

    /* Send response */
    $replyTopic = "tts-handshake/response/$client";
    $resp = [
        'status'    => 'ok',
        'server'    => gethostname(),
        'timestamp' => date('c'),
        'corr'      => $corr,
    ];
    $mqtt->publish($replyTopic, json_encode($resp), 1);
    logmsg("OK", "Response sent corr=$corr");

    /* ====== AUTO-ACL INSERT (if new) ====== */
    $acl = get_acl_text();
    $blockUser = "{$client}";

    $inserted = false;

    if (!acl_contains_user($acl, $blockUser)) {
        logmsg("INFO", "Auto-ACL inserting [$blockUser] ($hostname)");

        $block = build_acl_block($client, $hostname);
        $newAcl = insert_into_marker($acl, $block);

        write_aclfile($newAcl);
        logmsg("OK", "Auto-ACL entry added for [$blockUser]");

        system("sudo systemctl reload mosquitto");
        logmsg("OK", "Mosquitto reloaded (new ACL)");
        $inserted = true;
    }

    /* ====== HEALTH UPDATE ====== */
    $healthFile = "/dev/shm/text2speech/health.json";
    $health = [];

    if (is_readable($healthFile)) {
        $tmp = json_decode(file_get_contents($healthFile), true);
        if (is_array($tmp)) $health = $tmp;
    }

    $health[$blockUser] = [
        'client'    => $client,
        'hostname'  => $hostname,
        'corr'      => $corr,
        'timestamp' => time(),
        'iso_time'  => date('c'),
        'server'    => gethostname(),
    ];

    file_put_contents($healthFile, json_encode($health, JSON_PRETTY_PRINT));
    chmod($healthFile, 0664);
    logmsg("INFO", "Health updated for [$blockUser]");

    /* ====== ACL-SYNC (FIX MISSING ACL FOR EXISTING HEALTH) ====== */
    if (!$inserted) {
        acl_sync_if_missing($client, $hostname);
    }
};

/* =====================================================
 * Subscribe & Loop
 * ===================================================== */
$mqtt->subscribe([ HANDSHAKE_TOPIC => ['qos' => 1, 'function' => $callback] ]);
logmsg("INFO", "Subscribed to " . HANDSHAKE_TOPIC . " with QoS=1");

while (true) {
    if (!$mqtt->proc()) {
        logmsg("WARN", "MQTT lost — reconnecting …");
        while (!$mqtt->connect(true, NULL, $user, $pass)) {
            logmsg("WARN", "Reconnect failed — retry in 5s");
            sleep(5);
        }
        $mqtt->subscribe([ HANDSHAKE_TOPIC => ['qos' => 1, 'function' => $callback] ]);
        logmsg("OK", "Reconnected & resubscribed");
    }

    usleep(20000);
}
