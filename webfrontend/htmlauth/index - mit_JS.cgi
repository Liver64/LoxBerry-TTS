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

use LoxBerry::System;
use LoxBerry::Web;
use LoxBerry::Log;
use LoxBerry::Storage;
use LoxBerry::JSON;
use CGI;

use warnings;
use strict;
use File::Copy;
use Data::Dumper;

use HTML::Template;
#use Config::Simple '-strict';
#use CGI::Carp qw(fatalsToBrowser);
#use CGI qw/:standard/;
#use LWP::Simple;
#use LWP::UserAgent;
#use File::HomeDir;
#use Cwd 'abs_path';
#use JSON qw( decode_json );
#use utf8;

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
my $templateout;
our $lbpbindir;
my $templateoutout;
my $templateoutfile;
my $templateout_title;
my %SL;

my $languagefile 				= "tts_all.ini";
my $maintemplatefilename	 	= "index.html";
my $outputfile 					= 'output.cfg';
my $outputusbfile 				= 'hats.json';
my $pluginlogfile				= "text2speech.log";
my $interfaceconfigfilefile		= "interfaces.json";
my $devicefile					= "/tmp/soundcards2.txt";
my $lbhostname 					= lbhostname();
my $lbip 						= LoxBerry::System::get_localip();
my $ttsfolder					= "tts";
my $mp3folder					= "mp3";
my $azureregion					= "westeurope"; # Change here if you have a Azure API key for diff. region
my $rampath						= $lbpdatadir."/t2s_interface";

my $ms4hpluginname				= "AudioServer4Home";
my $sonospluginname				= "Sonos";
my $text2sippluginname			= "Text2SIP";

my $log							= LoxBerry::Log->new (name => 'Webinterface', filename => $lbplogdir ."/". $pluginlogfile, append => 1, addtime => 1);
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
#my %SL = LoxBerry::System::readlanguage($templateout, $languagefile);

# Read Plugin Version
my $sversion = LoxBerry::System::pluginversion();

# read all POST-Parameter in namespace "R".
my $cgi = CGI->new;
my $q = $cgi->Vars;

$q->{form} = "main" if !$q->{form};

if ( $q->{form} eq "main" ) {
	$templateoutfile = "$lbptemplatedir/index.html";
	$template = LoxBerry::System::read_file($templateoutfile);
	#$templateout->param("FORM", "1");
	&form();
}

$cgi->import_names('R');

##########################################################################
# Form: Log
##########################################################################

sub form_logs
{

	# Prepare template
	&inittemplate();
	$templateout->param("LOGLIST_HTML", LoxBerry::Web::loglist_html());

	return();
}

##########################################################################
# Form: Main
##########################################################################

sub form_main
{
	# Prepare template
	&inittemplate();

	return();
}


#########################################################################
# get Pids of Services
#########################################################################
my %pids;
if( $q->{ajax} ) 
{
	my %response;
		
	ajax_header();
	if( $q->{ajax} eq "getpids" ) {
		pids();
		$response{pids} = \%pids;
		print JSON::encode_json(\%response);
	}
	exit;
}

LOGSTART "T2S UI started";

#########################################################################
# Parameter
#########################################################################

$saveformdata = defined $R::saveformdata ? $R::saveformdata : undef;
$do = defined $R::do ? $R::do : "form";

##########################################################################
# Init Main Template
##########################################################################
#inittemplate();

if ($R::getkeys)
{
	getkeys();
}

##########################################################################
# check installed Plugins (needed for Interface)
##########################################################################

if (-r $lbpconfigdir . "/" . $interfaceconfigfilefile) 
{	
	my $jsonobjic = LoxBerry::JSON->new();
	our $icfg = $jsonobjic->open(
		filename      => $lbpconfigdir . "/" . $interfaceconfigfilefile,
		writeonclose  => 0
	);
	my @plugins         = LoxBerry::System::get_plugins();
	my @plugins_enabled;
	my $plugincheck;

	# JSON-Array -> Hash für schnellen Lookup
	my %wanted = map { $_ => 1 } @$icfg;

	foreach my $plugin (@plugins) {
		my $title = $plugin->{PLUGINDB_TITLE} or next;
		if (exists $wanted{$title}) {
			push @plugins_enabled, { name => $title };   # String als Hash mit key 'name'
			LOGDEB("Plugin $title ist installiert und im JSON gewünscht");
			$plugincheck = 1;
		}
	}
	$templateout->param(
		INTERFACE => $plugincheck,
		PLUGINS   => \@plugins_enabled,
		PLUGINDIR => $lbpplugindir,
	);
}

