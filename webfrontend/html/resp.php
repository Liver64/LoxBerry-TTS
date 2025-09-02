<?php
$text = "Hallo Welt, das ist ein TTS Test";

// Ziel-URL (nur mit gültigem Server-API-Key nutzbar)
$url = "https://api.responsivevoice.org/v1/text:speak";

$data = [
    "key" => "WQAwyp72",   // ❗ muss gültiger Server-Key sein
    "src" => $text,
    "hl"  => "de-DE",
    "v"   => "Deutsch Female",
    "c"   => "mp3"
];

// cURL-Init
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);

// Header mitgeben
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/x-www-form-urlencoded"
]);

// Request ausführen
$response = curl_exec($ch);

// Statuscode & Fehler abfangen
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if (curl_errno($ch)) {
    echo "cURL-Fehler: " . curl_error($ch);
    curl_close($ch);
    exit;
}
curl_close($ch);

if ($httpcode !== 200) {
    echo "HTTP-Fehler $httpcode<br>";
    echo "Antwort: <pre>" . htmlspecialchars($response) . "</pre>";
    exit;
}

// Datei speichern
file_put_contents("tts.mp3", $response);

// MP3 an den Browser schicken
header("Content-Type: audio/mpeg");
header("Content-Disposition: attachment; filename=tts.mp3");
readfile("tts.mp3");
exit;
?>
