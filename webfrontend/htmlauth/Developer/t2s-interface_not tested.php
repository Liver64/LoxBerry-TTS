<?php
require_once "loxberry_io.php";
require_once "phpMQTT/phpMQTT.php";

$P2W_Text = "Text to be spoken";
$psubfolder = "folder where your plugin is installed (according to your plugin.cfg)";


function t2svoice() {
    // Required global variables
    global $P2W_Text, $psubfolder;
	
    $RESP_TIMEOUT = 12;

    // ---------- Generate Unique Topics ----------
    $client = $psubfolder;

    // Generate a unique correlation ID using uuidgen or fallback to current timestamp
	// used as unique identifyer for MQTT pub and sub messsages to ensure requester get's their own sub back
    $corr = trim(shell_exec('uuidgen'));
    if (!$corr) {
        $corr = time();
    }

    // Define MQTT topics for request and response
    $req_topic  = "tts-publish/$client/$corr";
    $resp_topic = "tts-subscribe/$client/$corr";

    // ---------- Prepare Text Payload ----------
    $P2W_Text = $P2W_Text ?? '';
    $P2W_Text = preg_replace('/\R/', '', $P2W_Text); // Remove all line breaks

    // If text is empty, fallback to Pico TTS
    if ($P2W_Text === '') {
        return usepico();
    }

    // ---------- Create JSON Payload ----------
	// those are validated at mqtt subscriber from Text2speech (TTS) plugin
	// TTS Plugin checks if Messsage has been already generated and/or skip creation
    $payload = json_encode([
        'text'      => $P2W_Text,		// Text to converted into voice mp3 file
        'nocache'   => 0,				// 0 = check if Voice file already created and ship from cache 1 =  force TTS to recreate MP3 file
        'logging'   => 1,				// 0 = no TTS logs were passed. 1 = you get all logs from TTS for further proccessing
        'mp3files'  => 0,				// 0 = no list of available MP3 files could be used 1 = list of available MP3 files
        'client'    => $client,			// identifyer or requester (plugin installation folder)
        'corr'      => $corr,			// unique identifyer for this specific pub request
        'reply_to'  => $resp_topic		// response to be used from the subscriber
    ]);

    // ---------- Define Response Parser ----------
    $parse_response = function($msg) {
        $data = json_decode($msg, true);
        if (!$data) return null;

        // Extract response object if nested
        $r = $data['response'] ?? $data;

        return [
            'file'          => $r['file'] ?? null,
            'httpinterface' => $r['interfaces']['httpinterface'] ?? ($r['httpinterface'] ?? null),
            'corr'          => $r['corr'] ?? ($r['original']['corr'] ?? null)
        ];
    };

    // ---------- Connect to Local MQTT Broker ----------
    $cred = mqtt_connectiondetails(); // Get the MQTT Gateway connection details from LoxBerry
    $host = $cred['brokerhost'] ?? '127.0.0.1';
    $port = $cred['brokerport'] ?? 1883;
    $user = $cred['brokeruser'] ?? '';
    $pass = $cred['brokerpass'] ?? '';

    // Allow insecure login if needed (depends on MQTT client)
    putenv("MQTT_SIMPLE_ALLOW_INSECURE_LOGIN=1");

    // Initialize MQTT client
    try {
        $mqtt = new NetMQTTClient("$host:$port"); // Replace with actual MQTT client class
        if ($user || $pass) {
            $mqtt->login($user, $pass);
        }
    } catch (Exception $e) {
        // Connection failed, fallback to Pico
        return usepico();
    }

    // ---------- Subscribe and Wait for Response ----------
    $reply = null;

    // Subscribe to response topic with wildcard
    $mqtt->subscribe("tts-subscribe/#", function($topic, $message) use ($parse_response, $corr, &$reply) {
        $parsed = $parse_response($message);
        if ($parsed && $parsed['corr'] === $corr) {
            $reply = $parsed;
        }
    });

    // Publish the request payload
    $mqtt->publish($req_topic, $payload);

    // Wait for response within timeout window
    $end = time() + $RESP_TIMEOUT;
    while (!$reply && time() < $end) {
        $mqtt->tick(); // Process incoming messages
        usleep(100000); // Sleep for 100ms
    }

    // Disconnect from broker
    $mqtt->disconnect();

    // ---------- Handle Response or Fallback ----------
    if ($reply && $reply['file'] && $reply['httpinterface']) {
        $url = $reply['httpinterface'] . '/' . $reply['file'];
        $GLOBALS['full_path_to_mp3'] = $url;
        // enter your code for further proccessing
    }
    // enter your code for fallback
}