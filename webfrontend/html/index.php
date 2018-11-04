<?php
header('Content-Type: text/html; charset=utf-8');

require_once "loxberry_system.php";
require_once "loxberry_log.php";
include "error.php";

error_reporting(E_ALL);
ini_set("display_errors", "off");
define('ERROR_LOG_FILE', "$lbplogdir/text2speech.log");

//calling custom error handler
set_error_handler("handleError");

// Testcases
#print_r($arra); // undefined variable
#print_r($dssdfdfgg); // undefined variable
#include_once 'file.php'; // No such file or directory
			 
$L = LBSystem::readlanguage("tts_all.ini");
require_once 'tts.php';
?>
