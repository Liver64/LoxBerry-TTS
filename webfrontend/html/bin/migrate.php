<?php
require_once "loxberry_system.php";

/**
 * Migration script: tts_all.cfg -> t2s_config.json
 * 
 * Schritte:
 * 1. Prüfen, ob JSON-Config bereits existiert → wenn ja, abbrechen.
 * 2. Alte CFG-Datei parsen.
 * 3. Keys umbenennen: API-key → apikey, secret-key → secretkey.
 * 4. JSON-Datei schreiben.
 * 5. Alte CFG löschen.
 */

// Dateipfade
$oldCfgFile = LBPCONFIGDIR . '/tts_all.cfg';
$newJsonFile = LBPCONFIGDIR . '/t2s_config.json';

/** 
 * 1️⃣ Prüfen, ob neue JSON-Konfig schon existiert
 */
if (file_exists($newJsonFile)) {
    echo "<INFO> New JSON config already exists, no migration needed :-)" . PHP_EOL;
    exit;
}

/**
 * 2️⃣ Alte CFG-Datei laden
 */
if (!file_exists($oldCfgFile)) {
    echo "<ERROR> No old CFG config file found. Migration aborted." . PHP_EOL;
    exit(1);
}

$config = parse_ini_file($oldCfgFile, true);
if ($config === false) {
    echo "<ERROR> Could not parse old CFG configuration file." . PHP_EOL;
    exit(1);
}
echo "<OK> CFG configuration successfully parsed" . PHP_EOL;

/**
 * 3️⃣ Keys umbenennen
 */
if (isset($config['TTS']['API-key']) && isset($config['TTS']['secret-key'])) {
    $config['TTS']['apikey']    = $config['TTS']['API-key'];
    $config['TTS']['secretkey'] = $config['TTS']['secret-key'];
    unset($config['TTS']['API-key'], $config['TTS']['secret-key']);
    echo "<INFO> Config keys successfully migrated" . PHP_EOL;
} else {
    echo "<WARN> Old CFG does not contain API-key or secret-key fields" . PHP_EOL;
}

/**
 * 4️⃣ JSON-Datei schreiben
 */
try {
    $jsonData = json_encode(
        $config,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );

    if (file_put_contents($newJsonFile, $jsonData) === false) {
        throw new Exception("Could not write to $newJsonFile");
    }

    echo "<OK> New JSON config file successfully saved" . PHP_EOL;
} catch (Exception $e) {
    require_once "loxberry_log.php";
    echo "<ERROR> Failed to write new JSON config file: {$e->getMessage()}" . PHP_EOL;

    $notification = [
        "PACKAGE"   => $lbpplugindir,
        "NAME"      => "Text-to-speech",
        "MESSAGE"   => "The file '$newJsonFile' could not be written! Please check permissions and the old config file '$oldCfgFile'.",
        "SEVERITY"  => 3,
        "fullerror" => "Error: " . $e->getMessage()
    ];
    notify_ext($notification);
    exit(1);
}

/**
 * 5️⃣ Alte CFG-Datei löschen
 */
if (file_exists($newJsonFile) && @unlink($oldCfgFile)) {
    echo "<OK> Old CFG config file successfully deleted" . PHP_EOL;
} else {
    echo "<WARN> Could not delete old CFG config file. Please remove it manually." . PHP_EOL;
}
?>
