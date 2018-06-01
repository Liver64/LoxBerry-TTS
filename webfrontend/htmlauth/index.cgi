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

use CGI::Carp qw(fatalsToBrowser);
use CGI qw/:standard/;
use CGI;
use LWP::Simple;
use LWP::UserAgent;
use File::HomeDir;
use File::Copy;
use Cwd 'abs_path';
use JSON qw( decode_json );
use utf8;
use warnings;
use strict;
#use Data::Dumper;
#no strict "refs"; # we need it for template system

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

my $helptemplatefilename		= "help.html";
my $languagefile 				= "tts_all.ini";
my $maintemplatefilename	 	= "index.html";
my $successtemplatefilename 	= "success.html";
my $errortemplatefilename 		= "error.html";
my $noticetemplatefilename 		= "notice.html";
my $no_error_template_message	= "The error template is not readable. We must abort here. Please try to reinstall the plugin.";
my $pluginconfigfile 			= "tts_all.cfg";
my $pluginlogfile				= "error.log";
my $lbhostname 					= lbhostname();
my $ttsfolder					= "tts";
my $mp3folder					= "mp3";
#my $urlfile						= "https://raw.githubusercontent.com/Liver64/LoxBerry-Sonos/master/webfrontend/html/release/info.txt";
my $log 						= LoxBerry::Log->new ( name => 'T2S Add-on', filename => $lbplogdir ."/". $pluginlogfile, append => 1, addtime => 1 );
#my $helplink 					= "http://www.loxwiki.eu/display/LOXBERRY/Sonos4Loxone";
my $pcfg 						= new Config::Simple($lbpconfigdir . "/" . $pluginconfigfile);
my %Config 						= $pcfg->vars() if ( $pcfg );
our $error_message				= "";


##########################################################################
# Read Settings
##########################################################################

# read language
my $lblang = lblanguage();
#my %SL = LoxBerry::System::readlanguage($template, $languagefile);

# Read Plugin Version
my $sversion = LoxBerry::System::pluginversion();

# Read LoxBerry Version
my $lbversion = LoxBerry::System::lbversion();

# read all POST-Parameter in namespace "R".
my $cgi = CGI->new;
$cgi->import_names('R');

# check if logfile is empty
if (-z $lbplogdir."/".$pluginlogfile) {
	system("/usr/bin/date > $pluginlogfile");
	$log->open;
	LOGSTART "Logfile started";
}

##########################################################################

# deletes the log file
if ( $R::delete_log )
{
	LOGDEB "Logfile will be deleted. ".$R::delete_log;
	LOGWARN "Delete Logfile: ".$pluginlogfile;
	my $pluginlogfile = $log->close;
	system("/usr/bin/date > $pluginlogfile");
	$log->open;
	LOGSTART "Logfile restarted";
	print "Content-Type: text/plain\n\nOK";
	exit;
}


#########################################################################
# Parameter
#########################################################################

# Everything from URL
foreach (split(/&/,$ENV{'QUERY_STRING'})){
  ($namef,$value) = split(/=/,$_,2);
  $namef =~ tr/+/ /;
  $namef =~ s/%([a-fA-F0-9][a-fA-F0-9])/pack("C", hex($1))/eg;
  $value =~ tr/+/ /;
  $value =~ s/%([a-fA-F0-9][a-fA-F0-9])/pack("C", hex($1))/eg;
  $query{$namef} = $value;
}

# Set parameters coming in - get over post
if ( !$query{'saveformdata'} ) { 
	if ( param('saveformdata') ) { 
		$saveformdata = quotemeta(param('saveformdata')); 
	} else { 
		$saveformdata = 0;
	} 
} else { 
	$saveformdata = quotemeta($query{'saveformdata'}); 
}

if ( !$query{'do'} ) { 
	if ( param('do')) {
		$do = quotemeta(param('do'));
	} else {
		$do = "form";
	}
} else {
	$do = quotemeta($query{'do'});
}


