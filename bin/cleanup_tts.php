#!/usr/bin/env php
<?php

require_once "loxberry_system.php";
require_once "loxberry_log.php";

register_shutdown_function('shutdown');

$log = LBLog::newLog( [ "name" => "Cleanup", "stderr" => 1, "addtime" => 1 ] );

LOGSTART("Cleanup MP3 files");

$myConfigFolder = "$lbpconfigdir";								// get config folder
$myConfigFile = "tts_all.cfg";									// get config file

// Parsen der Konfigurationsdatei
if (!file_exists($myConfigFolder.'/tts_all.cfg')) {
	LOGCRIT('The file tts_all.cfg could not be opened, please try again!');
	exit;
} else {
	$config = parse_ini_file($myConfigFolder.'/tts_all.cfg', TRUE);
	LOGOK("T2S config has been loaded");
}

$folderpeace = explode("/",$config['SYSTEM']['path']);
if ($folderpeace[3] != "data") {
	// wenn NICHT local dir als Speichermedium selektiert wurde
	$MessageStorepath = $config['SYSTEM']['path']."/".$hostname."/tts/";
} else {
	// wenn local dir als Speichermedium selektiert wurde
	$MessageStorepath = $config['SYSTEM']['path']."/";
}

$storageinterval = !empty($config['MP3']['MP3store']) ? trim($config['MP3']['MP3store']) : "7";

// Check if we need to cleanup by time or by size
if(substr($storageinterval, -1) == "M") {
	$tosize = substr($storageinterval, 0, -1) * 1024*1024;
	if(empty($tosize)) {
		LOGCRIT("The size limit is not valid - stopping operation");
		LOGDEB("Config parameter MP3/MP3Store is {$config['MP3']['MP3store']}, tosize is '$tosize'");
		exit;
	}
	$storageinterval = null;
	delmp3();
	
} else {
	delmp3();
}

exit;


/**
/* Funktion : delmp3 --> löscht die hash5 codierten MP3 Dateien aus dem Verzeichnis 'messageStorePath'
/*
/* @param:  nichts
/* @return: nichts
**/

function delmp3() {
	global $config, $MessageStorepath, $storageinterval, $tosize;
		
	if($tosize) {
		LOGINF("Deleting oldest files to reach {$config['MP3']['MP3store']}B...");
		LOGTITLE("Cleanup to {$config['MP3']['MP3store']}B");
	} else {
		LOGINF("Deleting files older than {$config['MP3']['MP3store']} days...");
	}
	
	$dir = $MessageStorepath;
    
	// $folder = dir($dir);
	// // $store = '-'.$config['MP3']['MP3store'].' days';
	// while ($dateiname = $folder->read()) {
	    // if (filetype($dir.$dateiname) == "dir") continue;
        // if (strtotime($store) > @filemtime($dir.$dateiname)) {
			// if (strlen($dateiname) == 36) {
				// if (@unlink($dir.$dateiname) != false)
					// LOGINF($dateiname.' has been deleted');
				// else
					// LOGINF($dateiname.' could not be deleted');
			// }
		// }
        
    // }

	LOGDEB ("Directory: $dir");
	$files = glob("$dir/*");
	usort($files, function($a, $b) {
		return @filemtime($a) > @filemtime($b);
	});
	
	
	if (empty($tosize)) {
		/******************/
		/* Delete to time */
		$deltime = time() - $storageinterval * 24 * 60 * 60;
		foreach($files as $key => $file){
			if(!is_file($file)) {
				unset($files[$key]);
				continue;
			}
			$filetime = @filemtime($file);
			LOGDEB("Checking file ".basename($file)." (".date(DATE_ATOM, $filetime).")");
			if($filetime < $deltime && strlen($file) == 36) {
				if ( @unlink($file) != false )
					LOGINF(basename($file).' has been deleted');
				else
					LOGWARN(basename($file).' could not be deleted');
			}
		}
	} else { 
		/******************/
		/* Delete to size */
		// First get full size
		$fullsize = 0;
		foreach($files as $file){
			if(!is_file($file)) {
				unset($files[$key]);
				continue;
			}
			$fullsize += filesize($file);
		}
		
		// Are we below the limit? Then nothing to do
		if ($fullsize < $tosize) {
			LOGINF("Current size $fullsize is below destination size $tosize");
			LOGOK ("Nothing to do, quitting");
			exit;
		}

		// We need to delete
		$newsize = $fullsize;
		foreach($files as $file){
			$filesize = filesize($file);
			if ( @unlink($file) != false ) {
				LOGDEB(basename($file).' has been deleted');
				$newsize -= $filesize;
			} else {
				LOGWARN(basename($file).' could not be deleted');
			}
		
			// Check again after each file
			if ($newsize < $tosize) {
				LOGOK("New size $newsize reached destination size $tosize");
				break;
			}
		}
		
		// Check after all files
		if ($newsize > $tosize) {
			LOGERR("Used size $newsize is still greater than destination size $tosize - Something is strange.");
		}
		
	}
		
	LOGOK("T2S file reduction has completed");
    return; 	 
}

function shutdown()
{
	global $log;
	$log->LOGEND("Cleanup finished");
	
}


