#!/usr/bin/perl -w

# Copyright 2018 Oliver Lewald, olewald64@gmail.com
#
# Licensed under the Apache License, Version 2.0 (the "License");
# you may not use this file except in compliance with the License.
# You may obtain a copy of the License at
# 
#     http://www.apache.org/licenses/LICENSE-2.0
# 
# Unless required by applicable law or agreed to in writing, software
# distributed under the License is distributed on an "AS IS" BASIS,
# WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
# See the License for the specific language governing permissions and
# limitations under the License.


##########################################################################
# Modules
##########################################################################

use strict;
use warnings;

use LoxBerry::System;
use LoxBerry::Web;
use LoxBerry::Log;
use LoxBerry::Storage;
use LoxBerry::JSON;

use JSON::PP qw(encode_json decode_json); 
use HTML::Template;
use LWP::UserAgent;
use HTTP::Request;
use Encode ();
use CGI;
use URI::Escape qw(uri_unescape);
use POSIX qw(strftime);

# Optional (z. B. entfernen, wenn ungenutzt):
# use File::Copy;
# use Data::Dumper qw(Dumper);


##########################################################################
# Generic exception handler
##########################################################################

# Every non-handled exceptions sets the @reason variable that can
# be written to the logfile in the END function

$SIG{__DIE__} = sub { our @reason = @_ };

##########################################################################
# Variables
##########################################################################

my $namef;
my $value;
my %query;
my $template_title;
my $error;
my $saveformdata = 0;
my $do = "form";
my $helplink;
my $helptemplate;
my $storepath;
my $fullpath;
my $i;
my $template;
our $lbpbindir;
my %SL;

my $languagefile 				= "tts_all.ini";
my $maintemplatefilename	 	= "index.html";
my $outputfile 					= 'output.cfg';
my $outputusbfile 				= 'hats.json';
my $pluginlogfile				= "text2speech.log";
my $clients_dir 				= '/etc/mosquitto/tts-role/clients/text2sip';
my $devicefile					= "/tmp/soundcards2.txt";
my $lbhostname 					= lbhostname();
my $lbip 						= LoxBerry::System::get_localip();
my $ttsfolder					= "tts";
my $mp3folder					= "mp3";
my $azureregion					= "westeurope"; # Change here if you have a Azure API key for diff. region
my $rampath						= $lbpdatadir."/t2s_interface";
my $log							= LoxBerry::Log->new (name => 'Webinterface', filename => $lbplogdir ."/". $pluginlogfile, append => 1, addtime => 1);
our $LOG_ENDED 					= 0;
our $IS_AJAX 					= 0;
my $helplink 					= "https://wiki.loxberry.de/plugins/text2speech/start";
my $configfile 					= "t2s_config.json";
my $jsonobj 					= LoxBerry::JSON->new();
our $tcfg 						= $jsonobj->open(filename => $lbpconfigdir . "/" . $configfile, writeonclose => 0);
our $error_message				= "";

# Set new config options for upgrade installations
# cachsize
if (!defined $tcfg->{MP3}->{cachesize}) {
	$tcfg->{MP3}->{cachesize} = "100";
}
# add new parameter for Azure TTS"
if (!defined $tcfg->{TTS}->{regionms})  {
	$tcfg->{TTS}->{regionms} = $azureregion;
}
# splitsentence
if (!defined $tcfg->{MP3}->{splitsentences}) {
	$tcfg->{MP3}->{splitsentences} = "";
}
# USB device No.
if (!defined $tcfg->{SYSTEM}->{usbdevice}) {
	$tcfg->{SYSTEM}->{usbdevice} = 0;
}
# USB card No.
if (!defined $tcfg->{SYSTEM}->{usbcardno}) {
	$tcfg->{SYSTEM}->{usbcardno} = 1;
}
#  Jingle Volume reduction
if (!defined $tcfg->{TTS}->{jinglevolume}) {
	$tcfg->{TTS}->{jinglevolume} = "0.3";
}
# copy global apikey to engine-apikey
if (!defined $tcfg->{TTS}->{apikeys}) {
	$tcfg->{TTS}->{apikeys}->{$tcfg->{TTS}->{t2s_engine}} = $tcfg->{TTS}->{apikey};
}
# copy global Secret-key to engine-secretkey
if (!defined $tcfg->{TTS}->{secretkeys}) {
	$tcfg->{TTS}->{secretkeys}->{$tcfg->{TTS}->{t2s_engine}} = $tcfg->{TTS}->{secretkey};
}
if (!defined $tcfg->{TTS}->{apikeys}->{'5001'}) {
	$tcfg->{TTS}->{apikeys}->{'5001'} = "1";
}
if (!defined $tcfg->{TTS}->{apikeys}->{'6001'}) {
	$tcfg->{TTS}->{apikeys}->{'6001'} = "1";
}
$jsonobj->write();

##########################################################################
# Read Settings
##########################################################################

