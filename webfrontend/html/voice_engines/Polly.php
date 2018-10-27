<?php
function t2s($messageid, $MessageStorepath, $textstring, $filename)

// polly: Erstellt basierend auf Input eine TTS Nachricht, �bermittelt sie an Ivona.com und 
// speichert das zur�ckkommende file lokal ab
{
	set_include_path(__DIR__ . '/polly_tts');
	
	global $config, $messageid, $voice, $accesskey, $secretkey, $pathlanguagefile;
		
		include_once 'polly_tts/polly.php';
		
		$voicefile = "polly_voices.json";
		$urlvoice = $pathlanguagefile."".$voicefile;
		$valid_voices = File_Get_Array_From_JSON($urlvoice, $zip=false);
		if (isset($_GET['voice'])) {
			$tmp_voice = $_GET['voice'];
				$valid_voice = array_multi_search($tmp_voice, $valid_voices, $sKey = "name");
				if (!empty($valid_voice)) {
					$language = $valid_voice[0]['language'];
					$voice = $valid_voice[0]['name'];
					LOGGING('T2S language/voice has been successful entered',5);
				} else {
					LOGGING("The entered Polly voice is not supported. Please correct (see Wiki)!",3);
					exit;
				}
		} else {
			$language = $config['TTS']['messageLang'];
			$voice = $config['TTS']['voice'];
		}
				
		#####################################################################################################################
		# Zum Testen da auf Google Translate basierend (urlencode)
		# ersetzt Umlaute um die Sprachqualit�t zu verbessern
		# $search = array('�','�','�','�','�','�','�','�','%20','%C3%84','%C4','%C3%9C','%FC','%C3%96','%F6','%DF','%C3%9F');
		# $replace = array('ae','ue','oe','Ae','Ue','Oe','ss','Grad',' ','ae','ae','ue','ue','oe','oe','ss','ss');
		# $textstring = str_replace($search,$replace,$textstring);
		#####################################################################################################################
		
		#-- Aufruf der POLLY Class zum generieren der t2s --
		$a = new POLLY_TTS();
		$a->save_mp3($textstring, $config['SYSTEM']['ttspath']."/".$filename.".mp3", $language, $voice);
		LOGGING('The text has been passed to Polly engine for MP3 creation',5);
		LOGGING("MP3 file has been sucesfully saved.", 6);	
		$messageid = $filename;
		return ($messageid);
}


?> 