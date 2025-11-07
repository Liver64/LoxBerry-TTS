<?php
/**
 * Text-to-Speech (TTS) using VoiceRSS API
 *
 * Creates an MP3 file from the provided text using VoiceRSS and saves it locally.
 *
 * @param array $t2s_param [
 *     'apikey'   => (string) VoiceRSS API key,
 *     'filename' => (string) Output filename (without extension),
 *     'text'     => (string) Text to convert,
 *     'voice'    => (string) Voice identifier
 * ]
 *
 * @return string|false Returns the filename on success or false on failure
 */
function t2s($t2s_param)
{
    global $config;

    // =========================
    // 1. Validate input parameters
    // =========================
    $apikey   = $t2s_param['apikey']   ?? null;
    $filename = $t2s_param['filename'] ?? null;
    $text     = $t2s_param['text']     ?? null;
    $voiceKey = $t2s_param['voice']    ?? null;

    if (empty($apikey) || empty($filename) || empty($text) || empty($voiceKey)) {
        LOGERR("VoiceRSS.php: Missing required parameters (apikey, filename, text, voice).");
        return false;
    }

    // =========================
    // 2. Load voice and language configuration
    // =========================
    $langFilePath  = LBPHTMLDIR . "/voice_engines/langfiles/voicerss.json";
    $voiceFilePath = LBPHTMLDIR . "/voice_engines/langfiles/voicerss_voices.json";

    if (!file_exists($langFilePath) || !file_exists($voiceFilePath)) {
        LOGERR("VoiceRSS.php: Configuration files for languages or voices are missing.");
        return false;
    }

    $voices = json_decode(file_get_contents($voiceFilePath), true);
    if (!is_array($voices)) {
        LOGERR("VoiceRSS.php: Invalid JSON format in voices file.");
        return false;
    }

    // =========================
    // 3. Find matching voice
    // =========================
    $selectedVoice = null;
    foreach ($voices as $voice) {
        if (($voice['name'] ?? '') === $voiceKey) {
            $selectedVoice = $voice;
            break;
        }
    }

    if ($selectedVoice === null) {
        LOGERR("VoiceRSS.php: Provided voice '$voiceKey' not found in configuration.");
        return false;
    }

    $language  = $selectedVoice['language'];
    $voiceName = $selectedVoice['name'];

    // =========================
    // 3.5 Pre-API-check (connectivity & key validation)
    // =========================
    $pingUrl = "https://api.voicerss.org/?key=" . urlencode($apikey) . "&hl=en-us&src=ping";
    $ctxPing = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "User-Agent: LoxBerry-T2S/1.0\r\n",
            'timeout' => 8,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ]
    ]);

    $pingResult = @file_get_contents($pingUrl, false, $ctxPing);
    if ($pingResult === false) {
        LOGERR("VoiceRSS.php: Pre-check failed — cannot reach VoiceRSS API endpoint.");
        return false;
    }

    // VoiceRSS returns text errors like "ERROR: The API key is invalid or has expired."
    if (stripos($pingResult, 'ERROR') !== false) {
        LOGERR("VoiceRSS.php: Pre-check response indicates an error from API: " . trim($pingResult));
        return false;
    }

    LOGDEB("VoiceRSS.php: Pre-check successful — VoiceRSS API reachable and key valid.");

    // =========================
    // 4. Prepare API request (force MP3!)
    // =========================
    $query = [
        'key' => $apikey,
        'src' => $text,
        'hl'  => $language,
        'v'   => $voiceName,
        'c'   => 'MP3'
    ];
    $apiUrl = "https://api.voicerss.org/?" . http_build_query($query);

    LOGOK("VoiceRSS.php: Sending TTS request to VoiceRSS API using voice '$voiceName' and language '$language'.");

    // =========================
    // 5. Fetch audio from VoiceRSS
    // =========================
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "User-Agent: LoxBerry-T2S/1.0\r\n",
            'timeout' => 20,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ]
    ]);

    $audioData = @file_get_contents($apiUrl, false, $ctx);
    if ($audioData === false || strlen($audioData) < 50 || stripos($audioData, 'ERROR') !== false) {
        LOGERR("VoiceRSS.php: Failed to fetch audio data from VoiceRSS API.");
        if ($audioData && stripos($audioData, 'ERROR') !== false) {
            LOGERR("VoiceRSS.php: API returned error: " . trim($audioData));
        }
        return false;
    }

    // =========================
    // 6. Save MP3 file
    // =========================
    $outputDir = rtrim($config['SYSTEM']['ttspath'], '/');
    if (!is_dir($outputDir) && !@mkdir($outputDir, 0775, true)) {
        LOGERR("VoiceRSS.php: Output directory '$outputDir' does not exist and could not be created.");
        return false;
    }

    $safeName   = preg_replace('~[^a-f0-9]~i', '', (string)$filename);
    $outputFile = $outputDir . "/" . $safeName . ".mp3";

    if (@file_put_contents($outputFile, $audioData) === false) {
        LOGERR("VoiceRSS.php: Failed to save MP3 file to '$outputFile'.");
        return false;
    }

    LOGOK("VoiceRSS.php: MP3 file successfully saved as '$outputFile'.");

    // =========================
    // 7. Return the filename
    // =========================
    return $safeName;
}
?>
