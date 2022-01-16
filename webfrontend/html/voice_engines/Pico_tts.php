<?php
function t2s($messageid, $MessageStorepath, $textstring, $filename)

// pico: Erstellt basierend auf Input eine TTS Nachricht mit Pico2Wave
// http://lame.sf.net


{
	global $config, $messageid, $pathlanguagefile, $MessageStorepath, $textstring, $filename;
		
		$textstring = ($textstring);
		$file = "pico.json";
		$url = $pathlanguagefile."".$file;
		$valid_languages = File_Get_Array_From_JSON($url, $zip=false);
		
		if (isset($_GET['lang'])) {
			$language = $_GET['lang'];
			$isvalid = array_multi_search($language, $valid_languages, $sKey = "value");
			if (!empty($isvalid)) {
				$ttslanguage = $_GET['lang'];
				LOGGING('voice_engines/Pico_tts.php: T2S language has been successful entered',5);				
			} else {
				LOGGING("voice_engines/Pico_tts.php: The entered Pico language key is not supported. Please correct (see Wiki)!",3);
				exit;
			}
		} else {
			$ttslanguage = $config['TTS']['messageLang'];
		}	
		
		$file = $config['SYSTEM']['ttspath'] ."/". $filename . ".wav";
					
		# Prüfung ob die Voice Datei bereits vorhanden ist
		#if (!file_exists($file)) 
		#{
			# Übermitteln des Strings an Pico und lame zu MP3
			try {
				exec('/usr/bin/pico2wave -l=' . $ttslanguage . ' -w=' . $file . ' "'.$textstring.'"');
				#exit;
				exec('/usr/bin/lame '.$config['SYSTEM']['ttspath'] . $filename . ".wav".' '.$config['SYSTEM']['ttspath'] . $filename . ".mp3");
				LOGGING('voice_engines/Pico_tts.php: The text has been passed to Pico engine for MP3 creation',5);
				LOGGING("voice_engines/Pico_tts.php: MP3 file has been sucesfully saved.", 6);	
				unlink($config['SYSTEM']['ttspath'] . $filename . ".wav");
			} catch(Exception $e) {
				LOGGING("voice_engines/Pico_tts.php: The T2S could not be created! Please try again.",4);
			}
		#}
	
	# Ersetze die messageid durch die von TTS gespeicherte Datei
	$messageid = $filename;
	return ($messageid);
}

?>