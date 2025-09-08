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
        if ($voice['name'] === $voiceKey) {
            $selectedVoice = $voice;
            break;
        }
    }

    if ($selectedVoice === null) {
        LOGERR("VoiceRSS.php: Provided voice '$voiceKey' not found in configuration.");
        return false;
    }

    $language = $selectedVoice['language'];
    $voiceName = $selectedVoice['name'];

    // =========================
    // 4. Prepare API request
    // =========================
    $audioFormat = "44khz_16bit_mono";
    $encodedText = urlencode($text);

    $queryString = http_build_query([
        'key' => $apikey,
        'src' => $text,
        'hl'  => $language,
        'v'   => $voiceName,
        'f'   => $audioFormat
    ]);

    $apiUrl = "http://api.voicerss.org/?" . $queryString;

    LOGOK("VoiceRSS.php: Sending TTS request to VoiceRSS API using voice '$voiceName' and language '$language'.");

    // =========================
    // 5. Fetch audio from VoiceRSS
    // =========================
    ini_set('user_agent', 'Mozilla/5.0 (VoiceRSS PHP Client)');
    $mp3Data = @file_get_contents($apiUrl);

    if ($mp3Data === false || strlen($mp3Data) < 50) {
        LOGERR("VoiceRSS.php: Failed to fetch audio data from VoiceRSS API.");
        return false;
    }

    // =========================
    // 6. Save MP3 file
    // =========================
    $outputDir = rtrim($config['SYSTEM']['ttspath'], '/');
    if (!is_dir($outputDir)) {
        if (!mkdir($outputDir, 0775, true)) {
            LOGERR("VoiceRSS.php: Output directory '$outputDir' does not exist and could not be created.");
            return false;
        }
    }

    $outputFile = $outputDir . "/" . $filename . ".mp3";

    if (file_put_contents($outputFile, $mp3Data) === false) {
        LOGERR("VoiceRSS.php: Failed to save MP3 file to '$outputFile'.");
        return false;
    }

    LOGOK("VoiceRSS.php: MP3 file successfully saved as '$outputFile'.");

    // =========================
    // 7. Return the filename
    // =========================
    return $filename;
}
?>
