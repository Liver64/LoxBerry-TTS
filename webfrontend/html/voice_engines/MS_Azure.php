<?php

/**
* Copyright (c) Microsoft Corporation
* All rights reserved. 
* MIT License
* Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the ""Software""), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:
* The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.
* THE SOFTWARE IS PROVIDED *AS IS*, WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*
* Modified by Oliver Lewald 2025
**/	

function t2s(array $t2s_param): void
{
    global $config;

    // --- Extract parameters with defaults ---
    $region      = $config['TTS']['regionms'] ?? 'westeurope';
    $apiKey      = $t2s_param['apikey'] ?? '';
    $lang        = $t2s_param['language'] ?? 'en-US';
    $textstring  = $t2s_param['text'] ?? '';
    $voice_ms    = $t2s_param['voice'] ?? 'en-US-GuyNeural';
    $filename    = $t2s_param['filename'] ?? 'tts_output';

    // --- Validate required parameters ---
    if (!$apiKey || !$textstring) {
        LOGERR("voice_engines\MS Azure TTS: API key or text is missing");
        return;
    }

    // --- Define Azure endpoint URIs ---
    $accessTokenUri = "https://{$region}.api.cognitive.microsoft.com/sts/v1.0/issueToken";
    $ttsServiceUri  = "https://{$region}.tts.speech.microsoft.com/cognitiveservices/v1";

    try {
        // --- Step 1: Get Access Token ---
        $ch = curl_init($accessTokenUri);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Ocp-Apim-Subscription-Key: {$apiKey}", // Azure subscription key header
            "Content-Length: 0"                     // Required even if POST body is empty
        ]);
        curl_setopt($ch, CURLOPT_POST, true);      // Use POST method to request token
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return response as string

        $accessToken = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$accessToken || $httpCode !== 200) {
            LOGERR("voice_engines\MS Azure TTS: Failed to get access token. HTTP Code: {$httpCode}");
            return;
        }

        LOGINF("voice_engines\MS Azure TTS: Access token received successfully");

        // --- Step 2: Prepare SSML payload ---
        $doc = new DOMDocument('1.0', 'UTF-8');
        $root = $doc->createElement("speak");
        $root->setAttribute("version", "1.0");           // Required SSML version
        $root->setAttribute("xml:lang", substr($lang, 0, 5)); // Language of speech

        $voice = $doc->createElement("voice", htmlspecialchars($textstring, ENT_XML1));
        $voice->setAttribute("xml:lang", substr($lang, 0, 5)); // Language of voice
        $voice->setAttribute("name", $voice_ms);               // Specific Azure voice

        $root->appendChild($voice);
        $doc->appendChild($root);
        $ssml = $doc->saveXML();

        // --- Step 3: Send TTS request to Azure ---
        $ch = curl_init($ttsServiceUri);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/ssml+xml",                        // Tell Azure this is SSML XML
            "X-Microsoft-OutputFormat: audio-48khz-192kbitrate-mono-mp3", // Request MP3 audio format
            "Authorization: Bearer {$accessToken}",                       // Access token header
            "User-Agent: TTSPHP"                                          // Custom user agent (optional)
        ]);
        curl_setopt($ch, CURLOPT_POST, true);           // POST SSML to TTS API
        curl_setopt($ch, CURLOPT_POSTFIELDS, $ssml);   // Send SSML as POST body
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);// Return audio data as string
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);         // Timeout for request

        $audioData = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$audioData || $httpCode !== 200) {
            LOGERR("voice_engines\MS Azure TTS: Failed to create MP3. HTTP Code: {$httpCode}");
            return;
        }

        // --- Step 4: Save audio to file ---
        $filePath = rtrim($config['SYSTEM']['ttspath'], '/') . "/{$filename}.mp3";
        file_put_contents($filePath, $audioData);

        LOGOK("voice_engines\MS Azure TTS: MP3 successfully created at {$filePath}");

    } catch (Exception $e) {
        // --- Step 5: Handle any exceptions ---
        LOGERR("MS Azure TTS Exception: " . $e->getMessage());
    }
}
?>  