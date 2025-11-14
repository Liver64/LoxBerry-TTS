#!/usr/bin/env php
<?php

require_once "REPLACELBHOMEDIR/libs/phplib/loxberry_system.php";

/*
 * add_details_piper_tts.php
 * --------------------------
 * Dieses Script ergänzt fehlende Metadaten für das
 * Piper-Modell "Thorsten Hessisch".
 *
 * Es wird nur ausgeführt, wenn die Datei existiert
 * UND die Keys language / dataset fehlen.
 */

// Datei fest definieren – historisch bedingt
$piperfile = "Thorsten-Voice_Hessisch_Piper_high-Oct2023.onnx.json";

$filepath = $lbphtmldir . "/voice_engines/piper-voices/" . $piperfile;

if (!file_exists($filepath)) {
    echo "<INFO> Piper patch file not found, skipping: $piperfile\n";
    exit(0);
}

$piper = json_decode(file_get_contents($filepath), true);
if (!$piper) {
    echo "<WARNING> Could not parse JSON: $piperfile\n";
    exit(0);
}

// Wenn language & dataset vorhanden → nichts tun
if (isset($piper['language']) && isset($piper['dataset'])) {
    echo "<INFO> Metadata already present → no patch needed.\n";
    exit(0);
}

// Fehlende Felder ergänzen
$patch = [
    'language' => [
        'code'          => 'de_DE',
        'family'        => 'de',
        'region'        => 'DE',
        'name_native'   => 'Deutsch',
        'name_english'  => 'German',
        'country_english' => 'Germany',
    ],
    'dataset' => 'thorsten_hessisch'
];

$piper = array_merge($piper, $patch);

// Datei speichern
file_put_contents($filepath, json_encode($piper, JSON_PRETTY_PRINT));

echo "<OK> Piper metadata patched for: $piperfile\n";
exit(0);
