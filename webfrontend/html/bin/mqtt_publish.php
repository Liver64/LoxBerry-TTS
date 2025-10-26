<?php
declare(strict_types=1);

/**
 * mqtt_publish.php
 * - HTTP/CLI Dualmodus
 * - Nimmt JSON/POST/GET/CLI Args entgegen
 * - Publiziert Payload an MQTT (default topic 'tts-publish')
 * - Lauscht auf 'tts-subscribe' für Antwort (10s Timeout)
 * - Gibt IMMER eine JSON-Response zurück (auch bei Fehlern)
 */

require_once "REPLACELBHOMEDIR/libs/phplib/loxberry_system.php";
require_once "REPLACELBHOMEDIR/libs/phplib/loxberry_io.php";
require_once "REPLACELBHOMEDIR/webfrontend/html/plugins/text2spech/bin/phpmqtt/phpMQTT.php";

use Bluerhinos\phpMQTT;

/* ----------------- Konstanten ----------------- */
const DEFAULT_SEND_TOPIC     		= 'tts-publish';
const DEFAULT_RESPONSE_TOPIC 		= 'tts-subscribe';
const DEFAULT_MQTT_TOPIC 			= 'mqtt-config';     // Anfrage-Topic für Broker-Config
const DEFAULT_REPLY_MQTT_TOPIC  	= '/response';       // Standard-Reply, falls kein backtopic angegeben

/* ----------------- JSON-Ausgabe & Errorhandler ----------------- */
function json_out(int $code, array $data): void {
    if (PHP_SAPI !== 'cli') {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . (PHP_SAPI === 'cli' ? PHP_EOL : '');
    exit;
}

set_exception_handler(function(Throwable $e){
    json_out(500, [
        'ok'      => false,
        'error'   => 'exception',
        'message' => $e->getMessage(),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine(),
    ]);
});

set_error_handler(function($errno, $errstr, $errfile, $errline){
    if (!(error_reporting() & $errno)) return false;
    json_out(500, [
        'ok'      => false,
        'error'   => 'php_error',
        'type'    => $errno,
        'message' => $errstr,
        'file'    => basename($errfile),
        'line'    => $errline,
    ]);
});

/* ----------------- Helpers ----------------- */
function to01($v): int {
    if (is_bool($v)) return $v ? 1 : 0;
    $s = strtolower(trim((string)$v));
    if (in_array($s, ['1','true','on','yes'], true))  return 1;
    if (in_array($s, ['0','false','off','no'], true)) return 0;
    return ctype_digit($s) ? ((int)$s ? 1 : 0) : 0;
}

/* ----------------- Optional: MQTT-Config abfragen ----------------- */
if (to01($in['getconfig'] ?? ($in['config'] ?? 0))) {
    $creds  = mqtt_connectiondetails();
    $broker = $creds['brokerhost'] ?? '127.0.0.1';
    $port   = (int)($creds['brokerport'] ?? 1883);
    $user   = $creds['brokeruser'] ?? '';
    $pass   = $creds['brokerpass'] ?? '';

    $client_id_pub = uniqid((gethostname() ?: 'lb') . "_pub_");
    $client_id_sub = uniqid((gethostname() ?: 'lb') . "_sub_");

    $pub = new Bluerhinos\phpMQTT($broker, $port, $client_id_pub);
    $sub = new Bluerhinos\phpMQTT($broker, $port, $client_id_sub);
    if (!$pub->connect(true, NULL, $user, $pass) || !$sub->connect(true, NULL, $user, $pass)) {
        json_out(500, ['ok'=>false,'error'=>'mqtt_connect_failed']);
    }

    $cfgTopic    = DEFAULT_MQTT_TOPIC;         // 'mqtt-config'
    $replySuffix = DEFAULT_REPLY_MQTT_TOPIC;   // '/response'
    $tag         = uniqid('req-');
    $backtopic   = $cfgTopic . $replySuffix . '/' . $tag;

    $responseReceived = false;
    $lastResponse     = null;

    $sub->subscribe([$backtopic => ['qos' => 0, 'function' => function($t, $msg) use (&$responseReceived, &$lastResponse) {
        $responseReceived = true;
        $lastResponse = json_decode($msg, true);
        if (!is_array($lastResponse)) $lastResponse = ['ok'=>false,'error'=>'invalid_json','raw'=>$msg];
    }]]);

    $query = http_build_query(['backtopic' => $backtopic, 'tag' => $tag], '', '&', PHP_QUERY_RFC3986);
    $pub->publish($cfgTopic, $query, 0, false);

    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline && !$responseReceived) {
        $sub->proc();
        usleep(20_000);
    }
    $pub->close(); $sub->close();

    if (!$responseReceived) {
        json_out(504, ['ok'=>false,'error'=>'config_timeout','note'=>'no response on '.$backtopic.' within 5s']);
    }

    json_out(200, [
        'ok'      => true,
        'type'    => 'mqtt-config',
        'request' => ['topic'=>$cfgTopic,'backtopic'=>$backtopic,'tag'=>$tag],
        'data'    => $lastResponse,
    ]);
}


