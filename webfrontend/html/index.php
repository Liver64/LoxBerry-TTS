<?php
header('Content-Type: text/html; charset=utf-8');

error_reporting(0);   					// turn off all errors/warnings/notice
ini_set("display_errors", false);      
ini_set('html_errors', false);			 

require_once "loxberry_system.php";
require_once "loxberry_log.php";
$L = LBSystem::readlanguage("tts_all.ini");

require_once 'tts.php';
?>