# Everything we got from forms
$saveformdata         = param('saveformdata');
defined $saveformdata ? $saveformdata =~ tr/0-1//cd : undef;


##########################################################################
# Various checks
##########################################################################

# Check, if filename for the errortemplate is readable
stat($lbptemplatedir . "/" . $errortemplatefilename);
if ( !-r _ )
{
	$error_message = $no_error_template_message;
	LoxBerry::Web::lbheader($template_title, $helplink, $helptemplatefilename);
	print $error_message;
	LOGCRIT $error_message;
	LoxBerry::Web::lbfooter();
	LOGCRIT "Leave Plugin due to an critical error";
	exit;
}


# Filename for the errortemplate is ok, preparing template";
my $errortemplate = HTML::Template->new(
					filename => $lbptemplatedir . "/" . $errortemplatefilename,
					global_vars => 1,
					loop_context_vars => 1,
					die_on_bad_params=> 0,
					associate => $cgi,
					%htmltemplate_options,
					debug => 1,
					);
my %ERR = LoxBerry::System::readlanguage($errortemplate, $languagefile);

#**************************************************************************

# Check, if filename for the successtemplate is readable
stat($lbptemplatedir . "/" . $successtemplatefilename);
if ( !-r _ )
{
	$error_message = $ERR{'ERRORS.ERR_SUCCESS_TEMPLATE_NOT_READABLE'};
	LOGCRIT "The ".$successtemplatefilename." file could not be loaded. Abort plugin loading";
	LOGCRIT $error_message;
	&error;
}
#LOGDEB "Filename for the successtemplate is ok, preparing template";
my $successtemplate = 	HTML::Template->new(
						filename => $lbptemplatedir . "/" . $successtemplatefilename,
						global_vars => 1,
						loop_context_vars => 1,
						die_on_bad_params=> 0,
						associate => $cgi,
						%htmltemplate_options,
						debug => 1,
						);
my %SUC = LoxBerry::System::readlanguage($successtemplate, $languagefile);

##########################################################################
# Logging
##########################################################################

if ($pcfg)
{
	$log->loglevel(int($Config{'SYSTEM.LOGLEVEL'}));
	$LoxBerry::System::DEBUG 	= 1 if int($Config{'SYSTEM.LOGLEVEL'}) eq 7;
	$LoxBerry::Web::DEBUG 		= 1 if int($Config{'SYSTEM.LOGLEVEL'}) eq 7;
}
else
{
	$log->loglevel(7);
	$LoxBerry::System::DEBUG 	= 1;
	$LoxBerry::Web::DEBUG 		= 1;
	$error_message				= $ERR{'ERRORS.ERR_NO_SONOS_CONFIG_FILE'};
	&error;
	exit;
}
#*************************************************************************

# Check, if filename for the maintemplate is readable, if not raise an error
stat($lbptemplatedir . "/" . $maintemplatefilename);
if ( !-r _ )
{
	$error_message = $ERR{'ERRORS.ERR_MAIN_TEMPLATE_NOT_READABLE'};
	LOGCRIT "The ".$maintemplatefilename." file could not be loaded. Abort plugin loading";
	LOGCRIT $error_message;
	&error;
}

my $template =  HTML::Template->new(
				filename => $lbptemplatedir . "/" . $maintemplatefilename,
				global_vars => 1,
				loop_context_vars => 1,
				die_on_bad_params=> 0,
				associate => $pcfg,
				%htmltemplate_options,
				debug => 1
				);
my %SL = LoxBerry::System::readlanguage($template, $languagefile);			


##########################################################################
# Language Settings
##########################################################################

$template->param("LBHOSTNAME", lbhostname());
$template->param("LBLANG", $lblang);
$template->param("SELFURL", $ENV{REQUEST_URI});

LOGDEB "Read main settings from " . $languagefile . " for language: " . $lblang;

#************************************************************************

# übergibt Plugin Verzeichnis an HTML
$template->param("PLUGINDIR" => $lbpplugindir);

# übergibt Data Verzeichnis an HTML
#$template->param("DATADIR" => $lbpdatadir);