# read language
my $lblang = lblanguage();
#my %SL = LoxBerry::System::readlanguage($template, $languagefile);

# Read Plugin Version
my $sversion = LoxBerry::System::pluginversion();

# read all POST-Parameter in namespace "R".
my $cgi = CGI->new;
$cgi->import_names('R');
my $q = $cgi->Vars;


#########################################################################
# get Pids of Services
#########################################################################
my %pids;
if( $q->{ajax} ) {
    $IS_AJAX = 1;     # <— markieren, dass dieser Durchlauf AJAX ist
    my %response;
    ajax_header();
    if( $q->{ajax} eq "getpids" ) {
        pids();
        $response{pids} = \%pids;
        print encode_json(\%response);
    }
    exit;             # END läuft, aber wir filtern unten
}

LOGSTART "T2S UI started";

##########################################################################

# deletes the log file
if ( $R::delete_log )
{
	print "Content-Type: text/plain\n\nOK - In this version, this call does nothing";
	exit;
}

#########################################################################
# Parameter
#########################################################################

$saveformdata = defined $R::saveformdata ? $R::saveformdata : undef;
$do = defined $R::do ? $R::do : "form";

# --- AJAX: Validator für ICS/JSON ---
if (defined $R::action && $R::action eq 'validate_ics') {
    my $url  = defined $R::url  ? $R::url  : '';
    my $mode = defined $R::mode ? $R::mode : 'ics';

    my ($ok, $msg, $events) = _validate_url($url, $mode);

    # Keine CGI::header – reine Prints (vermeidet 500, falls CGI nicht geladen ist)
    print "Content-Type: application/json; charset=utf-8\n";
    print "Cache-Control: no-store, no-cache, must-revalidate\n\n";
    print encode_json({
        ok     => $ok ? JSON::PP::true : JSON::PP::false,
        msg    => $ok ? undef : $msg,
        events => $ok ? ($events // 0) : undef,
    });
    exit; # ganz wichtig – sonst läuft die normale Seite weiter
}

##########################################################################
# Init Main Template
##########################################################################
inittemplate();

if ($R::getkeys)
{
	getkeys();
}

##########################################################################
# Set LoxBerry SDK to debug in plugin is in debug
##########################################################################

if($log->loglevel() eq "7") {
	$LoxBerry::System::DEBUG 	= 1;
	$LoxBerry::Web::DEBUG 		= 1;
	$LoxBerry::Storage::DEBUG	= 1;
	$LoxBerry::Log::DEBUG		= 1;
}

##########################################################################
# Language Settings
##########################################################################

$template->param("LBHOSTNAME", lbhostname());
$template->param("LBLANG", $lblang);
$template->param("SELFURL", $ENV{REQUEST_URI});
$template->param("LBPPLUGINDIR", $lbpplugindir);
$template->param("LBPTEMPLATEDIR", $lbptemplatedir);
$template->param("HTTPINTERFACE", "http://$lbip/plugins/$lbpplugindir/interfacedownload");

LOGDEB "Read main settings from " . $languagefile . " for language: " . $lblang;

# übergibt Plugin Verzeichnis an HTML
#$template->param("PLUGINDIR" => $lbpplugindir);
$template->param(PLUGINDIR => $lbpplugindir,);

# übergibt Log Verzeichnis und Dateiname an HTML
$template->param("LOGFILE" , $lbplogdir . "/" . $pluginlogfile);

##########################################################################
# check if config files exist and they are readable
##########################################################################

# Check if tts_all.cfg file exist/directory exists
if (!-r $lbpconfigdir . "/" . $configfile) 
{
	LOGWARN "Plugin config file/directory does not exist";
	LOGDEB "Check if config directory exists. If not, try to create it.";
	$error_message = $SL{'ERRORS.ERR_CREATE_CONFIG_DIRECTORY'};
	mkdir $lbpconfigdir unless -d $lbpconfigdir or &error; 
	LOGOK "Config directory: " . $lbpconfigdir . " has been created";
}

# ============================================================
# Bridge health indicator (Text2Speech)
# ============================================================

my $health_file = "/dev/shm/text2speech/health.json";

my $interface_status = 0;   # 0=keine Bridge, 1=aktiv, 2=idle
my ($bridge_text, $bridge_last) = ("", "");

if (-f $health_file) {
    eval {
        local $/;
        open my $fh, '<', $health_file or die $!;
        my $json = <$fh>;
        close $fh;

        my $data = decode_json($json);
        if (ref $data eq 'HASH' && keys %$data) {
            # Jüngsten Handshake bestimmen
            my ($latest_key) = sort {
                $data->{$b}->{timestamp} <=> $data->{$a}->{timestamp}
            } keys %$data;

            my $entry = $data->{$latest_key};
            my $ts  = $entry->{timestamp} // 0;
            my $iso = $entry->{iso_time}  // strftime("%Y-%m-%d %H:%M:%S", localtime($ts));

            if ($ts > 0) {
                my $age = time() - $ts;

                # Zeitformat: [YYYY-MM-DD] HH:MMh
                if ($iso =~ /^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2})/) {
                    $bridge_last = "[$1] $2:$3h";
                } else {
                    $bridge_last = strftime("[%Y-%m-%d] %H:%M:%S", localtime($ts));
                }

                # Statuslogik
                if    ($age <= 300)    {   # ≤ 5 min
                    $interface_status = 1;
                    $bridge_text = $SL{'TEMPLATE.MESSAGE_BRIDGE3'};  # 🟢 aktiv
                }
                elsif ($age <= 43200)  {   # ≤ 12 h
                    $interface_status = 2;
                    $bridge_text = $SL{'TEMPLATE.MESSAGE_BRIDGE2'};  # 🟡 idle
                }
                else {                      # > 12 h
                    $interface_status = 0;
                    $bridge_text = $SL{'TEMPLATE.MESSAGE_BRIDGE1'};  # 🔴 getrennt
                }
            }
        }
    };
    if ($@) {
        LOGERR("index.cgi: Error parsing health.json: $@");
        $interface_status = 0;
    }
}

