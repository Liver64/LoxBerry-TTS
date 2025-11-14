#!/usr/bin/php
<?php
/**
 * mqtt-handshake-listener.php – Permanent MQTT listener for handshake requests
 * Text2Speech (T2S) Master / LoxBerry environment
 *
 * Version: 2.1
 * - FULL AUTO-ACL MARKER SUPPORT
 * - Inserts between ### BEGIN AUTO-ACL ### and ### END AUTO-ACL ###
 * - Duplicate detection
 * - Clean logging (RAM + symlink)
 */

require_once "/opt/loxberry/libs/phplib/loxberry_system.php";
require_once "/opt/loxberry/libs/phplib/loxberry_io.php";
require_once "/opt/loxberry/webfrontend/html/plugins/text2speech/bin/phpmqtt/phpMQTT.php";

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
$stdlog = "/opt/loxberry/log/plugins/text2speech/handshake-listener.log";

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

/* Connect loop */
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
    return strpos($acl, "user $userBlockId") !== false;
}

function build_acl_block(string $client, string $hostname): string {
    return
"# Auto-added SIP Bridge ($hostname)
user {$client}-{$hostname}
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
        logmsg("ERROR", "AUTO-ACL markers not found in ACL file!");
        return $acl;
    }

    // Bereich: vor dem Markerblock
    $before = substr($acl, 0, $startPos + strlen($begin));

    // Bereich: der Inhalt NACH dem Begin-Marker, VOR dem End-Marker
    $middle = substr(
        $acl,
        $startPos + strlen($begin),
        $endPos - ($startPos + strlen($begin))
    );

    // Bereich: inklusive dem End-Marker und alles dahinter
    $after = substr($acl, $endPos);

    // Neues ACL zusammenbauen
    return
        rtrim($before) . "\n" .
        rtrim($middle) . "\n" .
        $block . "\n" .
        $after;
}


function write_aclfile(string $content): void {
    $bytes = @file_put_contents(ACLFILE, $content);
    if ($bytes === false) {
        logmsg("ERROR", "Failed to write ACL file " . ACLFILE . " – check file permissions (user loxberry).");
        return;
    }
    @chmod(ACLFILE, 0644);
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
    $response = [
        'status'    => 'ok',
        'server'    => gethostname(),
        'timestamp' => date('c'),
        'corr'      => $corr,
    ];

    ob_start();
    $mqtt->publish($replyTopic, json_encode($response), 1);
    ob_end_clean();

    logmsg("OK", "Response sent corr=$corr");

    /* ---------------------------------------------------------
     * AUTO-ACL Handling
     * --------------------------------------------------------- */
    $acl = get_acl_text();
    $blockUser = "{$client}-{$hostname}";

    if (acl_contains_user($acl, $blockUser)) {
        logmsg("INFO", "Auto-ACL for [$blockUser] already exists");
    } else {
        logmsg("INFO", "Auto-ACL inserting [$blockUser] ($hostname)");

        $block = build_acl_block($client, $hostname);
        $newAcl = insert_into_marker($acl, $block);

        write_aclfile($newAcl);
        logmsg("OK", "Auto-ACL entry added for [$blockUser]");

        /* reload mosquitto */
        system("sudo systemctl reload mosquitto");
        logmsg("OK", "Mosquitto reloaded");
    }

    /* Update health.json */
    $healthFile = "/dev/shm/text2speech/health.json";
    $health = [];

    if (is_readable($healthFile)) {
        $tmp = json_decode(file_get_contents($healthFile), true);
        if (is_array($tmp)) $health = $tmp;
    }

    $health["{$client}-{$hostname}"] = [
        'client'    => $client,
        'hostname'  => $hostname,
        'corr'      => $corr,
        'timestamp' => time(),
        'iso_time'  => date('c'),
        'server'    => gethostname(),
    ];

    file_put_contents($healthFile, json_encode($health, JSON_PRETTY_PRINT));
    chmod($healthFile, 0664);
    logmsg("INFO", "Health updated for [$client]");
};

/* =====================================================
 * Subscribe and loop forever
 * ===================================================== */
$mqtt->subscribe([ HANDSHAKE_TOPIC => ['qos' => 0, 'function' => $callback] ]);
logmsg("INFO", "Subscribed to " . HANDSHAKE_TOPIC);

while (true) {
    if (!$mqtt->proc()) {
        logmsg("WARN", "MQTT lost — reconnecting …");
        while (!$mqtt->connect(true, NULL, $user, $pass)) {
            sleep(5);
        }
        $mqtt->subscribe([ HANDSHAKE_TOPIC => ['qos' => 0, 'function' => $callback] ]);
        logmsg("OK", "Reconnected");
    }

    usleep(20000);
}
