<?php
function t2s($messageid, $MessageStorepath, $textstring, $filename)

// polly: Erstellt basierend auf Input eine TTS Nachricht, übermittelt sie an Ivona.com und 
// speichert das zurückkommende file lokal ab
{
	set_include_path(__DIR__ . '/polly_tts');
	
	global $config, $messageid, $t2s_param, $lbphtmldir, $voice, $accesskey, $secretkey, $pathlanguagefile;
		
		include_once 'polly_tts/polly.php';
		
		$accesskey = $t2s_param['apikey'];
		$secretkey = $t2s_param['secretkey'];
		$filename = $t2s_param['filename'];
		$textstring = $t2s_param['text'];
		$language = $t2s_param['language'];
		$tmp_voice = $t2s_param['voice'];
		
		$voicefile = "polly_voices.json";
		$urlvoice = $pathlanguagefile."".$voicefile;
		#$valid_voices = File_Get_Array_From_JSON($urlvoice, $zip=false);
		$valid_voices = json_decode(file_get_contents($lbphtmldir."/voice_engines/langfiles/".$voicefile), TRUE);
		if (isset($_GET['voice'])) {
			$tmp_voice = $_GET['voice'];
				$valid_voice = array_multi_search($tmp_voice, $valid_voices, $sKey = "name");
				if (!empty($valid_voice)) {
					$language = $valid_voice[0]['language'];
					$voice = $valid_voice[0]['name'];
					LOGOK('voice_engines/Polly.php: T2S language/voice has been successful entered');
				} else {
					LOGERR("voice_engines/Polly.php: The entered Polly voice is not supported. Please correct (see Wiki)!");
					exit;
				}
		} else {
			$language = $config['TTS']['messageLang'];
			$voice = $tmp_voice;
		}
				
		#####################################################################################################################
		# Zum Testen da auf Google Translate basierend (urlencode)
		# ersetzt Umlaute um die Sprachqualität zu verbessern
		# $search = array('ä','ü','ö','Ä','Ü','Ö','ß','°','%20','%C3%84','%C4','%C3%9C','%FC','%C3%96','%F6','%DF','%C3%9F');
		# $replace = array('ae','ue','oe','Ae','Ue','Oe','ss','Grad',' ','ae','ae','ue','ue','oe','oe','ss','ss');
		# $textstring = str_replace($search,$replace,$textstring);
		#####################################################################################################################
		
		#-- Aufruf der POLLY Class zum generieren der t2s --
		$a = new POLLY_TTS();
		$a->save_mp3($textstring, $config['SYSTEM']['ttspath']."/".$filename.".mp3", $language, $voice);
		LOGOK('voice_engines/Polly.php: The text has been passed to Polly engine for MP3 creation');
		LOGOK("voice_engines/Polly.php: MP3 file has been sucesfully saved.");	
		$messageid = $filename;
		return ($messageid);
}


?> 