# ------------------------------------------------------------
# Übergabe an Template (nur wenn health.json sinnvoll war)
# ------------------------------------------------------------
if ($bridge_text ne "") {
    $template->param(
        INTERFACE   => $interface_status,   # 0/1/2
        BRIDGE_TEXT => $bridge_text,
        BRIDGE_LAST => $bridge_last,
    );
    LOGDEB("index.cgi: Bridge interface detected (INTERFACE=$interface_status, $bridge_last)");
} else {
    $template->param(
        INTERFACE   => 0,
    );
    LOGDEB("index.cgi: No active bridge detected -> INTERFACE=0");
}


##########################################################################
# Main program
##########################################################################

our %navbar;
$navbar{1}{Name} = "$SL{'T2S.MENU_SETTINGS'}";
$navbar{1}{URL} = './index.cgi?do=form';
#$navbar{3}{Name} = "$SL{'T2S.MENU_WIZARD'}";
#$navbar{3}{URL} = './index.cgi??do=logfilesdo=wizard';
$navbar{99}{Name} = "$SL{'T2S.MENU_LOGFILES'}";
$navbar{99}{URL} = './index.cgi?do=logfiles';

if ($R::saveformdata) {
  &save;
  $jsonobj->write();
} 

if(!defined $R::do or $R::do eq "form") {
	$navbar{1}{active} = 1;
	$template->param("FORM", "1");
	&form;
#} elsif ($R::do eq "wizard") {
#	LOGTITLE "Show logfiles";
#	$navbar{3}{active} = 1;
#	$template->param("WIZARD", "1");
#	printtemplate();
} elsif ($R::do eq "logfiles") {
	LOGTITLE "Show logfiles";
	$navbar{99}{active} = 1;
	$template->param("LOGFILES", "1");
	$template->param("LOGLIST_HTML", LoxBerry::Web::loglist_html());
	printtemplate();
}

$error_message = "Invalid do parameter";
error();
exit;

#####################################################
# Form-Sub
#####################################################

