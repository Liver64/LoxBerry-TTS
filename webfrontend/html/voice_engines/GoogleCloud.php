<?php

function t2s($t2s_param)

// google: Erstellt basierend auf Input eine TTS Nachricht, übermittelt sie an Google.com und 
// speichert das zurückkommende file lokal ab

{
	global $config;
		
		if (isset($_GET['lang'])) {
			$language = $_GET['lang'];
		} else {
			$language = $config['TTS']['messageLang'];
		}
		
		if (isset($_GET['voice'])) {
			$voice = $_GET['voice'];
			$language = substr($voice, 5); 
		} else {
			$voice = $config['TTS']['voice'];
		}
								  		
		LOGINF("voice_engines/GoogleCloud.php: Google Cloud TTS has been successful selected");	


		$params = [
			"audioConfig"=>[
				"audioEncoding"=>"MP3"
			],
			"input"=>[
				"text"=>$textstring
			],
			"voice"=>[
				"languageCode"=> $language,
				"name" => $voice
			]
		];
		$data_string = json_encode($params);
		$speech_api_key = $config['TTS']['API-key'];
		$url = 'https://texttospeech.googleapis.com/v1/text:synthesize';

		$handle = curl_init($url);

		curl_setopt($handle, CURLOPT_CUSTOMREQUEST, "POST"); 
		curl_setopt($handle, CURLOPT_POSTFIELDS, $data_string);  
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($handle, CURLOPT_HTTPHEADER, [                                                                          
			'Content-Type: application/json',                                                                                
			'Content-Length: ' . strlen($data_string),
			'X-Goog-Api-Key: ' . $speech_api_key
			]                                                                       
		);
		$response = curl_exec($handle);            
		$responseDecoded = json_decode($response, true);  
		curl_close($handle);
		#print_r($responseDecoded);
		
		if (array_key_exists('audioContent', $responseDecoded)) {
			# Speicherort der MP3 Datei
			$file = $config['SYSTEM']['ttspath'] ."/". $filename . ".mp3";
			file_put_contents($file, base64_decode($responseDecoded['audioContent']));  
			LOGOK('voice_engines/GoogleCloud.php: The text has been passed to Google cloud TTS for MP3 creation');
			return ($filename); 	
		} else {
			# Error handling
			LOGERR('voice_engines/GoogleCloud.php: Google Cloud TTS failed. Please check error message snd investigate');			
			LOGERR($responseDecoded['error']['message']);
			exit(1);
		}

		LOGOK('voice_engines/GoogleCloud.php: Something went wrong!');
		return;
}
