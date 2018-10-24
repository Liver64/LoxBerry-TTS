<?php
/**
* Submodul: logging
*
**/

/**
* Function : logging --> provide interface to LoxBerry logfile
*
* @param: 	empty
* @return: 	log entry
**/

function LOGGING($message = "", $loglevel = 7, $raw = 0)
{
	global $pcfg, $L, $config, $lbplogdir, $logfile, $plugindata;
	
	if ($plugindata['PLUGINDB_LOGLEVEL'] >= intval($loglevel) || $loglevel == 8)  {
		($raw == 1)?$message="<br>".$message:$message=htmlentities($message);
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
		if ($loglevel < 4) {
			if (isset($message) && $message != "" ) notify (LBPPLUGINDIR, $L['BASIS.MAIN_TITLE'], $message);
		}
	}
	return;
}

# ** NICHT AKTIV **

/**
* Function : check_size_logfile --> check size of LoxBerry logfile
*
* @param: 	empty
* @return: 	empty
**/
function check_size_logfile()  {
	global $L;
	
	if (!is_file(LBPLOGDIR."/sonos.log"))   {
		fopen(LBPLOGDIR."/sonos.log", "w");
	} else {
		$logsize = filesize(LBPLOGDIR."/sonos.log");
		if ( $logsize > 5242880 )  {
			LOGGING($L["ERRORS.ERROR_LOGFILE_TOO_BIG"]." (".$logsize." Bytes)",4);
			LOGGING("Set Logfile notification: ".LBPPLUGINDIR." ".$L['BASIS.MAIN_TITLE']." => ".$L['ERRORS.ERROR_LOGFILE_TOO_BIG'],7);
			notify (LBPPLUGINDIR, $L['BASIS.MAIN_TITLE'], $L['ERRORS.ERROR_LOGFILE_TOO_BIG']);
			system("echo '' > ".LBPLOGDIR."/sonos.log");
		}
		return;
	}
}


/**
* Function : get_interface_config --> provide interface to LoxBerry logfile
*
* @param: 	empty
* @return: 	array 	name => package name
*					filename => pfad und Dateiname zum Logfile
*					append => 1		
**/
function get_interface_config()  {
	
global $lbhomedir;

$logging_config = "interface.cfg";		// fixed filename to pass log entries to ext. Prog.

$level = LBSystem::pluginloglevel();
# suche nach evtl. vorhandenen Plugins die T2S nutzen
$pluginusage = glob("$lbhomedir/config/plugins/*/".$logging_config);
# Laden der Plugindb
$plugindb = LBSystem::get_plugins();
$alldata = array();
foreach($pluginusage as $plugfolder)  {
	$folder = explode('/',$plugfolder);
	$plugfolder = $folder[5];
	$myFolder = $lbhomedir."/config/plugins/".$plugfolder;
	$key = recursive_array_search($plugfolder,$plugindb);
	if (!file_exists($myFolder.'/'.$logging_config)) {
		LOGGING('The file '.$logging_config.' could not be opened, please try again!', 4);
	} else {
		$tmp_ini = parse_ini_file($myFolder.'/'.$logging_config, TRUE);
		$folders = $lbhomedir."/log/plugins/".$plugfolder."/".$tmp_ini['SYSTEM']['NAME_LOGFILE'];
		$alldata[] = array(
							'name' => $tmp_ini['SYSTEM']['PLUGINDB_NAME'], 
							'filename' => $folders, 
							"append" => 1,
							);
		LOGGING("TTS Logging config '".$logging_config."' has been loaded", 5);
	}
}
#print_r($pluginusage);
#print_r($alldata);
return $alldata;
}


?>
