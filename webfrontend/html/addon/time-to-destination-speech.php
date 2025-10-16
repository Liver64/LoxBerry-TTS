<?php
function tt2t()
{
    // https://developers.google.com/maps/documentation/distance-matrix/intro#DistanceMatrixRequests
    global $config, $traffic;

    $TL = LOAD_T2S_TEXT();

    // Destination check
    if (empty($_GET['to'])) {
        LOGERR('Text2Speech: addon/time2Dest.php: No destination address provided (?to=...).');
        return '';
    }
    $arrivalRaw = (string)$_GET['to'];
    LOGOK('Text2Speech: addon/time-to-destination-speech.php: Destination address detected.');

    // API key check
    $key = trim((string)($config['LOCATION']['googlekey'] ?? ''));
    if ($key === '') {
        LOGWARN('Text2Speech: addon/time-to-destination-speech.php: Google Maps API key is missing in Plugin Config');
        return '';
    }

    // Origin from config
    $street   = (string)($config['LOCATION']['googlestreet'] ?? '');
    $town     = (string)($config['LOCATION']['googletown']   ?? '');
    $startRaw = trim($street . ', ' . $town);

    // Traffic/model
    $traffic = isset($_GET['traffic']) ? '1' : '0';
    $valid   = ['pessimistic','best_guess','optimistic'];
    $traffic_model = (string)($_GET['model'] ?? 'best_guess');
    if (!in_array($traffic_model, $valid, true)) {
        LOGWARN('Text2Speech: addon/time-to-destination-speech.php: Invalid traffic model. Falling back to best_guess.');
        $traffic_model = 'best_guess';
    }

    $lang = 'de'; $mode = 'driving'; $units = 'metric'; $time = time();

    // Request
    $params = [
        'origins'        => $startRaw,
        'destinations'   => $arrivalRaw,
        'departure_time' => $time,
        'traffic_model'  => $traffic_model,
        'mode'           => $mode,
        'units'          => $units,
        'key'            => $key,
        'language'       => $lang,
    ];
    $request = 'https://maps.googleapis.com/maps/api/distancematrix/json?' .
               http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    // Fetch (short timeouts)
    $ctx = stream_context_create([
        'http' => ['timeout' => 5],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $jdata = @file_get_contents($request, false, $ctx);
    if ($jdata === false) {
        LOGERR('Text2Speech: addon/time-to-destination-speech.php: Network/timeout fetching Distance Matrix.');
        return '';
    }

    $data = json_decode($jdata, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_array($data)) {
        LOGERR('Text2Speech: addon/time-to-destination-speech.php: Invalid JSON response.');
        return '';
    }

    $status = $data['status'] ?? 'UNKNOWN';
    if ($status !== 'OK') {
        LOGERR("Text2Speech: addon/time-to-destination-speech.php: API status not OK ($status).");
        return '';
    }

    // Elements
    $elem = $data['rows'][0]['elements'][0] ?? null;
    if (!$elem || ($elem['status'] ?? '') !== 'OK') {
        $rowStatus = $elem['status'] ?? 'MISSING';
        LOGWARN("Text2Speech: addon/time-to-destination-speech.php: Element status not OK ($rowStatus).");
        return '';
    }

    // Distance & durations
    $distanceMeters = (int)($elem['distance']['value'] ?? 0);
    $distanceKm     = (int)round($distanceMeters / 1000, 0);

    $durationSec    = (int)($elem['duration']['value'] ?? 0);
    $durTrafficSec  = (int)($elem['duration_in_traffic']['value'] ?? 0);
    $useSec         = ($traffic === '1' && $durTrafficSec > 0) ? $durTrafficSec : $durationSec;

    $hours   = (int)floor($useSec / 3600);
    $minutes = (int)floor(($useSec % 3600) / 60);
    $seconds = $useSec % 60;
    if ($seconds >= 30) { $minutes++; }

    $arrival = $arrivalRaw;

    // Build text (success path)
    if ($traffic === '0') {
        $textpart1 = $TL['DESTINATION-TO-SPEECH']['TEXT_ANNOUNCEMENT1']." ".$distanceKm." ".$TL['DESTINATION-TO-SPEECH']['TEXT_ANNOUNCEMENT2']." ".$arrival." ".$TL['DESTINATION-TO-SPEECH']['TEXT_ANNOUNCEMENT3']." ". date("H", $time) ." ".$TL['DESTINATION-TO-SPEECH']['TEXT_ANNOUNCEMENT4']." ".date("i", $time)." ".$TL['DESTINATION-TO-SPEECH']['TEXT_ANNOUNCEMENT5']." ";
    } else {
        $textpart1 = $TL['DESTINATION-TO-SPEECH']['TEXT_ANNOUNCEMENT1']." ".$distanceKm." ".$TL['DESTINATION-TO-SPEECH']['TEXT_ANNOUNCEMENT2']." ".$arrival." ".$TL['DESTINATION-TO-SPEECH']['TEXT_ANNOUNCEMENT3']." ". date("H", $time) ." ".$TL['DESTINATION-TO-SPEECH']['TEXT_ANNOUNCEMENT4']." ".date("i", $time)." ".$TL['DESTINATION-TO-SPEECH']['TEXT_ANNOUNCEMENT6']." ";
    }

    if ($hours == 0 && $minutes == 1) {
        $textpart2 = $TL['DESTINATION-TO-SPEECH']['ONE_MINUTE'];
    } elseif ($hours == 0 && $minutes > 1) {
        $textpart2 = $minutes . $TL['DESTINATION-TO-SPEECH']['MORE_THEN_ONE_MINUTE'];
    } elseif ($hours == 1 && $minutes == 1) {
        $textpart2 = $TL['DESTINATION-TO-SPEECH']['ONE_HOUR_AND_MINUTES'];
    } elseif ($hours == 1 && $minutes >= 1) {
        $textpart2 = $TL['DESTINATION-TO-SPEECH']['ONE_HOUR_AND']." ".$minutes." ".$TL['DESTINATION-TO-SPEECH']['MORE_THEN_ONE_MINUTE'];
    } elseif ($hours > 1 && $minutes > 1) {
        $textpart2 = $hours . " ".$TL['DESTINATION-TO-SPEECH']['HOUR_AND_MINUTES']." ". $minutes." ".$TL['DESTINATION-TO-SPEECH']['MORE_THEN_ONE_MINUTE'];
    } else {
        $textpart2 = $TL['DESTINATION-TO-SPEECH']['ZERO_MINUTES'] ?? 'weniger als eine Minute';
    }

    $text = trim($textpart1 . $textpart2);

    LOGDEB(sprintf(
        'Text2Speech: addon/time-to-destination-speech.php: OK distance=%skm time=%s:%02d traffic=%s model=%s',
        $distanceKm, $hours, $minutes, $traffic, $traffic_model
    ));
    LOGOK('Text2Speech: addon/time-to-destination-speech.php: Text generated and passed to T2S.');

    return $text; // success → TTS text
}
?>
