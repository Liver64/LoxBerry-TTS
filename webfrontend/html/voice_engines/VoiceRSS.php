<?php
function t2s($messageid, $MessageStorepath, $textstring, $filename)

// voicerss: Erstellt basierend auf Input eine TTS Nachricht, übermittelt sie an VoiceRRS und 
// speichert das zurückkommende file lokal ab

# 08/03/2018 added $ttsaudiocodec from sonos.cfg
{
	global $config, $messageid, $t2s_param, $pathlanguagefile;
	
		$apikey = $t2s_param['apikey'];
		$filename = $t2s_param['filename'];
		$textstring = $t2s_param['text'];
		$language = $t2s_param['language'];
	
		$ttsaudiocodec = "44khz_16bit_mono";
		$textstring = urlencode($textstring);
		
		$file = "voicerss.json";
		$url = $pathlanguagefile."".$file;
		$valid_languages = File_Get_Array_From_JSON($url, $zip=false);
			if (isset($_GET['lang'])) {
				$language = $_GET['lang'];
				$isvalid = array_multi_search($language, $valid_languages, $sKey = "value");
				if (!empty($isvalid)) {
					$language = $_GET['lang'];
					LOGGING('voice_engines/VoiceRSS.php: T2S language has been successful entered',5);
				} else {
					LOGGING("voice_engines/VoiceRSS.php: The entered VoiceRSS language key is not supported. Please correct (see Wiki)!",3);
					exit;
				}
			} else {
				$language = $config['TTS']['messageLang'];
			}	
								
		#####################################################################################################################
		# zu testen da auf Google Translate basierend (urlencode)
		# ersetzt Umlaute um die Sprachqualität zu verbessern
		# $search = array('ä','ü','ö','Ä','Ü','Ö','ß','°','%20','%C3%84','%C4','%C3%9C','%FC','%C3%96','%F6','%DF','%C3%9F');
		# $replace = array('ae','ue','oe','Ae','Ue','Oe','ss','Grad',' ','ae','ae','ue','ue','oe','oe','ss','ss');
		# $textstring = str_replace($search,$replace,$textstring);
		#####################################################################################################################	

		# Generieren des strings der an VoiceRSS geschickt wird
		$inlay = "key=$apikey&src=$textstring&hl=$language&f=$ttsaudiocodec";	
									
		# Speicherort der MP3 Datei
		$file = $config['SYSTEM']['ttspath'] ."/". $filename . ".mp3";
					
		# Prüfung ob die MP3 Datei bereits vorhanden ist
		#if (!file_exists($file)) 
		#{
			# Übermitteln des strings an VoiceRSS.org
			ini_set('user_agent', 'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2228.0 Safari/537.36');
			$mp3 = file_get_contents('http://api.voicerss.org/?' . $inlay);
			file_put_contents($file, $mp3);
			LOGGING('voice_engines/VoiceRSS.php: The text has been passed to VoiceRSS engine for MP3 creation',5);
			LOGGING("voice_engines/VoiceRSS.php: MP3 file has been sucesfully saved.", 6);	
		#} else {
		#	LOGGING('voice_engines/VoiceRSS.php: Requested T2s has been grabbed from cache',6);
		#}
	# Ersetze die messageid durch die von TTS gespeicherte Datei
	$messageid = $filename;
	return ($messageid);
				  	
}

?>

