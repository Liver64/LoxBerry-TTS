<?php
/**
 * Piper Text-to-Speech
 * 
 * Nutzung:
 * $t2s_param = [
 *     'filename'  => 'testfile',
 *     'text'      => 'Hallo Welt!',
 *     'voice'     => 'Thorsten',
 * ];
 */
 
function t2s($t2s_param)
{
    global $config;

    $filename   = $t2s_param['filename'] ?? 'tts_output';
    $text       = $t2s_param['text'] ?? '';
    $voice      = $t2s_param['voice'] ?? '';
    $speaker    = isset($_GET['speaker']) ? max(0, min(7, (int)$_GET['speaker'])) : 4;

    // ---------- CACHE ----------
    $ttspath  = rtrim($config['SYSTEM']['ttspath'] ?? '/tmp', '/');
    $mp3Path  = "$ttspath/$filename.mp3";
    //if (is_file($mp3Path) && filesize($mp3Path) > 0) {
     //   LOGINF("voice_engines/Piper.php: Cache hit ($mp3Path)");
    //    return basename($mp3Path, '.mp3');
    //}

    // ---------- Voice-Model ----------
    $voicefile = LBPHTMLDIR . "/voice_engines/langfiles/piper_voices.json";
    if (!is_file($voicefile)) { LOGERR("voice_engines/Piper.php: Voice file not found: $voicefile"); return false; }

    $voices = json_decode(file_get_contents($voicefile), true);
    if (!$voices) { LOGERR("voice_engines/Piper.php: Failed to decode voice file."); return false; }

    $match = array_multi_search($voice, $voices, "name");
    if (empty($match[0]['filename'])) {
        LOGERR("voice_engines/Piper.php: Voice '$voice' not in list.");
        return false;
    }
    $modelFile = LBPHTMLDIR . "/voice_engines/piper-voices/" . $match[0]['filename'];
	
	if (isset($_GET['speaker']))   {
		LOGINF("voice_engines/Piper.php: Voice '$voice' speaker $speaker");
	} else {
		LOGINF("voice_engines/Piper.php: Voice '$voice'");
	}

    // ---------- OPTIONAL: Piper-Flags (zuerst leeres Array definieren!) ----------
    $piperOpts = []; // Wichtig: existiert jetzt sicher
    // Beispiele (nur aktivieren, wenn deine Piper-Version sie unterstützt):
    // $piperOpts[] = '--sentence_silence 0.1';
    // $piperOpts[] = '--length_scale 0.95';

    // ---------- Encoding Profile ----------
    $profile = $t2s_param['encode_profile'] ?? ($_GET['encode'] ?? 'fast'); // 'fast'|'balanced'|'hq'
    $ff_fast = '-codec:a libshine -ar 44100 -ac 1 -b:a 96k';   // schnell + kompatibel
    $ff_bal  = '-codec:a libmp3lame -qscale:a 5 -ac 1';        // solide
    $ff_hq   = '-codec:a libmp3lame -qscale:a 2 -ac 1';        // beste Qualität
    $ffmpegCodec = ($profile === 'fast') ? $ff_fast : (($profile === 'balanced') ? $ff_bal : $ff_hq);

    // ---------- Primärweg: STDOUT-WAV -> ffmpeg ----------
    $cmdPipe = 'bash -lc ' . escapeshellarg(
        'set -o pipefail; ' .
        'printf %s ' . escapeshellarg($text) .
        ' | piper -m ' . escapeshellarg($modelFile) .
        ' --speaker ' . escapeshellarg((string)$speaker) . ' ' .
        implode(' ', $piperOpts) . ' ' .
        '--output_file - ' . // WAV → STDOUT
        ' | /usr/bin/ffmpeg -y -hide_banner -loglevel error -f wav -i pipe:0 ' .
        $ffmpegCodec . ' ' . escapeshellarg($mp3Path) . ' 2>&1'
    );

    $out = []; $rc = 0;
    exec($cmdPipe, $out, $rc);
    $ok = ($rc === 0 && is_file($mp3Path) && filesize($mp3Path) > 0);

    // ---------- Fallback: bei 'fast' einmalig mit LAME retryn ----------
    if (!$ok && $profile === 'fast') {
        $retryCodec = $ff_bal; // libmp3lame, q=5
        $cmdRetry = 'bash -lc ' . escapeshellarg(
            'set -o pipefail; ' .
            'printf %s ' . escapeshellarg($text) .
            ' | piper -m ' . escapeshellarg($modelFile) .
            ' --speaker ' . escapeshellarg((string)$speaker) . ' ' .
            implode(' ', $piperOpts) . ' ' .
            '--output_file - ' .
            ' | /usr/bin/ffmpeg -y -hide_banner -loglevel error -f wav -i pipe:0 ' .
            $retryCodec . ' ' . escapeshellarg($mp3Path) . ' 2>&1'
        );
        $out2 = []; $rc2 = 0;
        exec($cmdRetry, $out2, $rc2);
        $ok = ($rc2 === 0 && is_file($mp3Path) && filesize($mp3Path) > 0);
        if (!$ok) {
            LOGERR("voice_engines/Piper.php: ffmpeg failed (fast->lame fallback). Exit: $rc2");
            if (!empty($out2)) LOGDEB("ffmpeg(retry): " . implode("\n", $out2));
        }
    }

    if (!$ok) {
        LOGERR("voice_engines/Piper.php: MP3 could not be created: $mp3Path");
        if (!empty($out)) LOGDEB("pipeline: " . implode("\n", $out));
        return false;
    }

    LOGOK("voice_engines/Piper.php: MP3 created: $mp3Path (profile=$profile)");
	LOGOK("voice_engines/Piper.php: MP3 file successfully saved");
    return basename($mp3Path, '.mp3');
}
?>
