<?php
/**
* Submodul: logging
*
**/

# suche nach evtl. vorhandenen Plugins die TTS nutzen
$pluginusage = glob("$lbhomedir/config/plugins/*/tts_plugin.cfg");
# Laden der Plugindb
$plugindb = LBSystem::get_plugins();
#print_r($plugindb);

#$alldata = array();
if (($pluginusage === false) or (empty($pluginusage)))  {
	#echo 'NO PLUGIN FOUND<br>';
	$ownplugindata = LBSystem::plugindata();
	$alldata[] = array(
						"logpath" => $lbplogdir,
						'pluginname' => $ownplugindata['PLUGINDB_NAME'], 
						'pluginfolder' => $ownplugindata['PLUGINDB_FOLDER'], 
						'loggingname' => "TTS", 
						'logfilename' => "error.log", 
						'loglevel' => $ownplugindata['PLUGINDB_LOGLEVEL'],
						"append" => 1,
						);
	
} else {
	#echo 'PLUGIN FOUND<br>';
	foreach($pluginusage as $plugfolder)  {
		$folder = explode('/',$plugfolder);
		$plugfolder = $folder[5];
		$myFolder = $lbhomedir."/config/plugins/".$plugfolder;
		$key = recursive_array_search($plugfolder,$plugindb);
		if (!file_exists($myFolder.'/tts_plugin.cfg')) {
			LOGGING('The file tts_plugin.cfg could not be opened, please try again!', 4);
		} else {
			$tmp_ini = parse_ini_file($myFolder.'/tts_plugin.cfg', TRUE);
			$folders = $lbhomedir."/log/plugins/".$plugfolder."/".$tmp_ini['SYSTEM']['logfilename'];
			$alldata[] = array(
								'logpath' => $folders, 
								'pluginname' => $plugindb[$key]['PLUGINDB_NAME'], 
								'pluginfolder' => $plugindb[$key]['PLUGINDB_FOLDER'], 
								'loggingname' => $tmp_ini['SYSTEM']['loggingname'], 
								'logfilename' => $tmp_ini['SYSTEM']['logfilename'], 
								'loglevel' => $plugindb[$key]['PLUGINDB_LOGLEVEL'],
								"append" => 1,
								);
			LOGGING("TTS Plugin config has been loaded", 5);
		}
	}
}
#print_r($alldata);
#echo count($alldata);

return $alldata;




/**
* Function : logging --> provide interface to LoxBerry logfile
*
* @param: 	empty
* @return: 	log entry
**/

function LOGGING($message = "", $loglevel = 7, $raw = 0)
{
	global $pcfg, $L, $config, $lbplogdir, $logfile, $alldata;
	#echo count($alldata);
	#print_r($alldata);
	foreach($alldata as $allplugin)  {
		
		$params = [
				"name" => $allplugin['loggingname'],
				"filename" => $allplugin['logpath']."/".$allplugin['logfilename'],
				"package" => $allplugin['pluginfolder'],
				"append" => 1,
				];
				#print_r($params);
		$log = LBLog::newLog($params);	
					
		if ($allplugin['loglevel'] >= intval($loglevel) || $loglevel == 8)  {
			switch ($loglevel) 	{
				case 0:
					#LOGEMERGE("$message");
					break;
				case 1:
					$log->ALERT("TTS -> $message");
					break;
				case 2:
					$log->CRIT("TTS -> $message");
					break;
				case 3:
					$log->ERR("TTS -> $message");
					break;
				case 4:
					$log->WARN("TTS -> $message");
					break;
				case 5:
					$log->OK("TTS -> $message");
					break;
				case 6:
					$log->INF("TTS -> $message");
					break;
				case 7:
					$log->DEB("TTS -> $message");
				default:
					break;
			}
			#echo $alldata[$i]['pluginfolder']."/".$alldata[$i]['logfilename'].'<br>';
			if ($loglevel < 2) {
				if (isset($message) && $message != "" )
				$notification = array (
									"PACKAGE" => $alldata[$i]['loggingname'],    // Mandatory
									"NAME" => $alldata[$i]['loggingname'],       // Mandatory           
									"MESSAGE" => $message, 							// Mandatory
									#"SEVERITY" => 3,
									#"fullerror" => "Access is denied: " . $error,
									#"msnumber" => 1,
									"LOGFILE" => $alldata[$i]['pluginfolder']."/".$alldata[$i]['logfilename']
										);
 
				notify_ext($notification);
			}
		}
		#return;
	}
}

/**
* Function : check_size_logfile --> check size of LoxBerry logfile
*
* @param: 	empty
* @return: 	empty
**/
function check_size_logfile()  {
	global $L;
	
	$logsize = @filesize(LBPLOGDIR."/error.log");
	if ( $logsize > 5242880 )
	{
		LOGGING($L["ERRORS.ERROR_LOGFILE_TOO_BIG"]." (".$logsize." Bytes)",4);
		LOGGING("Set Logfile notification: ".LBPPLUGINDIR." ".$L['BASIS.MAIN_TITLE']." => ".$L['ERRORS.ERROR_LOGFILE_TOO_BIG'],7);
		notify (LBPPLUGINDIR, $L['BASIS.MAIN_TITLE'], $L['ERRORS.ERROR_LOGFILE_TOO_BIG']);
		system("echo '' > ".LBPLOGDIR."/error.log");
	}
	return;
}
?>