# übergibt Log Verzeichnis und Dateiname an HTML
$template->param("LOGFILE" , $lbplogdir . "/" . $pluginlogfile);

##########################################################################
# check if config files exist and they are readable
##########################################################################

# Check if tts_all.cfg file exist/directory exists
if (!-r $lbpconfigdir . "/" . $pluginconfigfile) 
{
	LOGWARN "Plugin config file/directory does not exist";
	LOGDEB "Check if config directory exists. If not, try to create it.";
	$error_message = $ERR{'ERRORS.ERR_CREATE_CONFIG_DIRECTORY'};
	mkdir $lbpconfigdir unless -d $lbpconfigdir or &error; 
	LOGOK "Config directory: " . $lbpconfigdir . " has been created";
}


##########################################################################
# Main program
##########################################################################

if ($R::saveformdata) {
  &save;

} else {
  &form;

}
exit;


#####################################################
# Form-Sub
#####################################################

sub form {

	my $storage = LoxBerry::Storage::get_storage_html(
					formid => 'STORAGEPATH', 
					currentpath => $pcfg->param("SYSTEM.path"),
					type_usb => 1, 
					type_local => 1, 
					type_net => 1, 
					readwriteonly => 1, 
					label => "$SL{'T2S.SAFE_DETAILS'}");
					
	$template->param("STORAGEPATH", $storage);
	
	# fill saved values into form
	$template		->param("SELFURL", $ENV{REQUEST_URI});
	$template		->param("T2S_ENGINE" 	=> $pcfg->param("TTS.t2s_engine"));
	$template		->param("VOICE" 		=> $pcfg->param("TTS.voice"));
	$template		->param("CODE" 			=> $pcfg->param("TTS.messageLang"));
	$template		->param("VOLUME" 		=> $pcfg->param("TTS.volume"));
	$template		->param("DATADIR" 		=> $pcfg->param("SYSTEM.path"));
	
	# Get current storage folder
	$storepath = $pcfg->param("SYSTEM.path"),
	
	# Full path to check if folders already there
	$fullpath = $storepath."/".$lbhostname."/".$ttsfolder."/".$mp3folder;
	
	# Split path
	my @fields = split /\//, $storepath;
	my $folder = $fields[3];
	
	if ($folder ne "data")  {	
		if(-d $fullpath)  {
			LOGDEB "Directory already exists.";
		} else {
			# Create folder
			mkdir($storepath."/".$lbhostname, 0777);
			mkdir($storepath."/".$lbhostname."/".$ttsfolder, 0777);
			mkdir($storepath."/".$lbhostname."/".$ttsfolder."/".$mp3folder, 0777);
			LOGDEB "Directory '".$storepath."/".$lbhostname."/".$ttsfolder."/".$mp3folder."' has been created.";
			
			# Copy MP3 files to newly created folder
			my $source_dir = $lbpdatadir.'/mp3';
			my $target_dir = $storepath."/".$lbhostname."/".$ttsfolder."/".$mp3folder;

			opendir(my $DIRE, $source_dir) || die "can't opendir $source_dir: $!";  
			my @files = readdir($DIRE);

			foreach my $t (@files)	{
			   if(-f "$source_dir/$t" )  {
				  #Check with -f only for files (no directories)
				  copy "$source_dir/$t", "$target_dir/$t";
			   }
			}
			closedir($DIRE);
			LOGINF "All MP3 files has been copied successful to target location.";
		}
	} else {
		LOGINF "Local dir has been selected.";
	}
	
	# Load saved values for "select"
	my $t2s_engine	= $pcfg->param("TTS.t2s_engine");
	
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
	
	# Print Template
	my $sversion = LoxBerry::System::pluginversion();
	$template_title = "$SL{'BASIS.MAIN_TITLE'}: v$sversion";
	LoxBerry::Web::head();
	LoxBerry::Web::pagestart($template_title, $helplink, $helptemplate);
	print LoxBerry::Log::get_notifications_html($lbpplugindir);
	print $template->output();
	undef $template;	
	LoxBerry::Web::lbfooter();
	
	# Test Print to UI
	#my $content =  "Miniserver Nr. 1 heißt: $MiniServer und hat den Port: $MSWebPort User ist: $MSUser und PW: $MSPass.";
	#my $template_title = '';
	#LoxBerry::Web::lbheader($template_title);
	#print $lbpdatadir.'/mp3/'.'<br>';
	#print $directory;
	#LoxBerry::Web::lbfooter();
	#exit;
}

