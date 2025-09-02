<?php

require_once "loxberry_system.php";
require_once "loxberry_log.php";
require_once "loxberry_io.php";
require_once "/opt/loxberry/webfrontend/html/plugins/text2speech/bin/phpmqtt/phpMQTT.php";

$level = LBSystem::pluginloglevel();

// Logging vorbereiten
$params = [	"name" => "MQTT Flat Handler",
			"filename" => "/opt/loxberry/log/plugins/text2speech/mqtt.log",
			"append" => 1,
			"addtime" => 1,
			];
$log = LBLog::newLog($params);	

// MQTT-Verbindungsdetails holen
$creds = mqtt_connectiondetails();
$client_id = uniqid(gethostname() . "_client");

// Topics definieren
$subscribeTopic = 'flat/topic';
$responseTopic  = 'flat/response';

// Verbindung aufbauen
$mqtt = new Bluerhinos\phpMQTT($creds['brokerhost'], $creds['brokerport'], $client_id);

if (!$mqtt->connect(true, NULL, $creds['brokeruser'], $creds['brokerpass'])) {
    LOGERR("MQTT Verbindung fehlgeschlagen");
    $log->LOGERR("MQTT Verbindung fehlgeschlagen");
    exit(1);
}

// Callback-Funktion definieren
$callback = function ($topic, $msg) use ($mqtt, $responseTopic, $log) {
    $log->LOGINF("Empfangen von [$topic]: $msg");

    $data = json_decode($msg, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $log->LOGWARN("Ungültiges JSON empfangen: $msg");
        return;
    }

    // Beispielhafte Verarbeitung
    $response = [
        'status'    => 'received',
        'original'  => $data,
        'timestamp' => date('c')
    ];

    $responseJson = json_encode($response);
    $mqtt->publish($responseTopic, $responseJson, 0);
    $log->LOGINF("Rückantwort gesendet an [$responseTopic]: $responseJson");
};

// Topic abonnieren
$mqtt->subscribe([
    $subscribeTopic => ['qos' => 0, 'function' => $callback]
]);

// Endlosschleife zum Lauschen
while ($mqtt->proc()) {}

$mqtt->close();
$log->LOGINF("MQTT-Verbindung geschlossen");

?>
?>