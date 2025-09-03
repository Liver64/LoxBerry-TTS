<?php
function t2s($messageid, $MessageStorepath, $textstring, $filename)

// voicerss: Erstellt basierend auf Input eine TTS Nachricht, übermittelt sie an VoiceRRS und 
// speichert das zurückkommende file lokal ab

{
	global $config, $messageid, $lbphtmldir, $t2s_param, $pathlanguagefile;
		#echo "<PRE>";
		$apikey = $t2s_param['apikey'];
		$filename = $t2s_param['filename'];
		$textstring = $t2s_param['text'];
		$language = $t2s_param['language'];
		$voice = $t2s_param['voice'];
	
		$ttsaudiocodec = "44khz_16bit_mono";
		$textstring = urlencode($textstring);
		
		$langfile = "voicerss.json";
		$voicefile = "voicerss_voices.json";
		$voices = json_decode(file_get_contents($lbphtmldir."/voice_engines/langfiles/".$voicefile), TRUE);
		$valid_languages = json_decode(file_get_contents($lbphtmldir."/voice_engines/langfiles/".$langfile), TRUE);
		
		$language = $config['TTS']['messageLang'];
		$isvalid = array_multi_search($voice, $voices, $sKey = "name");
		$language = $isvalid[0]['language'];
		$voice = $isvalid[0]['name'];
							
		#####################################################################################################################
		# zu testen da auf Google Translate basierend (urlencode)
		# ersetzt Umlaute um die Sprachqualität zu verbessern
		# $search = array('ä','ü','ö','Ä','Ü','Ö','ß','°','%20','%C3%84','%C4','%C3%9C','%FC','%C3%96','%F6','%DF','%C3%9F');
		# $replace = array('ae','ue','oe','Ae','Ue','Oe','ss','Grad',' ','ae','ae','ue','ue','oe','oe','ss','ss');
		# $textstring = str_replace($search,$replace,$textstring);
		#####################################################################################################################	

		# Generieren des strings der an VoiceRSS geschickt wird
		$inlay = "key=$apikey&src=$textstring&hl=$language&v=$voice&f=$ttsaudiocodec";	
									
		# Speicherort der MP3 Datei
		$file = $config['SYSTEM']['ttspath'] ."/". $filename . ".mp3";
					
		# Übermitteln des strings an VoiceRSS.org
		ini_set('user_agent', 'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2228.0 Safari/537.36');
		$mp3 = file_get_contents('http://api.voicerss.org/?' . $inlay);
		file_put_contents($file, $mp3);
		LOGOK('voice_engines/VoiceRSS.php: The text has been passed to VoiceRSS engine for MP3 creation');
		LOGOK("voice_engines/VoiceRSS.php: MP3 file has been sucesfully saved.");	
		# Ersetze die messageid durch die von TTS gespeicherte Datei
		$messageid = $filename;
		return ($messageid);
				  	
}

?>

