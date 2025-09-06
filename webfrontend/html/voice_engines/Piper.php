<?php
function t2s($t2s_param)

// Piper: Erstellt basierend auf Input eine TTS Nachricht mit Piper
// http://lame.sf.net

{
	global $config, $pathlanguagefile;
	
		$filename = $t2s_param['filename'];
		$textstring = $t2s_param['text'];
		$voice = $t2s_param['voice'];
		
		$voicefile = "piper_voices.json";
		$piperdet = json_decode(file_get_contents(LBPHTMLDIR."/voice_engines/langfiles/".$voicefile), TRUE);
		$urlvoice = $pathlanguagefile."".$voicefile;
		#$valid_voices = File_Get_Array_From_JSON($urlvoice, $zip=false);
		#$valid_voices = json_decode(file_get_contents(LBPHTMLDIR."/voice_engines/langfiles/".$langfile), TRUE);
	
		$valid_voice = array_multi_search($voice, $piperdet, $sKey = "name");
		$pipervoicefile = $valid_voice[0]['filename'];

		if (isset($_GET['speaker'])) {
			$speakerraw = $_GET['speaker'];
			if ($speakerraw >= 0 && $speakerraw < 8)   {
				$speaker = $speakerraw;
			} else {
				LOGERR("voice_engines\Piper.php: Please check your speaker value in URL. Must be between 0 to 7");
				exit;				
			}
		} else {
			$speaker = "4";
		}
		LOGINF("voice_engines\Piper.php: Piper has been successful selected");	
		# Übermitteln des Strings an Piper
		try {
			exec('echo '.$textstring.' | piper -m '.LBPHTMLDIR.'/voice_engines/piper-voices/'.$pipervoicefile.' -f '.$config['SYSTEM']['ttspath'] .'/'. $filename . '.wav --speaker '.$speaker);
			exec('/usr/bin/lame '.$config['SYSTEM']['ttspath'] ."/". $filename . ".wav".' '.$config['SYSTEM']['ttspath'] ."/". $filename . ".mp3");
			unlink($config['SYSTEM']['ttspath'] ."/". $filename . ".wav");
		} catch(Exception $e) {
			LOGWARN("voice_engines\Piper.php: The T2S could not be created! Please try again.");
		}
		LOGOK('voice_engines\Piper.php: The text has been passed to Piper engine for MP3 creation');
		return $filename;
}

?>