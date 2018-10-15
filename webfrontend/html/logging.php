<?php
/**
* Submodul: logging
*
**/
$logging_config = "tts_logging.cfg";		// fixed filename to pass log entries to ext. Prog.

$level = LBSystem::pluginloglevel();

# suche nach evtl. vorhandenen Plugins die TTS nutzen
$pluginusage = glob("$lbhomedir/config/plugins/*/".$logging_config);
# Laden der Plugindb
$plugindb = LBSystem::get_plugins();
$alldata = array();
#echo 'PLUGIN FOUND<br>';
foreach($pluginusage as $plugfolder)  {
	$folder = explode('/',$plugfolder);
	$plugfolder = $folder[5];
	$myFolder = $lbhomedir."/config/plugins/".$plugfolder;
	$key = recursive_array_search($plugfolder,$plugindb);
	if (!file_exists($myFolder.'/'.$logging_config)) {
		LOGGING('The file '.$logging_config.' could not be opened, please try again!', 4);
	} else {
		$tmp_ini = parse_ini_file($myFolder.'/'.$logging_config, TRUE);
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
		LOGGING("TTS Logging config '".$logging_config."' has been loaded", 5);
	}
}
#print_r($alldata);
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
	foreach($alldata as $allplugin)  {
		$params = [
				"name" => $allplugin['loggingname'],
				"filename" => $allplugin['logpath'],
				"package" => $allplugin['pluginfolder'],
				"append" => 1,
				];
				#print_r($params);
		LBLog::newLog($params);	
					
		if ($allplugin['loglevel'] >= intval($loglevel) || $loglevel == 8)  {
			switch ($loglevel) 	{
				case 0:
		        #LOGEMERGE("$message");
		        break;
				case 1:
					LOGALERT("$message");
				break;
				case 2:
					LOGCRIT("$message");
				break;
				case 3:
					LOGERR("$message");
				break;
				case 4:
					LOGWARN("$message");
				break;
				case 5:
					LOGOK("$message");
				break;
				case 6:
					LOGINF("$message");
				break;
				case 7:
					LOGDEB("$message");
				default:
		        break;
			}
			#echo $alldata[$i]['pluginfolder']."/".$alldata[$i]['logfilename'].'<br>';
			if ($loglevel < 4) {
				if (isset($message) && $message != "" )
				$notification = array (
									"PACKAGE" => $allplugin['pluginfolder'],    // Mandatory
									"NAME" => $allplugin['loggingname'],       // Mandatory           
									"MESSAGE" => $message, 							// Mandatory
									"LOGFILE" => $allplugin['logpath']
									);
				#print_r($notification);
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
	global $L, $allplugin, $alldata;
	
	$logsize = @filesize($allplugin['logpath']);
	if ( $logsize > 5242880 )
	{
		LOGGING($L["ERRORS.ERROR_LOGFILE_TOO_BIG"]." (".$logsize." Bytes)",4);
		LOGGING("Set Logfile notification: ".$alldata[0]['pluginfolder']." ".$L['BASIS.MAIN_TITLE']." => ".$L['ERRORS.ERROR_LOGFILE_TOO_BIG'],7);
		notify ($alldata[0]['pluginfolder'], $L['BASIS.MAIN_TITLE'], $L['ERRORS.ERROR_LOGFILE_TOO_BIG']);
		system("echo '' > ".$alldata[0]['logpath']);
	}
	return;
}
?>
