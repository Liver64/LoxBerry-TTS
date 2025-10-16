<?php
function muellkalender() {

	require_once "loxberry_system.php";
    global $config, $home;

    $TL = LOAD_T2S_TEXT();

    // CalDav4Lox Plugin vorhanden?
    $caldavPhp = LBHOMEDIR."/webfrontend/html/plugins/caldav4lox/caldav.php";
    if (!is_file($caldavPhp)) {
        LOGERR('Text2Speech: addon/waste-calendar-to-speech.php: Caldav-4-Lox plugin not installed. Please install the plugin.');
		exit;
        return ''; // kein TTS
    }

    // LoxBerry-Check
    if (substr((string)$home, 0, 4) !== '/opt') {
        #LOGERR('Text2Speech: addon/waste-calendar-to-speech.php: This addon only runs on LoxBerry.');
        #return '';
    }

    // URL aus Config
    $url = (string)($config['VARIOUS']['CALDavMuell'] ?? '');
    if ($url === '') {
        LOGWARN('Text2Speech: addon/waste-calendar-to-speech.php: Config VARIOUS.CALDavMuell is empty.');
        return '';
    }
    if (strpos($url, '&debug') !== false) {
        LOGWARN('Text2Speech: addon/waste-calendar-to-speech.php: Please remove &debug from the configured URL.');
        // Wir hängen debug selbst an:
        $url = str_replace('&debug', '', $url);
    }
    $rawUrl = $config['VARIOUS']['CALDavMuell'];
	$callurl = sanitize_caldav_url($rawUrl);

	$ctx = stream_context_create(['http' => ['timeout' => 5]]);
	$raw = @file_get_contents($callurl, false, $ctx);

	LOGINF("Text2Speech: addon/waste-calendar-to-speech.php: CalDav URL: $callurl");

	$ctx = stream_context_create(['http' => ['timeout' => 5]]);
	$raw = @file_get_contents($callurl, false, $ctx);
    $dienst = json_decode($raw, true);
    if (!is_array($dienst)) {
        LOGERR('Text2Speech: addon/waste-calendar-to-speech.php: Invalid JSON from Caldav-4-Lox.');
        return '';
    }

    $hour  = (int)strftime('%H');
    $today = date('m/d/Y');

    // Prüfen, ob die Konfiguration Events im URL-Query vorgibt
    $eventsPos = strpos($url, 'events');
    if ($eventsPos === false) {
        // Ausgabe ohne "events"-Filter (defensiv gegen unerwartete Struktur)
        // Erwartete Keys sind in der Originalversion leer ('') – absichern:
        $root = $dienst[''] ?? null;
        if (!is_array($root)) {
            LOGWARN('Text2Speech: addon/waste-calendar-to-speech.php: No event payload in response.');
            return '';
        }

        $summary = $root['Summary'] ?? '';
        $fwDay   = $root['fwDay']   ?? null;
        $hStart  = $root['hStart']  ?? null;

        if ($summary === '' || $fwDay === null || $hStart === null) {
            LOGWARN('Text2Speech: addon/waste-calendar-to-speech.php: Missing fields (Summary/fwDay/hStart) in response.');
            return '';
        }

        $enddate = date('m/d/Y', strtotime($hStart));
        $days    = (strtotime($enddate) - strtotime($today)) / 86400;

        $speak = '';
        if ($fwDay === 0 && $hour >= 4 && $hour < 12) {
            $speak = welcomemorning() . ' ' .
                     $TL['WASTE-CALENDAR-TO-SPEECH']['TODAY_MORNING_START'] . ' ' .
                     $summary . ' ' .
                     $TL['WASTE-CALENDAR-TO-SPEECH']['TODAY_MORNING_END'];
        } elseif ($fwDay === 1 && $hour >= 18) {
            $speak = welcomeevening() . ' ' .
                     $TL['WASTE-CALENDAR-TO-SPEECH']['EVENING_BEFORE_START'] . ' ' .
                     $summary . ' ' .
                     $TL['WASTE-CALENDAR-TO-SPEECH']['EVENING_BEFORE_END'];
        }

        if ($speak === '') {
            LOGDEB('Text2Speech: addon/waste-calendar-to-speech.php: No announcement due to time/day conditions.');
            return '';
        }

        LOGDEB('Text2Speech: addon/waste-calendar-to-speech.php: Waste calendar announcement: ' . $speak);
        LOGOK('Text2Speech: addon/waste-calendar-to-speech.php: Message generated and passed to T2S.');
        return $speak;
    }

    // Ausgabe mit "events"-Filter
    $eventsQuery = substr($url, strrpos($url, 'events') + 7);
    if ($eventsQuery === '' || $eventsQuery === false) {
        LOGWARN('Text2Speech: addon/waste-calendar-to-speech.php: Please provide specific events after &events= in the config URL.');
        return '';
    }

    $muellarten   = explode('|', $eventsQuery);
    $muellheute   = [];
    $muellmorgen  = [];

    foreach ($muellarten as $val) {
        $val = trim($val);
        if ($val === '' || !isset($dienst[$val])) { continue; }

        $ev = $dienst[$val];
        $hStart = $ev['hStart'] ?? null;
        $hEnd   = $ev['hEnd']   ?? null;
        if (!$hStart || !$hEnd) { continue; }

        // Full-day only: Start- und End-Zeit müssen identisch sein (fix: Vergleich, kein Assignment!)
        $starttime = substr($hStart, 11, 20);
        $endtime   = substr($hEnd,   11, 20);
        if ($endtime == $starttime) {
            $fwDay = $ev['fwDay'] ?? null;
            if ($fwDay === 0)       { $muellheute[]  = $val; }
            elseif ($fwDay === 1)   { $muellmorgen[] = $val; }
        }
    }

    $speak = '';
    if (count($muellheute) === 1 && $hour >= 0 && $hour < 11) {
        $speak = welcomemorning() . ' ' .
                 $TL['WASTE-CALENDAR-TO-SPEECH']['TODAY_MORNING_START'] . ' ' .
                 $muellheute[0] . ' ' .
                 $TL['WASTE-CALENDAR-TO-SPEECH']['TODAY_MORNING_END'];
    } elseif (count($muellheute) === 2 && $hour >= 0 && $hour < 11) {
        $speak = welcomemorning() . ' ' .
                 $TL['WASTE-CALENDAR-TO-SPEECH']['TODAY_MORNING_START'] . ' ' .
                 $muellheute[0] . ' ' .
                 $TL['WASTE-CALENDAR-TO-SPEECH']['IN_CASE_2TIMES_WASTE'] . ' ' .
                 $muellheute[1] . ' ' .
                 $TL['WASTE-CALENDAR-TO-SPEECH']['TODAY_MORNING_END'];
    } elseif (count($muellmorgen) === 1 && $hour >= 11) {
        $speak = welcomeevening() . ' ' .
                 $TL['WASTE-CALENDAR-TO-SPEECH']['EVENING_BEFORE_START'] . ' ' .
                 $muellmorgen[0] . ' ' .
                 $TL['WASTE-CALENDAR-TO-SPEECH']['EVENING_BEFORE_END'];
    } elseif (count($muellmorgen) === 2 && $hour >= 11) {
        $speak = welcomeevening() . ' ' .
                 $TL['WASTE-CALENDAR-TO-SPEECH']['EVENING_BEFORE_START'] . ' ' .
                 $muellmorgen[0] . ' ' .
                 $TL['WASTE-CALENDAR-TO-SPEECH']['IN_CASE_2TIMES_WASTE'] . ' ' .
                 $muellmorgen[1] . ' ' .
                 $TL['WASTE-CALENDAR-TO-SPEECH']['EVENING_BEFORE_END'];
    } else {
        LOGDEB('Text2Speech: addon/waste-calendar-to-speech.php: No matching waste events for today/tonight.');
        return '';
    }

    LOGDEB('Text2Speech: addon/waste-calendar-to-speech.php: Waste calendar announcement: ' . $speak);
    LOGOK('Text2Speech: addon/waste-calendar-to-speech.php: Message generated and passed to T2S.');
    return $speak;
}

/**
 * Zerlegt eine CalDav-URL aus der Config, encodiert kritische Parameter und setzt sie sicher wieder zusammen.
 *
 * @param string $rawUrl Vollständige URL aus der Config
 * @return string Bereinigte, sichere URL
 */
function sanitize_caldav_url(string $rawUrl): string {
    $parts = parse_url($rawUrl);
    if (!isset($parts['scheme'], $parts['host'], $parts['path'], $parts['query'])) {
        LOGERR("Text2Speech: addon/waste-calendar-to-speech.php: Invalid URL structure.");
        return '';
    }

    parse_str($parts['query'], $query);

    // Kritische Parameter encodieren
    if (isset($query['pass'])) {
        $query['pass'] = urlencode($query['pass']);
    }
    if (isset($query['events'])) {
        $query['events'] = urlencode($query['events']);
    }

    // Optional: debug-Flag ergänzen
    $query['debug'] = '';

    // URL neu zusammensetzen
    $safeUrl = $parts['scheme'] . '://' . $parts['host'] . $parts['path'] . '?' . http_build_query($query);

    LOGDEB("Text2Speech: addon/waste-calendar-to-speech.php: Final URL: $safeUrl");
    return $safeUrl;
}