sub form {

	$template->param(FORMNO => 'FORM' );

	LOGTITLE "Display form";
	
	my $storage = LoxBerry::Storage::get_storage_html(
					formid => 'STORAGEPATH', 
					currentpath => $tcfg->{SYSTEM}->{path},
					custom_folder => 1,
					type_all => 1, 
					readwriteonly => 1, 
					data_mini => 1,
					label => "$SL{'T2S.SAFE_DETAILS'}");
					
	$template->param("STORAGEPATH", $storage);
	
	# fill saved values into form
	#$template		->param("SELFURL", $ENV{REQUEST_URI});
	$template		->param("T2S_ENGINE" 	=> $tcfg->{TTS}->{t2s_engine});
	$template		->param("VOICE" 		=> $tcfg->{TTS}->{voice});
	$template		->param("CODE" 			=> $tcfg->{TTS}->{messageLang});
	$template		->param("VOLUME" 		=> $tcfg->{TTS}->{volume});
	$template		->param("DATADIR" 		=> $tcfg->{SYSTEM}->{path});
	$template		->param("APIKEY"		=> $tcfg->{TTS}->{apikeys}->{$tcfg->{TTS}->{t2s_engine}});
	$template		->param("SECKEY"		=> $tcfg->{TTS}->{secretkeys}->{$tcfg->{TTS}->{t2s_engine}});
		
	# Get current storage folder
	$storepath = $tcfg->{SYSTEM}->{path};
		
	# Load saved values for "select"
	my $t2s_engine	= $tcfg->{TTS}->{t2s_engine};
	
	# fill dropdown with list of files from mp3 folder
	my $dir = $lbpdatadir.'/mp3/';
	my $mp3_list;
	
    opendir(DIR, $dir) or die $!;
	my @dots 
        = grep { 
            /\.mp3$/      # just files ending with .mp3
	    && -f "$dir/$_"   # and are files
	} 
	readdir(DIR);
	my @sorted_dots = sort { $a <=> $b } @dots;		# sort files numericly
    # Loop through the array adding filenames to dropdown
    foreach my $file (@sorted_dots) {
		$mp3_list.= "<option value='$file'>" . $file . "</option>\n";
    }
	closedir(DIR);
	$template->param("MP3_LIST", $mp3_list);
	LOGDEB "List of MP3 files has been successful loaded";
	LOGOK "Plugin has been successfully loaded.";
	
	my $line;
	my $out_list;
	
	my @data_piper;
	my @data_piper_voices;
	my $modified_str;
	my $new_pcfgp;
	my $new_pcfgpv;
	
	# open Piper languanges
	my $jsonobjpiper = LoxBerry::JSON->new();
	my $pcfgp = $jsonobjpiper->open(filename => $lbphtmldir."/voice_engines/langfiles/piper.json");
	
	# open Piper languanges details
	my $jsonobjpiper_voice = LoxBerry::JSON->new();
	my $pcfgpv = $jsonobjpiper_voice->open(filename => $lbphtmldir."/voice_engines/langfiles/piper_voices.json");
	
	# read all JSON files from folder
	my $directory = $lbphtmldir. "/voice_engines/piper-voices/";
	opendir(DIR, $directory) or die $!;
	my @pipfiles 
        = grep { 
            /\.json$/      		# just files ending with .json
	    && -f "$directory/$_"   # and is a file
	} 
	readdir(DIR);
	
    # Loop through the files adding details
    foreach my $file (@pipfiles) {
		my $jsonparser = LoxBerry::JSON->new();
		my $config = $jsonparser->open(filename => $lbphtmldir."/voice_engines/piper-voices/".$file, writeonclose => 0);
		# adding basic info to JSON object $pcfgp 
		my @piper = (  {"country" => $config->{language}->{country_english},
						"value" => $config->{language}->{code}
		});
		push @data_piper, @piper;
		$new_pcfgp = \@data_piper; 

		# adding detailes info JSON object $pcfgpv
		my @piper_voices = (  {	"name" => $config->{dataset},
								"language" => $config->{language}->{code},
								"filename" => $modified_str = substr($file, 0, -5)
		});
		push @data_piper_voices, @piper_voices;
		$new_pcfgpv = \@data_piper_voices;
    } 
	$jsonobjpiper->{jsonobj} = $new_pcfgp;
	$jsonobjpiper_voice->{jsonobj} = $new_pcfgpv;
	$jsonobjpiper->write();
	$jsonobjpiper_voice->write();
	closedir(DIR);
	# Call PHP to remove duplicates from piper.json
	my $tv = qx(/usr/bin/php $lbphtmldir/bin/piper_tts.php);	

	# Fill output Dropdown
	my $outpath = $lbpbindir . "/" . $outputfile;
	open my $in, $outpath or die "$outpath: $!";
	
	my $i = 1;
	while ($line = <$in>) {
		if ($i < 10) {
			$out_list.= "<option value='00".$i++."'>" . $line . "</option>\n";
		} else {
			$out_list.= "<option value='0".$i++."'>" . $line . "</option>\n";
		}
	}
	close $in;
	$template->param("OUT_LIST", $out_list);
	
	# Fill USB output Dropdown
	my $usb_list;
	my $jsonparser = LoxBerry::JSON->new();
	my $config = $jsonparser->open(filename => $lbpbindir . "/" . $outputusbfile);
				
	foreach my $key (sort { lc($a) cmp lc($b) } keys %$config) {
		$usb_list.= "<option value=" . $key . ">" . $config->{$key}->{name}, $key . "</option>\n";
    }
	$template->param("USB_LIST", $usb_list);
	
	# ------------------------------------------------------------
	# Soundcards-JSON besorgen (Reihenfolge der Versuche):
	# 1) PHP liefert JSON direkt über STDOUT (empfohlen, keine Datei nötig)
	# 2) /tmp/soundcards.json lesen, falls vorhanden
	# 3) Fallback: JSON in-memory über _build_soundcards_json() erzeugen
	#    (setzt voraus, dass deine Hilfsfunktion im selben File verfügbar ist)
	# ------------------------------------------------------------

	my $json = '';

	# 1) Versuch: PHP-Helfer (falls vorhanden)
	#$json = qx{/usr/bin/php LBHOMEDIR/bin/plugins/text2speech/detect_soundcards.php 2>/dev/null};
	$json = qx{/usr/bin/php $lbpbindir/detect_soundcards.php 2>/dev/null};
	my $data = eval { decode_json($json) } || { cards => [] };

	# 2) Versuch: /tmp/soundcards.json lesen
	my $jsonfile = '/tmp/soundcards.json';
	if ((!$json || $json !~ /\S/) && -s $jsonfile) {
		local $/;
		if (open my $fh, '<', $jsonfile) {
			$json = <$fh>;
			close $fh;
		}
	}

	# 3) Fallback: JSON in-memory bauen (ohne Datei)
	if (!$json || $json !~ /\S/) {
		eval {
			# Erwartet: deine Implementierung von _build_soundcards_json()
			my $data = _build_soundcards_json();
			$json = encode_json($data);
		};
		if ($@) {
			# Nichts gefunden/gebaut
			$template->param("SC_LIST" => "No sound information available");
			$template->param("MYFILE"  => 0);
			$template->param("SC_SELECT" => '');
			# Früh raus; der Rest benötigt JSON
			last;
		}
	}

	# ------------------------------------------------------------
	# JSON parsen
	# ------------------------------------------------------------
	my $sc_list  = '';

	if ($json) {
		my $data  = eval { decode_json($json) } || {};
		my $cards = $data->{cards} // [];

		if (@$cards) {
			for my $c (@$cards) {
				my $tag_usb = $c->{is_usb}     ? ' [USB]'     : '';
				my $tag_def = $c->{is_default} ? ' [default]' : '';
				my $cidx    = $c->{index};
				my $cname   = $c->{name} // '';
				my $cid     = $c->{id}   // '';
				my $devs    = $c->{devices} // [];

				if (@$devs) {
					for my $d (@$devs) {
						my $didx   = $d->{device};
						my $dname  = $d->{name}  // '';
						my $dvalue = $d->{value} // '';   # <-- hier steht z.B. "hw:0,0"

						# Zeile in der Liste inkl. value anzeigen
						$sc_list .= sprintf(
						  'card %d: %s [%s]%s%s, device %d: %s <span class="sc-val">(%s)</span><br>',
						  $cidx, $cname, $cid, $tag_usb, $tag_def, $didx, $dname, $dvalue
						);

						# Option in <select>, Label inkl. value
						my $label = sprintf(
							'card %d: %s [%s]%s%s, device %d: %s (%s)',
							$cidx, $cname, $cid,
							($c->{is_usb} ? ' (USB)' : ''),
							($c->{is_default} ? ' [default]' : ''),
							$didx, $dname, $dvalue
						);
					}
				} else {
					# Karte ohne explizite Devices -> device 0 annehmen, value zeigen
					my $assumed = sprintf('hw:%d,0', $cidx);
					$sc_list .= sprintf(
						'card %d: %s [%s]%s%s <code>(%s)</code><br>',
						$cidx, $cname, $cid, $tag_usb, $tag_def, $assumed
					);

					my $label = sprintf(
						'card %d: %s [%s]%s%s, device 0: %s (%s)',
						$cidx, $cname, $cid,
						($c->{is_usb} ? ' (USB)' : ''),
						($c->{is_default} ? ' [default]' : ''),
						$cname, $assumed
					);
				}
			}
		} else {
			$sc_list = "No devices found<br>";
		}
	} else {
		$sc_list = "No sound information available<br>";
	}

	# Übergabe an Template
	$template->param("SC_LIST"   => $sc_list);

	# Optional: Dateigröße deiner JSON-Datei anzeigen (falls vorhanden)
	my $filesize = (-s $jsonfile) || 0;
	$template->param("MYFILE" => $filesize);
	
	LOGDEB "Printing template";
	printtemplate();
	
	#Test Print to UI
	#my $content =  "Miniserver Nr. 1 heißt: $MiniServer und hat den Port: $MSWebPort User ist: $MSUser und PW: $MSPass.";
	#my $template_title = 'Testing';
	#LoxBerry::Web::lbheader($template_title);
	#print "Size: $filesize\n";
	#LoxBerry::Web::lbfooter();
	#exit;
}

