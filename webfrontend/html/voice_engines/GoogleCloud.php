<?php
function t2s(array $t2s_param)
{
    global $config;

    LOGINF("voice_engines/GoogleCloud.php: Start");

    // ===== 1) Parameter =====
    $filename   = $t2s_param['filename'] ?? 'tts_output';
    $textstring = $t2s_param['text']     ?? '';
    $voiceName  = $t2s_param['voice']    ?? ($config['TTS']['voice'] ?? 'de-DE-Neural2-F');

    if ($textstring === '') {
        LOGERR("voice_engines/GoogleCloud.php: Empty text.");
        return false;
    }

    // Sprache aus Voice ableiten (z.B. de-DE-Neural2-F -> de-DE)
    $langFromVoice = static function (string $v): string {
        $p = explode('-', $v);
        return (count($p) >= 2) ? "$p[0]-$p[1]" : 'de-DE';
    };

    $language = $_GET['lang'] ?? ($config['TTS']['messageLang'] ?? $langFromVoice($voiceName));
    if (!empty($_GET['voice'])) {
        $voiceName = $_GET['voice'];
        $language  = $langFromVoice($voiceName);
    }

    LOGINF("voice_engines/GoogleCloud.php: Voice='{$voiceName}', Language='{$language}'");

    // Zielpfad
    $ttspath = rtrim($config['SYSTEM']['ttspath'] ?? '/tmp', '/');
    if (!is_dir($ttspath)) @mkdir($ttspath, 0775, true);
    $mp3Path = "{$ttspath}/{$filename}.mp3";

    // Cache
    if (is_file($mp3Path) && filesize($mp3Path) > 0) {
        LOGINF("voice_engines/GoogleCloud.php: Cache hit ($mp3Path, ".filesize($mp3Path)." bytes)");
        LOGOK ("voice_engines/GoogleCloud.php: Done (from cache)");
        return basename($mp3Path, '.mp3');
    }

    // ===== 2) Auth =====
    $apiKey      = $t2s_param['apikey']       ?? ($config['TTS']['API-key'] ?? '');
    $accessToken = $t2s_param['access_token'] ?? ($config['TTS']['access_token'] ?? '');

    $endpoint = 'https://texttospeech.googleapis.com/v1/text:synthesize';
    if ($accessToken === '' && $apiKey !== '') {
        $endpoint .= '?key=' . rawurlencode($apiKey);
    }
    if ($accessToken === '' && $apiKey === '') {
        LOGERR("voice_engines/GoogleCloud.php: No credentials (access_token or API-key) provided.");
        return false;
    }

    // Optionale Prosodie
    $speakingRate = isset($t2s_param['speakingRate']) ? (float)$t2s_param['speakingRate']
                  : (isset($_GET['rate']) ? (float)$_GET['rate'] : 1.0);
    $pitch        = isset($t2s_param['pitch']) ? (float)$t2s_param['pitch']
                  : (isset($_GET['pitch']) ? (float)$_GET['pitch'] : 0.0);

    // ===== 3) Payload-Builder =====
    $buildPayload = static function (string $text) use ($language, $voiceName, $speakingRate, $pitch): string {
        return json_encode([
            "audioConfig" => [
                "audioEncoding" => "MP3",
                "speakingRate"  => $speakingRate, // 0.25..4.0
                "pitch"         => $pitch        // -20..20
            ],
            "input" => [ "text" => $text ],
            "voice" => [
                "languageCode" => $language,
                "name"         => $voiceName
            ]
        ], JSON_UNESCAPED_UNICODE);
    };

    // ===== 4) Chunking =====
    $chunks = chunkTextForGoogleTTS($textstring, 4800);

    // ===== 5) HTTP Call (mit Retry) =====
    $callGoogle = static function (string $url, string $json, string $accessToken) {
        $ch = curl_init($url);
        $headers = [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json)
        ];
        if ($accessToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $accessToken;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$resp, $err, $code];
    };

    @unlink($mp3Path);
    $minSize = 1024;
    $okAll   = true;

    foreach ($chunks as $i => $chunk) {
        $payload = $buildPayload($chunk);

        // Retries bei 429/5xx
        $atte
