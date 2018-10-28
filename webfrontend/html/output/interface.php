<?php
/**
* Submodul: bidirectonal Interface
*
**/


/**
/* Funktion : receive_post_request --> Function to receive and validate incoming Requests
/* @param: 	empty
/*
/* @return: array (
/*			[0] Text for T2S (string)
/*			[1] greet=0 w/o greeting, greet=1 with greeting (bolean)
/**/

function process_post_request() {
	
	#$level = LBSystem::pluginloglevel();
	
	// http://thisinterestsme.com/receiving-json-post-data-via-php/
	// http://thisinterestsme.com/sending-json-via-post-php/
	global $text, $decoded, $time_start, $level;
	
	#print_r('');
	if ($level == 7) {
		#print '***********************************************************************<br>';
		#print ' Details of incoming http Request<br>';
		#print '***********************************************************************<br>';
		#print '<br>';
	}
	// Make sure that it is a POST request.
	if(strcasecmp($_SERVER['REQUEST_METHOD'], 'POST') != 0){
		LOGERR("T2S Interface ** Request method must be POST!");
		if ($level == 7) {
			#print "Error: T2S Interface ** Request method must be POST!<br>";
		}
		exit;
	}
	if ($level == 7) {
		#print "Success: T2S Interface ** Request method is POST!<br>";
	}
	// Make sure that the content type of the POST request has been set to application/json
	$contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';
	if(strcasecmp($contentType, 'application/json') != 0){
		LOGERR("T2S Interface ** Content type must be: application/json");
		if ($level == 7) {
			#print "Error: T2S Interface ** Content type must be: application/json!<br>";
		}
		exit;
	}
	if ($level == 7) {
		#print "Success: T2S Interface ** Content type is: application/json!<br>";
	}
	
	// Receive the RAW post data.
	$content = trim(file_get_contents("php://input"));
	
	// Attempt to decode the incoming RAW post data from JSON.
	$decoded = json_decode($content, true);
	
	// If json_decode failed, the JSON is invalid.
	if(!is_array($decoded)){
		LOGERR("T2S Interface ** Received content contained invalid JSON!");
		if ($level == 7) {
			#print "Error: T2S Interface ** Received content contained invalid JSON!<br>";
		}
		exit;
	}
	if ($level == 7) {
		#print "Success: T2S Interface ** Received content contained valid JSON!<br>";
	}
	LOGOK("T2S Interface ** POST request has been successful processed!");
	if ($level == 7) {
		#print "Success: T2S Interface ** POST request has been successful processed!<br>";
		#print '<br>';
	}
	
	if ($level == 7) {
		#print '***********************************************************************<br>';
		#print ' Data Import from incoming http Request (Array)<br>';
		#print '***********************************************************************<br>';
		#print '<br>';
		#print_r($decoded);
		#print '<br>';
	}
	#print_r($decoded);
	return ($decoded);
}





?>