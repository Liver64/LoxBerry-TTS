#!/usr/bin/env php
<?php
// soundcards.php — Prints JSON with ALSA cards/devices (incl. robust USB detection)

function readFileSafe(string $p): string { return is_file($p) ? (string)@file_get_contents($p) : ''; }

function getDefaultCard(): int {
    foreach ([getenv('HOME').'/.asoundrc', '/etc/asound.conf'] as $cfg) {
        $txt = readFileSafe($cfg);
        if ($txt && preg_match('/^\s*defaults\.(?:pcm|ctl)\.card\s+(\d+)/mi', $txt, $m)) {
            return (int)$m[1];
        }
    }
    return 0; // ALSA default
}

function isUsbCard(int $idx): bool {
    $lnk  = "/sys/class/sound/card$idx/device";
    if (!file_exists($lnk)) {
        return false;
    }
    $real = realpath($lnk) ?: '';

    // (1) Primär: /sys Pfad enthält "usb"
    if ($real && stripos($real, '/usb/') !== false) {
        return true;
    }

    // (2) udevadm: ID_BUS=usb
    $udevadm = trim(shell_exec('command -v udevadm 2>/dev/null') ?? '');
    if ($udevadm !== '') {
        // realpath kann auf ein Parent zeigen, daher -p auf den Pfad nutzen
        $props = shell_exec($udevadm.' info -q property -p '.escapeshellarg($real).' 2>/dev/null') ?? '';
        if ($props && preg_match('/^ID_BUS=usb$/m', $props)) {
            return true;
        }
    }

    // (3) Heuristik: Kartenzeile enthält "USB"
    $cardsTxt = readFileSafe('/proc/asound/cards');
    foreach (preg_split('/\R/', $cardsTxt) as $line) {
        $line = trim($line);
        if (preg_match('/^(\d+)\s+\[([^\]]+)\]\s*:\s*(.+)$/', $line, $m)) {
            if ((int)$m[1] === $idx && stripos($m[3], 'USB') !== false) {
                return true;
            }
        }
    }

    return false;
}

function buildPayload(): array {
    $default = getDefaultCard();

    // (A) Basiskarten aus /proc/asound/cards
    $cards = []; // idx => meta
    $cardsTxt = readFileSafe('/proc/asound/cards');
    foreach (preg_split('/\R/', $cardsTxt) as $line) {
        $line = trim($line);
        // Beispiel: "0 [Headphones     ]: bcm2835 - bcm2835 Headphones"
        if (preg_match('/^(\d+)\s+\[([^\]]+)\]\s*:\s*([^-]+)-\s*(.+)$/', $line, $m)) {
            $idx = (int)$m[1];
            $cards[$idx] = [
                'index'      => $idx,
                'name'       => trim($m[4]),
                'id'         => trim($m[2]),
                'is_usb'     => isUsbCard($idx),
                'is_default' => ($idx === $default),
                'devices'    => [],
            ];
        }
    }

    // (B) Playback-Devices aus /proc/asound/pcm (bevorzugt; braucht keine audio-Gruppe)
    $pcmTxt = readFileSafe('/proc/asound/pcm');
    if ($pcmTxt) {
        foreach (preg_split('/\R/', $pcmTxt) as $line) {
            $line = trim($line);
            // Bsp: "00-00: bcm2835 Headphones : ... : playback 1"
            if (preg_match('/^(\d{2})-(\d{2}):\s+(.+?)\s*:.*playback\s+\d+/i', $line, $m)) {
                $cidx  = (int)$m[1];              // "00" -> 0
                $didx  = (int)$m[2];
                $dname = trim($m[3]);
                if (isset($cards[$cidx])) {
                    $cards[$cidx]['devices'][] = [
                        'device' => $didx,
                        'name'   => $dname,
                        'value'  => "hw:$cidx,$didx",
                    ];
                }
            }
        }
    } else {
        // (C) Fallback: aplay -l (falls /proc/asound/pcm fehlt)
        $aplay = trim(shell_exec('command -v aplay 2>/dev/null') ?: '/usr/bin/aplay');
        $out   = shell_exec("LC_ALL=C $aplay -l 2>/dev/null") ?: '';
        foreach (preg_split('/\R/', $out) as $line) {
            $line = trim($line);
            // "card 0: xxx [ID], device 0: NAME ..."
            if (preg_match('/^card\s+(\d+):\s+([^\[]+)\[([^\]]+)\],\s*device\s+(\d+):\s+(.+)$/', $line, $m)) {
                $cidx  = (int)$m[1];
                $didx  = (int)$m[4];
                $dname = trim($m[5]);

                if (!isset($cards[$cidx])) {
                    $cards[$cidx] = [
                        'index'      => $cidx,
                        'name'       => trim($m[2]),
                        'id'         => trim($m[3]),
                        'is_usb'     => isUsbCard($cidx),
                        'is_default' => ($cidx === $default),
                        'devices'    => [],
                    ];
                }
                $cards[$cidx]['devices'][] = [
                    'device' => $didx,
                    'name'   => $dname,
                    'value'  => "hw:$cidx,$didx",
                ];
            }
        }
    }

    // (D) Wenn eine Karte keine Devices hat → wenigstens device 0 anbieten
    foreach ($cards as $i => $c) {
        if (empty($c['devices'])) {
            $cards[$i]['devices'][] = [
                'device' => 0,
                'name'   => $c['name'].' (device 0)',
                'value'  => "hw:$i,0",
            ];
        }
    }

    ksort($cards);
    return [
        'detected_at'        => date('c'),
        'default_card_index' => $default,
        'cards'              => array_values($cards),
    ];
}

$payload = buildPayload();

// stdout JSON (für dein Perl/UI)
header('Content-Type: application/json; charset=utf-8');
echo json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);

// optional zusätzlich in /tmp schreiben
@file_put_contents('/tmp/soundcards.json', json_encode($payload));
