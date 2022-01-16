<?php


function GetWitz()  {
	$such_start = "<!-- google_ad_section_start -->";
	$such_ende = "<!-- google_ad_section_end -->";
	$witz_html = file_get_contents("http://lustich.de/witze/zufallswitz/");
	$witz_start = stripos($witz_html, $such_start);
	$witz_ende = stripos($witz_html, $such_ende);
	$witz = html_entity_decode(strip_tags(substr($witz_html, $witz_start+strlen($such_start), $witz_ende-$witz_start-strlen($such_ende))));
	$witz = str_replace("\"", "", $witz);
	echo "<br>WITZ: $witz";
	echo '<br>';
	LOGGING('Text2Speech: addon/gimmicks.php: Joke been generated and pushed to T2S creation',7);
	return ($witz);
}
	
?>