##########################################################################
# Set LoxBerry SDK to debug if plugin is in debug
##########################################################################



##########################################################################
# Language Settings
##########################################################################

$templateout->param("LBHOSTNAME", lbhostname());
$templateout->param("LBLANG", $lblang);
$templateout->param("SELFURL", $ENV{REQUEST_URI});
$templateout->param("LBPPLUGINDIR", $lbpplugindir);
$templateout->param("LBPTEMPLATEDIR", $lbptemplatedir);
$templateout->param("HTTPINTERFACE", "http://$lbhostname/plugins/$lbpplugindir/interfacedownload");

LOGDEB "Read main settings from " . $languagefile . " for language: " . $lblang;

# übergibt Plugin Verzeichnis an HTML
$templateout->param(PLUGINDIR => $lbpplugindir,);

# übergibt Log Verzeichnis und Dateiname an HTML
$templateout->param("LOGFILE" , $lbplogdir . "/" . $pluginlogfile);

##########################################################################
# check if config files exist and they are readable
##########################################################################

# Check if config file exist/directory exists
if (!-r $lbpconfigdir . "/" . $configfile) 
{
	LOGWARN "Plugin config file/directory does not exist";
	LOGDEB "Check if config directory exists. If not, try to create it.";
	$error_message = $SL{'ERRORS.ERR_CREATE_CONFIG_DIRECTORY'};
	mkdir $lbpconfigdir unless -d $lbpconfigdir or &error; 
	LOGOK "Config directory: " . $lbpconfigdir . " has been created";
}

##########################################################################
# Main program
##########################################################################

if ($R::saveformdata) {
  &save;
  $jsonobj->write();
} 

#####################################################
# Form-Sub
#####################################################

sub form {
	
	&inittemplate();
	
	$templateout->param(FORMNO => 'FORM' );
	
	LOGTITLE "Display form";
	
	my $storage = LoxBerry::Storage::get_storage_html(
					formid => 'STORAGEPATH', 
					currentpath => $tcfg->{SYSTEM}->{path},
					custom_folder => 1,
					type_all => 1, 
					readwriteonly => 1, 
					data_mini => 1,
					label => "$SL{'T2S.SAFE_DETAILS'}");
					
	$templateout->param("STORAGEPATH", $storage);
	
	# fill saved values into form
	#$templateout		->param("SELFURL", $ENV{REQUEST_URI});
	$templateout		->param("T2S_ENGINE" 	=> $tcfg->{TTS}->{t2s_engine});
	$templateout		->param("VOICE" 		=> $tcfg->{TTS}->{voice});
	$templateout		->param("CODE" 			=> $tcfg->{TTS}->{messageLang});
	$templateout		->param("VOLUME" 		=> $tcfg->{TTS}->{volume});
	$templateout		->param("DATADIR" 		=> $tcfg->{SYSTEM}->{path});
	$templateout		->param("APIKEY"		=> $tcfg->{TTS}->{apikeys}->{$tcfg->{TTS}->{t2s_engine}});
	$templateout		->param("SECKEY"		=> $tcfg->{TTS}->{secretkeys}->{$tcfg->{TTS}->{t2s_engine}});
		
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
	$templateout->param("MP3_LIST", $mp3_list);
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
	my $outpath = $lbpconfigdir . "/" . $outputfile;
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
	$templateout->param("OUT_LIST", $out_list);
	
	# Fill USB output Dropdown
	my $usb_list;
	my $jsonparser = LoxBerry::JSON->new();
	my $config = $jsonparser->open(filename => $lbpbindir . "/" . $outputusbfile);
				
	foreach my $key (sort { lc($a) cmp lc($b) } keys %$config) {
		$usb_list.= "<option value=" . $key . ">" . $config->{$key}->{name}, $key . "</option>\n";
    }
	$templateout->param("USB_LIST", $usb_list);
	
	# detect Soundcards
	system($lbpbindir . '/service.sh sc_show');
	my $filename = '/tmp/soundcards2.txt';
	open my $in, $filename;
	my $sc_list;
	while (my $line = <$in>) {
            $sc_list.= $line.'<br>';
        }
    $templateout->param("SC_LIST", $sc_list);
	close($in);
	
	# check/get filesize of determined soundcards in order to fadeIn/fadeOut
	my $filesize = -s $devicefile;
	$templateout->param("MYFILE", $filesize);
	
	LOGDEB "Printing template";
	printtemplate();
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
	$tcfg->{SYSTEM}->{interfacepath} 							= $rampath;
	$tcfg->{SYSTEM}->{httpinterface} 							= "http://$lbhostname/plugins/$lbpplugindir/interfacedownload";
	$tcfg->{SYSTEM}->{cifsinterface} 							= "//$lbhostname/plugindata/$lbpplugindir/interfacedownload";
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
	$templateout->param("ERROR", "1");
	$templateout_title = $SL{'ERRORS.MY_NAME'} . ": v$sversion - " . $SL{'ERRORS.ERR_TITLE'};
	LoxBerry::Web::lbheader($templateout_title, $helplink);
	$templateout->param('ERR_MESSAGE', $error_message);
	print $templateout->output();
	LoxBerry::Web::lbfooter();
	exit;
}


