<?php
/**
* Submodul: Interface
*
**/


/**
/* Funktion : receive_post_request --> Function to receive and validate incoming Requests
/* @param: 	empty
/*
/* @return: array (
/*			[0] Text for T2S (string)
/*			[1] greet=0 w/o greeting, greet=1 with greeting (bolean)
**/

function process_post_request() {
	
	// http://thisinterestsme.com/receiving-json-post-data-via-php/
	// http://thisinterestsme.com/sending-json-via-post-php/
	global $text, $decoded, $time_start_total, $level, $logif;
	
	// Make sure that it is a POST request.
	if(strcasecmp($_SERVER['REQUEST_METHOD'], 'POST') != 0){
		LOGGING("T2S Interface: Request method must be POST!", 3);
		exit;
	}
	
	// Make sure that the content type of the POST request has been set to application/json
	$contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';
	if(strcasecmp($contentType, 'application/json') != 0)  {
		LOGGING("T2S Interface: Content type must be: application/json", 3);
		exit;
	}
	// Receive the RAW post data.
	$content = trim(file_get_contents("php://input"));
	
	// Attempt to decode the incoming RAW post data from JSON.
	$decoded = json_decode($content, true);
	
	// If json_decode failed, the JSON is invalid.
	if(!is_array($decoded)){
		LOGGING("T2S Interface: Received content containes invalid JSON!", 3);
		exit;
	}
	LOGGING("T2S Interface: Incoming POST request has been successful processed, let's go ahead!", 5);
	return ($decoded);
}





?>