<?php
/**
 * List ElevenLabs Voices
 * 
 * Ruft alle verfügbaren ElevenLabs Voices ab und zeigt diese formatiert an.
 * Du brauchst nur deinen API Key einzutragen.
 */

$apiKey = "77ba25e2758b20cb43867c8926ef30d0"; // <-- Deinen API Key hier eintragen
echo "<PRE>";
listVoices($apiKey);

/**
 * Ruft alle Voices von ElevenLabs ab und gibt diese übersichtlich aus
 */
function listVoices(string $apiKey): void
{
    $ch = curl_init("https://api.elevenlabs.io/v1/voices");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => [
            "xi-api-key: $apiKey",
            "Content-Type: application/json"
        ],
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        echo "❌ cURL Error: $err\n";
        return;
    }

    if ($httpCode !== 200) {
        echo "❌ API Error: HTTP Status $httpCode\nResponse: $response\n";
        return;
    }

    $data = json_decode($response, true);

    if (!isset($data['voices']) || empty($data['voices'])) {
        echo "⚠️ No voices found.\n";
        return;
    }

    echo "🎤 Available ElevenLabs Voices:\n";
    echo str_repeat("-", 80) . "\n";
    printf("%-25s %-10s %-10s %-30s\n", "Name", "Language", "Gender", "Voice ID");
    echo str_repeat("-", 80) . "\n";

    foreach ($data['voices'] as $voice) {
        $name      = $voice['name'] ?? 'Unknown';
        $lang      = $voice['labels']['language'] ?? 'Unknown';
        $gender    = $voice['labels']['gender'] ?? 'Unknown';
        $voice_id  = $voice['voice_id'] ?? 'Unknown';

        printf("%-25s %-10s %-10s %-30s\n", $name, $lang, $gender, $voice_id);
    }

    echo str_repeat("-", 80) . "\n";
    echo "Total voices found: " . count($data['voices']) . "\n";
}
?>