#####################################################
# Save
#####################################################

sub print_save
{
	$templateout->param("SAVE", "1");
	$templateout_title = "$SL{'BASIS.MAIN_TITLE'}: v$sversion";
	LoxBerry::Web::lbheader($templateout_title, $helplink);
	print $templateout->output();
	LoxBerry::Web::lbfooter();
	exit;
}


######################################################################
# AJAX functions
######################################################################

sub pids 
{
	#$pids{'mqttgateway'}   = trim(`pgrep mqttgateway.pl`);
	#$pids{'mosquitto'}     = trim(`pgrep mosquitto`);
	$pids{'mqtt_handler'}  = trim(`pgrep -f mqtt-handler.php`);
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



##########################################################################
# Init Template
##########################################################################
sub inittemplate
{
    # Add JS Scripts
	my $templatefile = "$lbptemplatedir/javascript.js";
	$template .= LoxBerry::System::read_file($templatefile);

	$templateout = HTML::Template->new_scalar_ref(
		\$template,
		global_vars => 1,
		loop_context_vars => 1,
		die_on_bad_params => 0,
	);

	# Language File
	%SL = LoxBerry::System::readlanguage($templateout, $languagefile);	
	
	our %navbar;
	
	$navbar{10}{Name} = "$SL{'T2S.MENU_SETTINGS'}";
	$navbar{10}{URL} = './index.cgi?form';
	$navbar{10}{active} = 1 if $q->{form} eq "main";
	$navbar{99}{Name} = "$SL{'T2S.MENU_LOGFILES'}";
	$navbar{99}{URL} = 'index.cgi?form=logfiles';
	$navbar{99}{active} = 1 if $q->{form} eq "logs";
	
	return();

}

##########################################################################
# Print Template
##########################################################################
sub printtemplate
{
    # Print out Template
	LoxBerry::Web::lbheader($SL{'BASIS.MAIN_TITLE'} . " v$sversion", "https://wiki.loxberry.de/plugins/audioserver4home/start", "");
	# Print your plugins notifications with name daemon.
	print LoxBerry::Log::get_notifications_html($lbpplugindir, 'text2speech');
	print $templateout->output();
	LoxBerry::Web::lbfooter();
	
	return();
}		

##########################################################################
# END routine - is called on every exit (also on exceptions)
##########################################################################
sub END 
{	
	our @reason;
	
	if ($log) {
		if (@reason) {
			LOGCRIT "Unhandled exception catched:";
			LOGERR @reason;
			LOGEND "Finished with an exception";
		} elsif ($error_message) {
			LOGEND "Finished with handled error";
		} else {
			LOGEND "Finished successful";
		}
	}
}



