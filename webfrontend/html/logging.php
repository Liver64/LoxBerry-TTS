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
	global $pcfg, $L, $config, $log, $lbplogdir, $logfile, $plugindata, $logif;
	
	if ($plugindata['PLUGINDB_LOGLEVEL'] >= intval($loglevel) || $loglevel == 8 || $log->loglevel == 7)  {
		($raw == 1)?$message="<br>".$message:$message=htmlentities($message);
		
		#echo $log->loglevel;
		switch ($loglevel) 	{
		case 0:
		        //OFF
		        break;
		case 1:
		    LOGALERT("$message");
			array_push($logif, array("ALERT" => $message));
		        break;
		case 2:
		    LOGCRIT("$message");
			array_push($logif, array("CRITICAL" => $message));
		        break;
		case 3:
		    LOGERR("$message");
			array_push($logif, array("ERROR" => $message));
		        break;
		case 4:
			LOGWARN("$message");
			array_push($logif, array("WARNING" => $message));
		        break;
		case 5:
			LOGOK("$message");
			array_push($logif, array("OK" => $message));
			    break;
		case 6:
			LOGINF("$message");
			array_push($logif, array("INFO" => $message));
		        break;
		case 7:
			LOGDEB("$message");
			array_push($logif, array("DEB" => $message));
			default:
		        break;
		}
		if ($loglevel < 4) {
			#if (isset($message) && $message != "" ) notify (LBPPLUGINDIR, $L['BASIS.MAIN_TITLE'], $message);
		}
	}
	return;
}

?>