/* ----------------- Eingabe einsammeln ----------------- */
$in = [];
if (PHP_SAPI === 'cli') {
    // CLI: Flags wie --text="Hallo" --nocache 1 --topic tts-publish --retain 0 ...
    $args = $argv ?? [];
    array_shift($args);
    for ($i=0; $i<count($args); $i++) {
        if (preg_match('/^--([^=]+)=(.*)$/', $args[$i], $m)) {
            $in[$m[1]] = $m[2];
        } elseif (substr($args[$i],0,2)==='--') {
            $key = substr($args[$i],2);
            $val = ($i+1<count($args) && substr($args[$i+1],0,2)!=='--') ? $args[++$i] : '1';
            $in[$key] = $val;
        }
    }
} else {
    // HTTP: Body (JSON), dann POST, dann GET; GET überschreibt POST/JSON
    $ct  = $_SERVER['CONTENT_TYPE'] ?? '';
    $raw = file_get_contents('php://input') ?: '';
    if ($raw !== '' || stripos($ct, 'application/json') !== false) {
        $tmp = json_decode($raw, true);
        if ($raw !== '' && !is_array($tmp)) json_out(400, ['ok'=>false,'error'=>'invalid_json']);
        if (is_array($tmp)) $in = $tmp;
    }
    if (!empty($_POST)) $in = array_replace($in, $_POST);
    if (!empty($_GET))  $in = array_replace($in, $_GET);

    // Falls payload als JSON-String kam (?payload={"text":"..."}):
    if (isset($in['payload']) && is_string($in['payload'])) {
        $p = json_decode($in['payload'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($p)) $in['payload'] = $p;
    }
}

/* ----------------- Felder extrahieren ----------------- */
$topic  = trim((string)($in['topic'] ?? DEFAULT_SEND_TOPIC));
$qos    = isset($in['qos']) ? (int)$in['qos'] : 0; $qos = ($qos===1)?1:0;
$retain = to01($in['retain'] ?? 0); // Befehls-Topics: standardmäßig 0

// Payload aus 'payload' (Objekt) oder flachen Feldern
$payloadSrc = (isset($in['payload']) && is_array($in['payload']))
    ? $in['payload']
    : [
        'text'     => $in['text']     ?? null,
        'nocache'  => $in['nocache']  ?? null,
        'logging'  => $in['logging']  ?? 1,
        'mp3files' => $in['mp3files'] ?? 1,
    ];

// Validierung
$errors = [];
$text = isset($payloadSrc['text']) ? trim((string)$payloadSrc['text']) : '';
if ($text === '') $errors[] = 'text_required';
if ($errors) json_out(400, ['ok'=>false,'error'=>'validation_failed','details'=>$errors]);

// Normalisierung
$publishdata = [
    'text'     => $text,
    'nocache'  => to01($payloadSrc['nocache']  ?? 0),
    'logging'  => to01($payloadSrc['logging']  ?? 1),
    'mp3files' => to01($payloadSrc['mp3files'] ?? 1),
];

/* ----------------- MQTT verbinden ----------------- */
$creds  = mqtt_connectiondetails();
$broker = $creds['brokerhost'] ?? '127.0.0.1';
$port   = (int)($creds['brokerport'] ?? 1883);
$user   = $creds['brokeruser'] ?? '';
$pass   = $creds['brokerpass'] ?? '';

$client_id_pub = uniqid((gethostname() ?: 'lb') . "_pub_");
$client_id_sub = uniqid((gethostname() ?: 'lb') . "_sub_");

$pub = new phpMQTT($broker, $port, $client_id_pub);
$sub = new phpMQTT($broker, $port, $client_id_sub);

// Erst Subscriber verbinden (damit Antwort sofort empfangen werden kann)
if (!$sub->connect(true, null, $user, $pass)) {
    json_out(502, ['ok'=>false,'error'=>'mqtt_sub_connect_failed','broker'=>"$broker:$port"]);
}
if (!$pub->connect(true, null, $user, $pass)) {
    $sub->close();
    json_out(502, ['ok'=>false,'error'=>'mqtt_pub_connect_failed','broker'=>"$broker:$port"]);
}

/* ----------------- Response abonnieren ----------------- */
$responseTopic   = DEFAULT_RESPONSE_TOPIC;
$responseReceived= false;
$lastResponse    = null;

$sub->subscribe([
    $responseTopic => [
        'qos' => 0,
        'function' => function($t, $msg) use (&$responseReceived, &$lastResponse) {
            $msg  = mb_convert_encoding($msg, 'UTF-8', 'auto');
            $data = json_decode($msg, true);
            $lastResponse = is_array($data) ? $data : ['raw'=>$msg];
            $responseReceived = true;
        }
    ]
]);

/* ----------------- Publish senden ----------------- */
$payloadJson = json_encode($publishdata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$pub->publish($topic, $payloadJson, $qos, (bool)$retain);

/* ----------------- Warten bis Antwort oder Timeout ----------------- */
$deadline = microtime(true) + 10.0; // 10s Timeout
while (microtime(true) < $deadline && !$responseReceived) {
    $sub->proc();
    usleep(20_000);
}

/* ----------------- Aufräumen ----------------- */
$pub->close();
$sub->close();

/* ----------------- Ergebnis zurückgeben ----------------- */
json_out(200, [
    'ok'        => true,
    'published' => [
        'topic'  => $topic,
        'qos'    => $qos,
        'retain' => $retain,
        'bytes'  => strlen($payloadJson),
    ],
    'response'  => $lastResponse,         // null, wenn keine Antwort binnen 10s
    'note'      => $lastResponse ? null : 'timeout_no_response_after_10s',
]);