#####################################################
# Save-Sub
#####################################################

sub save 
{
	LOGTITLE "Save parameters";
	LOGDEB "Filling config with parameters";

	# Write configuration file(s)
	$tcfg->{TTS}->{t2s_engine} 									= "$R::t2s_engine";
	$tcfg->{TTS}->{messageLang} 								= "$R::t2slang";
	$tcfg->{TTS}->{apikey} 										= "$R::apikey";
	$tcfg->{TTS}->{apikeys}		->{$tcfg->{TTS}->{t2s_engine}} 	= $tcfg->{TTS}->{apikey};
	$tcfg->{TTS}->{secretkey} 									= "$R::seckey";
	$tcfg->{TTS}->{secretkeys}	->{$tcfg->{TTS}->{t2s_engine}} 	= $tcfg->{TTS}->{secretkey};
	$tcfg->{TTS}->{voice} 										= "$R::voice";
	$tcfg->{TTS}->{regionms} 									= $azureregion;
	$tcfg->{TTS}->{volume} 										= "$R::volume";
	#$tcfg->{TTS}->{jinglevolume} 								= "$R::volume";
	$tcfg->{MP3}->{file_gong} 									= "$R::file_gong";
	$tcfg->{MP3}->{MP3store} 									= "$R::mp3store";
	$tcfg->{MP3}->{cachesize} 									= "$R::cachesize";
	$tcfg->{LOCATION}->{town} 									= "$R::town";
	$tcfg->{LOCATION}->{region} 								= "$R::region";
	$tcfg->{LOCATION}->{googlekey}	 							= "$R::googlekey";
	$tcfg->{LOCATION}->{googletown} 							= "$R::googletown";
	$tcfg->{LOCATION}->{googlestreet}			 				= "$R::googlestreet";
	$tcfg->{VARIOUS}->{CALDavMuell} 							= "$R::wastecal";
	$tcfg->{VARIOUS}->{CALDav2} 								= "$R::cal";
	$tcfg->{SYSTEM}->{path} 									= "$R::STORAGEPATH";
	$tcfg->{SYSTEM}->{mp3path} 									= "$R::STORAGEPATH/$mp3folder";
	$tcfg->{SYSTEM}->{ttspath} 									= "$R::STORAGEPATH/$ttsfolder";
	#$tcfg->{SYSTEM}->{interfacepath} 							= $rampath;
	#$tcfg->{SYSTEM}->{httpinterface} 							= "http://$lbhostname/plugins/$lbpplugindir/interfacedownload";
	$tcfg->{SYSTEM}->{httpinterface} 							= "http://$lbip/plugins/$lbpplugindir/interfacedownload";
	$tcfg->{SYSTEM}->{cifsinterface} 							= "//$lbhostname/plugindata/$lbpplugindir/interfacedownload";
	$tcfg->{SYSTEM}->{httpmp3interface} 						= "http://$lbhostname/plugindata/$lbpplugindir/mp3";
	$tcfg->{SYSTEM}->{cifsmp3interface} 						= "//$lbhostname/plugindata/$lbpplugindir/mp3";
	$tcfg->{SYSTEM}->{card}					 					= "$R::out_list";
	if ($R::out_list eq '012' || $R::out_list eq '012')   {
		$tcfg->{SYSTEM}->{usbcard} 									= "$R::usb_list";
		$tcfg->{SYSTEM}->{usbdevice}								= "$R::usbdeviceno";
		$tcfg->{SYSTEM}->{usbcardno} 								= "$R::usbcardno";
	} else {
		$tcfg->{SYSTEM}->{usbcard} 									= "";
		$tcfg->{SYSTEM}->{usbdevice}								= "";
		$tcfg->{SYSTEM}->{usbcardno} 								= "";
	}
	
	
	LOGINF "Writing configuration file";
	$jsonobj->write();

	LOGOK "All settings has been saved successful";

	# If storage folders do not exist, copy default mp3 files
	my $copy = 0;
	if (!-e "$R::STORAGEPATH/$mp3folder") {
		$copy = 1;
	}

	LOGINF "Creating folders and symlinks";
	system ("mkdir -p $R::STORAGEPATH/$mp3folder");
	system ("mkdir -p $R::STORAGEPATH/$ttsfolder");
	#if (!-e $rampath)    {
	#	system ("mkdir -p $rampath");
	#}
	system ("rm $lbpdatadir/interfacedownload");
	system ("rm $lbphtmldir/interfacedownload");
	system ("ln -s $R::STORAGEPATH/$ttsfolder $lbpdatadir/interfacedownload");
	system ("ln -s $R::STORAGEPATH/$ttsfolder $lbphtmldir/interfacedownload");
	LOGOK "All folders and symlinks created successfully.";

	if ($copy) {
		LOGINF "Copy existing mp3 files from $lbpdatadir/$mp3folder to $R::STORAGEPATH/$mp3folder";
		system ("cp -r $lbpdatadir/$mp3folder/* $R::STORAGEPATH/$mp3folder");
	}
	&print_save;
	exit;
	
}


