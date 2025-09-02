<?php

require_once "loxberry_system.php";

if (file_exists(LBPCONFIGDIR.'/t2s_config.json')) {
	@unlink(LBPCONFIGDIR.'/tts_all.cfg');
	echo "<INFO> New JSON Config already exist, no migration needed :-)".PHP_EOL;
	exit;
}
// Parsen der alten INI Konfigurationsdatei
if (file_exists(LBPCONFIGDIR.'/tts_all.cfg')) {
	$config = parse_ini_file(LBPCONFIGDIR.'/tts_all.cfg', TRUE);
	echo "<OK> CFG configuration successfully parsed".PHP_EOL;
} else {
	exit;
}

# rename API Key field and Secret Key fields in config
$tmp1 = $config['TTS']['API-key'];
$tmp2 = $config['TTS']['secret-key'];
$config['TTS']['apikey'] = $config['TTS']['API-key'];
$config['TTS']['secretkey'] = $config['TTS']['secret-key'];
$config['TTS']['apikey'] = $tmp1;
$config['TTS']['secretkey'] = $tmp2;
unset($config['TTS']['secret-key']);
unset($config['TTS']['API-key']);
echo "<INFO> Config keys successfully migrated".PHP_EOL;

#echo '<PRE>';
#print_r($config);

if (!file_exists(LBPCONFIGDIR.'/t2s_config.json')) {
	try {
		file_put_contents(LBPCONFIGDIR."/t2s_config.json", json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_PRETTY_PRINT));
		echo "<OK> New JSON config file has been successful saved".PHP_EOL;
	} catch (Exception $e) {
		require_once "loxberry_log.php";
		echo "<ERROR> New JSON config file could not be written".PHP_EOL;
		$notification = array (
            "PACKAGE" => $lbpplugindir, 
            "NAME" => "Text-to-speech",           
            "MESSAGE" => "The File '".LBPCONFIGDIR."/t2s_config.json' could not be written! Please check your '".LBPCONFIGDIR."/tts_all.cfg' file",
            "SEVERITY" => 3,
            "fullerror" => "Error: " . $error
		);
		notify_ext($notification);
		exit;
	}
}
if (file_exists(LBPCONFIGDIR.'/t2s_config.json')) {
	@unlink(LBPCONFIGDIR.'/tts_all.cfg');
	echo "<OK> Old CFG config file has been successful deleted".PHP_EOL;
}

?>