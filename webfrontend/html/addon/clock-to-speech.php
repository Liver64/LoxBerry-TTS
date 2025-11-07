<?php
function c2s()

// clock-to-speech: Erstellt basierend auf der aktuellen Uhrzeit eine TTS Nachricht, übermittelt sie an VoiceRRS und 
// speichert das zurückkommende file lokal ab
// @Parameter = $ttext von sonos2.php
{
	global $debug;
	
	#********************** NEW get text variables*********** ***********
	$TL = load_t2s_text();
			
	$Stunden = (int)strftime("%H");
	$Minuten = (int)strftime("%M");

	if ($Stunden >= 6 && $Stunden < 11) {
		$Vorspann = $TL['CLOCK-TO-SPEECH']['GREETING_6AM_to_11AM'];
	} elseif ($Stunden >= 11 && $Stunden < 17) {
		$Vorspann = $TL['CLOCK-TO-SPEECH']['GREETING_11AM_to_5PM'];
	} elseif ($Stunden >= 17 && $Stunden < 22) {
		$Vorspann = $TL['CLOCK-TO-SPEECH']['GREETING_5PM_to_10PM'];
	} elseif ($Stunden >= 22) {
		$Vorspann = $TL['CLOCK-TO-SPEECH']['GREETING_AFTER_10PM'];
	} else {
		$Vorspann = $TL['CLOCK-TO-SPEECH']['GREETING_DEFAULT'];
	}

	if ($Stunden >= 6 && $Stunden < 8) {
		$Nachsatz = " ";
	} else {
		$Nachsatz = "";
	}

	
	switch ($Stunden) 
	{
		# ergänzender Satz für die Zeit zwischen 6:00 und 8:00h (z.B. an Schultagen)
		case $Stunden >=6 && $Stunden <8:
			$Nachsatz=" ";
		break;
		# ergänzender Satz für die Zeit nach 8:00h
		case $Stunden >=8:
			$Nachsatz="";
		break;
		default:
			$Nachsatz="";
		break;
	}
	
	$ttext = $Vorspann." ".$TL['CLOCK-TO-SPEECH']['TEXT_BEFORE_HOUR_ANNOUNCEMENT']." ".$Stunden." ".$TL['CLOCK-TO-SPEECH']['TEXT_BEFORE_MINUTE_ANNOUNCEMENT']." ".$Minuten. " ".$TL['CLOCK-TO-SPEECH']['TEXT_AFTER_MINUTE_ANNOUNCEMENT']." ".$Nachsatz;
	$text = ($ttext);
	$_GET['nocache'] = '1';
	
	LOGINF('Text2Speech: addon/clock.php: Time Announcement: '.$ttext);
	LOGINF('Text2Speech: addon/clock.php: Message been generated and pushed to T2S creation');
	return ($text);
}	
?>