#####################################################
# Save-Sub
#####################################################

sub save 
{
	# OK - now installing...

	# Write configuration file(s)
	$pcfg->param("TTS.t2s_engine", "$R::t2s_engine");
	$pcfg->param("TTS.messageLang", "$R::t2slang");
	$pcfg->param("TTS.API-key", "$R::apikey");
	$pcfg->param("TTS.secret-key", "$R::seckey");
	$pcfg->param("TTS.voice", "$R::voice");
	$pcfg->param("MP3.file_gong", "$R::file_gong");
	$pcfg->param("MP3.MP3store", "$R::mp3store");
	$pcfg->param("LOCATION.town", "\"$R::town\"");
	$pcfg->param("LOCATION.region", "$R::region");
	$pcfg->param("LOCATION.googlekey", "$R::googlekey");
	$pcfg->param("LOCATION.googletown", "$R::googletown");
	$pcfg->param("LOCATION.googlestreet", "$R::googlestreet");
	$pcfg->param("VARIOUS.CALDavMuell", "\"$R::wastecal\"");
	$pcfg->param("VARIOUS.CALDav2", "\"$R::cal\"");
	$pcfg->param("SYSTEM.LOGLEVEL", "$R::LOGLEVEL");
	$pcfg->param("SYSTEM.path", "$R::STORAGEPATH");
	$pcfg->param("SYSTEM.card", "$R::scard");
	$pcfg->param("TTS.volume", "$R::volume");
	
	
	
	$pcfg->save() or &error;;

	LOGOK "All settings has been saved successful";

		my $lblang = lblanguage();
	$template_title = "$SL{'BASIS.MAIN_TITLE'}: v$sversion";
	LoxBerry::Web::lbheader($template_title, $helplink, $helptemplatefilename);
	$successtemplate->param('SAVE_ALL_OK'		, $SUC{'SAVE.SAVE_ALL_OK'});
	$successtemplate->param('SAVE_MESSAGE'		, $SUC{'SAVE.SAVE_MESSAGE'});
	$successtemplate->param('SAVE_BUTTON_OK' 	, $SUC{'SAVE.SAVE_BUTTON_OK'});
	$successtemplate->param('SAVE_NEXTURL'		, $ENV{REQUEST_URI});
	print $successtemplate->output();
	LoxBerry::Web::lbfooter();
	exit;
	
	# Test Print to UI
	#my $content =  "http://$MSUser:$MSPass\@$MiniServer:$MSWebPort/dev/sps/io/fetch_sonos/Ein";
	#my $template_title = '';
	#LoxBerry::Web::lbheader($template_title);
	#print $content;
	#LoxBerry::Web::lbfooter();
	#exit;
		
}


#####################################################
# Error-Sub
#####################################################

sub error 
{
	$template_title = $ERR{'ERRORS.MY_NAME'} . ": v$sversion - " . $ERR{'ERRORS.ERR_TITLE'};
	LoxBerry::Web::lbheader($template_title, $helplink, $helptemplatefilename);
	$errortemplate->param('ERR_MESSAGE'		, $error_message);
	$errortemplate->param('ERR_TITLE'		, $ERR{'ERRORS.ERR_TITLE'});
	$errortemplate->param('ERR_BUTTON_BACK' , $ERR{'ERRORS.ERR_BUTTON_BACK'});
	$successtemplate->param('ERR_NEXTURL'	, $ENV{REQUEST_URI});
	print $errortemplate->output();
	LoxBerry::Web::lbfooter();
}


