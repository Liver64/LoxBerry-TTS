#!/usr/bin/php
<?php
/**
 * mqtt-handshake-listener.php – Permanent MQTT listener for handshake requests
 * Text2Speech (T2S) Master / LoxBerry environment
 * Author: Oliver L.
 * Version: 1.5 (RAM logger, fully sanitized output)
 */

require_once "REPLACELBHOMEDIR/libs/phplib/loxberry_system.php";
require_once "REPLACELBHOMEDIR/libs/phplib/loxberry_io.php";
require_once "REPLACELBHOMEDIR/webfrontend/html/plugins/text2speech/bin/phpmqtt/phpMQTT.php";

use Bluerhinos\phpMQTT;

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE);

const HANDSHAKE_TOPIC = 'tts-handshake/request/#';
const HANDSHAKE_DEBUG = false;

/* =====================================================
 * Simple RAM Logger (/dev/shm) + Symlink to LoxBerry log
 * ===================================================== */
$ramlog = "/dev/shm/text2speech/handshake-listener.log";
$stdlog = "REPLACELBHOMEDIR/log/plugins/text2speech/handshake-listener.log";

if (!is_dir(dirname($ramlog))) {
    mkdir(dirname($ramlog), 0775, true);
}
@touch($ramlog);
@chmod($ramlog, 0664);

if (!is_link($stdlog)) {
    @mkdir(dirname($stdlog), 0775, true);
    @unlink($stdlog);
    @symlink($ramlog, $stdlog);
}

/* --- Logging helper --- */
function logmsg(string $level, string $msg): void {
    global $ramlog;
    $ts = date('Y-m-d H:i:s');
    file_put_contents($ramlog, "[$ts] <$level> $msg\n", FILE_APPEND);
}

/* ======================
 * MQTT connection setup
 * ====================== */
$creds = mqtt_connectiondetails();
$brokerHost = $creds['brokerhost'] ?? $creds['brokeraddress'] ?? '127.0.0.1';
$port       = (int)($creds['brokerport'] ?? 1883);
$useTls     = ($port != 1883);
$broker     = $useTls ? "tls://$brokerHost" : $brokerHost;
$user       = $creds['brokeruser'] ?? $creds['mqttuser'] ?? '';
$pass       = $creds['brokerpass'] ?? $creds['mqttpass'] ?? '';

$client_id  = uniqid((gethostname() ?: 'lb') . "_hshake_");
$mqtt       = new phpMQTT($broker, $port, $client_id);

/* Deactivate any built-in debug */
if (property_exists($mqtt, 'debug')) {
    $mqtt->debug = false;
}

logmsg("INFO", "Starting MQTT Handshake Listener …");
logmsg("INFO", "Connecting to $brokerHost:$port");

$retries = 0;
while (!$mqtt->connect(true, NULL, $user, $pass)) {
    if ($retries % 5 === 0) {
        logmsg("WARN", "MQTT connect failed – retrying in 5s (attempt $retries)");
    }
    $retries++;
    sleep(5);
}
logmsg("OK", "Connected to MQTT broker on $brokerHost:$port (topic=" . HANDSHAKE_TOPIC . ")");

/* ======================
 * Callback for handshake
 * ====================== */
$callback = function (string $topic, string $msg) use ($mqtt) {
    $data = json_decode($msg, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        logmsg("ERROR", "Invalid handshake JSON on $topic: " . json_last_error_msg());
        return;
    }

    if (empty($data['client'])) {
        logmsg("WARN", "Handshake payload missing 'client'");
        return;
    }

    $client = preg_replace('/[^A-Za-z0-9._-]/', '', (string)$data['client']);
    $corr   = isset($data['corr']) ? (string)$data['corr'] : (string)time();

    $replyTopic = "tts-handshake/response/$client";
    $response = [
        'status'    => 'ok',
        'server'    => (gethostname() ?: 'unknown'),
        'timestamp' => date('c'),
        'corr'      => $corr,
    ];

    // --- fully silence any phpMQTT stdout/stderr ---
    $oldStdout = fopen('php://stdout', 'r');
    $oldStderr = fopen('php://stderr', 'r');
    $null = fopen('/dev/null', 'w');
    if (is_resource(STDOUT)) { fclose(STDOUT); }
    if (is_resource(STDERR)) { fclose(STDERR); }
    define('STDOUT', $null);
    define('STDERR', $null);
    ob_start();
    $mqtt->publish($replyTopic, json_encode($response, JSON_UNESCAPED_UNICODE), 1);
    ob_end_clean();
    fclose($null);
    // restore output handles (safe for next PHP ops)
    if ($oldStdout) fclose($oldStdout);
    if ($oldStderr) fclose($oldStderr);

    logmsg("OK", "Handshake response sent to [$replyTopic] (corr=$corr)");
};

/* ======================
 * Subscription
 * ====================== */
$mqtt->subscribe([ HANDSHAKE_TOPIC => ['qos' => 0, 'function' => $callback] ]);
logmsg("INFO", "Subscribed to " . HANDSHAKE_TOPIC);

/* ======================
 * Permanent loop
 * ====================== */
$lastCheck = time();
while (true) {
    if (!$mqtt->proc()) {
        logmsg("WARN", "MQTT connection lost, reconnecting …");
        while (!$mqtt->connect(true, NULL, $user, $pass)) {
            sleep(5);
        }
        $mqtt->subscribe([ HANDSHAKE_TOPIC => ['qos' => 0, 'function' => $callback] ]);
        logmsg("OK", "Reconnected and resubscribed to " . HANDSHAKE_TOPIC);
    }

    if (time() - $lastCheck >= 60) {
        $mqtt->ping();
        $lastCheck = time();
    }

    usleep(20000);
}

$mqtt->close();
logmsg("INFO", "MQTT Handshake Listener stopped.");
exit(0);