#####################################################
# Error
#####################################################

sub error 
{
	$template->param("ERROR", "1");
	$template_title = $SL{'ERRORS.MY_NAME'} . ": v$sversion - " . $SL{'ERRORS.ERR_TITLE'};
	LoxBerry::Web::lbheader($template_title, $helplink);
	$template->param('ERR_MESSAGE', $error_message);
	print $template->output();
	LoxBerry::Web::lbfooter();
	exit;
}


#####################################################
# Save
#####################################################

sub print_save
{
	$template->param("SAVE", "1");
	$template_title = "$SL{'BASIS.MAIN_TITLE'}: v$sversion";
	LoxBerry::Web::lbheader($template_title, $helplink);
	print $template->output();
	LoxBerry::Web::lbfooter();
	exit;
}


######################################################################
# --- Helpers for CalDAV validation ---
######################################################################

sub _mask_url 
{
    my ($u) = @_;
    return '' unless defined $u;
    $u =~ s/(pass=)([^&]+)/$1***MASKED***/ig;
    $u =~ s{(https?://)([^:/\s]+):([^@/]+)\@}{$1$2:***MASKED***@}ig;
    return $u;
}

sub _validate_url {
    my ($url, $mode) = @_;
    $mode ||= 'ics';

    return (0, "No URL entered") unless defined $url && $url ne '';

    # --- Resolve actual target for ICS: take calURL=... if present ---
    my $target_url = $url;
    if ($mode eq 'ics') {
        if ($url =~ /(?:^|[?&])calURL=([^&]+)/i) {
            my $enc = $1;
            my $dec = uri_unescape($enc);          # e.g. https%3A// -> https://
            $dec =~ s/^webcal:\/\//https:\/\//i;   # normalize webcal://
            $target_url = $dec if $dec =~ m{^https?://}i;
        }
    }

    # --- HTTP client ---
    my $ua = LWP::UserAgent->new(
        timeout      => 12,
        max_size     => 512 * 1024,   # up to ~512 KB
        max_redirect => 5,
        env_proxy    => 1,
        agent        => "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
                      . "(KHTML, like Gecko) Chrome/124.0 Safari/537.36"
    );

    # --- Build request (no Range on first try) ---
    my $req = HTTP::Request->new(GET => $target_url);
    if ($mode eq 'ics') {
        $req->header('Accept' => 'text/calendar, text/plain, application/octet-stream');
    } else { # json
        $req->header('Accept' => 'application/json, text/plain');
    }
    $req->header('Connection' => 'close');

    my $res = $ua->request($req);
    return (0, sprintf("HTTP error %s at %s", $res->status_line, _mask_url($target_url)))
        unless $res->is_success;

    my $ct_header = $res->header('Content-Type') // '';
    my $ct        = lc $ct_header;

    # Prefer raw content; normalize BOM/encodings
    my $bytes = $res->content // '';
    my $body  = $bytes;
    $body =~ s/^\xEF\xBB\xBF//;   # UTF-8 BOM

    # Detect UTF-16/32 BOMs and decode
    if ($body =~ /^\xFF\xFE\x00\x00/) {              # UTF-32 LE with BOM
        $body = Encode::decode('UTF-32LE', $body);
    } elsif ($body =~ /^\x00\x00\xFE\xFF/) {         # UTF-32 BE with BOM
        $body = Encode::decode('UTF-32BE', $body);
    } elsif ($body =~ /^\xFF\xFE/) {                 # UTF-16 LE
        $body = Encode::decode('UTF-16LE', $body);
    } elsif ($body =~ /^\xFE\xFF/) {                 # UTF-16 BE
        $body = Encode::decode('UTF-16BE', $body);
    }

    # Trim leading whitespace/newlines that might precede VCALENDAR
    $body =~ s/^\s+//;

    if ($mode eq 'ics') {
        my $has_vcal = ($body =~ /BEGIN:VCALENDAR/i) ? 1 : 0;

        # If server clearly returned JSON, tell the user
        if ($ct =~ /json/) {
            return (0, sprintf("Got JSON instead of ICS (Content-Type: %s)", $ct_header));
        }
        # If HTML and no VCALENDAR → likely error/login page
        if ($ct =~ /html/ && !$has_vcal) {
            my $snip = substr($body, 0, 200); $snip =~ s/\s+/ /g;
            return (0, sprintf("Got HTML instead of ICS (Content-Type: %s): %s", $ct_header, $snip));
        }

        # Accept ICS if VCALENDAR marker is present (even with wrong Content-Type)
        unless ($has_vcal) {
            my $snip = substr($body, 0, 200); $snip =~ s/\s+/ /g;
            return (0, sprintf(
                "No iCalendar (BEGIN:VCALENDAR missing). Content-Type: %s. Snippet: %s",
                $ct_header, $snip
            ));
        }

        my $events = () = ($body =~ /BEGIN:VEVENT/ig);
        return (0, "No events found") if $events < 1;
        return (1, "OK", $events);
    }
    elsif ($mode eq 'json') {
        # Wrong type for JSON?
        if ($ct =~ /calendar|ics/) {
            return (0, sprintf("Got ICS instead of JSON (Content-Type: %s)", $ct_header));
        }

        my $data;
        eval { $data = decode_json($body) };
        if ($@ || !defined $data) {
            my $snip = substr($body, 0, 200); $snip =~ s/\s+/ /g;
            return (0, sprintf("Invalid JSON. Snippet: %s", $snip));
        }

        # Count appointment-like objects (ignore 'now')
        my $count = 0;
        if (ref $data eq 'HASH') {
            for my $k (keys %$data) {
                next if lc($k) eq 'now';
                $count++ if ref $data->{$k} eq 'HASH';
            }
        }
        return (0, "No appointments found in JSON") if $count < 1;

        return (1, "OK", $count);
    }

    return (0, "Unknown mode");
}


######################################################################
# AJAX functions
######################################################################

sub pids 
{
	$pids{'mqttgateway'}   = trim(`pgrep mqttgateway.pl`);
	$pids{'mosquitto'}     = trim(`pgrep mosquitto`);
	$pids{'mqtt_handler'}  = trim(`pgrep -f mqtt-subscribe.php`);
	$pids{'mqtt_watchdog'} = trim(`pgrep -f 'mqtt-watchdog.php'`);
	#LOGDEB "PIDs updated";
}

sub ajax_header
{
	print $cgi->header(
			-type => 'application/json',
			-charset => 'utf-8',
			-status => '200 OK',
	);	
	#LOGOK "AJAX posting received and processed";
}	

#####################################################
# Get Engine keys (AJAX)
#####################################################

sub getkeys
{
	print "Content-type: application/json\n\n";
	my $engine = defined $R::t2s_engine ? $R::t2s_engine : "";
	my $apikey = defined $tcfg->{TTS}->{apikeys}->{$engine} ? $tcfg->{TTS}->{apikeys}->{$engine} : "";
	my $secret = defined $tcfg->{TTS}->{secretkeys}->{$engine} ? $tcfg->{TTS}->{secretkeys}->{$engine} : "";
	print "{\"apikey\":\"$apikey\",\"seckey\":\"$secret\"}";
	exit;
}


#####################################################
# Get connected MQTT clients
#####################################################

# Liest *.json aus $dir und dedupliziert nach client (neueste Datei gewinnt).
# Rückgabe: Hashref  { client => { version=>..., ip=>..., host=>... }, ... }
sub read_text2sip_clients_map {
    my ($dir) = @_;
    my %by_client;

    for my $file (glob("$dir/*.json")) {
        open my $fh, '<', $file or next;
        local $/;
        my $raw = <$fh>;
        close $fh;

        my $data = eval { decode_json($raw) } or next;

        my $client = $data->{client} or next;
        my $ver    = $data->{version} // '';
        my $ip     = $data->{ip}      // $data->{remote_ip} // '';
        my $host   = $data->{host}    // $data->{hostname}  // '';

        my $mtime = (stat($file))[9] // 0;

        # Dedupe: neueste Datei je client gewinnt
        my $cur = $by_client{$client};
        if (!defined $cur || $mtime >= ($cur->{_mtime} // -1)) {
            $by_client{$client} = { version => $ver, ip => $ip, host => $host, _mtime => $mtime };
        }
    }

    # _mtime entfernen
    delete $_->{_mtime} for values %by_client;
    return \%by_client;
}

# Rückgabe: $client_array [ {client=>..., version=>..., ip=>..., host=>...}, ... ]
sub read_text2sip_clients_list {
    my ($dir) = @_;
    my $map = read_text2sip_clients_map($dir);
    return my $client_array = [ map { { client => $_, %{ $map->{$_} } } } sort keys %$map ];
}


##########################################################################
# Init Template
##########################################################################
sub inittemplate
{
    # Check, if filename for the maintemplate is readable, if not raise an error
    my $maintemplatefile = "$lbptemplatedir/$maintemplatefilename";
	
    stat($maintemplatefile);
    if (!-r _) {
        $error_message = "Error: Main template not readable";
        LOGCRIT "The ".$maintemplatefilename." file could not be loaded. Abort plugin loading";
        LOGCRIT $error_message;
        &error;
    }
	
	$template =  HTML::Template->new(
				filename => $lbptemplatedir . "/" . $maintemplatefilename,
				global_vars => 1,
				loop_context_vars => 1,
				die_on_bad_params=> 0,
				associate => $jsonobj,
				%htmltemplate_options,
				debug => 1
				);

    # Sprachdatei laden
    %SL = LoxBerry::System::readlanguage($template, $languagefile);			
}

##########################################################################
# Print Template
##########################################################################
sub printtemplate
{
    # Print Template
    print "Content-type: text/html\n\n";  # war: application/javascript
    $template_title = "$SL{'BASIS.MAIN_TITLE'}: v$sversion";
    LoxBerry::Web::head();
    LoxBerry::Web::pagestart($template_title, $helplink, $helptemplate);
    print LoxBerry::Log::get_notifications_html($lbpplugindir);
    print $template->output();
    LoxBerry::Web::lbfooter();
    LOGOK "Website printed";
    exit;
}		

##########################################################################
# END routine - is called on every exit (also on exceptions)
##########################################################################
END {
    our @reason;
    our $error_message;
    our $IS_AJAX;

    return if $IS_AJAX;  # <— kein LOGEND bei AJAX

    if ($log) {
        if (@reason) {
            LOGCRIT "Unhandled exception catched:";
            LOGERR  @reason;
            LOGEND "Finished with an exception";
        } elsif ($error_message) {
            LOGEND "Finished with handled error";
        } else {
            LOGEND "Finished successful";
        }
    }
}



