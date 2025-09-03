#!/usr/bin/perl
use warnings;
use strict;
use LoxBerry::System;
use CGI;
use JSON;
#use LoxBerry::Log;
#use Data::Dumper;

my $error;
my $response;
my $cgi = CGI->new;
my $q = $cgi->Vars;

#print STDERR Dumper $q;

#my $log = LoxBerry::Log->new (
#    name => 'AJAX',
#	stderr => 1,
#	loglevel => 7
#);

#LOGSTART "Request $q->{action}";

if( $q->{action} eq "massservicerestart" ) {
	system ("$lbpbindir/mass_watchdog.pl --action=restart --verbose=0 > /dev/null 2>&1 &");
	my $resp = $?;
	sleep(1);
	my $status = LoxBerry::System::lock(lockfile => 'mass-watchdog', wait => 600); # Wait until watchdog is ready...
	$response = $resp;
}

if( $q->{action} eq "massservicestop" ) {
	system ("$lbpbindir/mass_watchdog.pl --action=stop --verbose=0 > /dev/null 2>&1");
	$response = $?;
}

if( $q->{action} eq "massservicestatus" ) {
	my $id;
	my $count = `sudo docker ps | grep -c musicassistent`;
	if ($count >= "1") {
		$id = `sudo docker ps | grep musicassistent | awk '{ print \$1 }'`;
		chomp ($id);
	}
	my %response = (
		pid => $id,
	);
	chomp (%response);
	$response = encode_json( \%response );
}

# Get config
if( $q->{action} eq "getconfig" ) {
	# Load config
	require LoxBerry::JSON;
	my $cfgfile = "$lbpconfigdir/plugin.json";
	my $jsonobj = LoxBerry::JSON->new();
	my $cfg = $jsonobj->open(filename => $cfgfile, readonly => 1);
	$response = encode_json( $cfg );
}

#if( $q->{action} eq "savesettings" ) {
#
#	# Check if all required parameters are defined
#	if (!defined $q->{'topic'} || $q->{'topic'} eq "") {
#		$q->{'topic'} = "poolmanager";
#	}
#	if (!defined $q->{'valuecycle'} || $q->{'valuecycle'} eq "") {
#		$q->{'valuecycle'} = "5";
#	}
#	if (!defined $q->{'statuscycle'} || $q->{'statuscycle'} eq "") {
#		$q->{'statuscycle'} = "300";
#	}

#	# Load config
#	require LoxBerry::JSON;
#	my $cfgfile = "$lbpconfigdir/plugin.json";
#	my $jsonobj = LoxBerry::JSON->new();
#	my $cfg = $jsonobj->open(filename => $cfgfile);
#	
#	# Save
#	$cfg->{'topic'} = $q->{'topic'};
#	$cfg->{'valuecycle'} = $q->{'valuecycle'};
#	$cfg->{'statuscycle'} = $q->{'statuscycle'};
#	$jsonobj->write();

#	$response = encode_json( $cfg );
#	
#}

#####################################
# Manage Response and error
#####################################

if( defined $response and !defined $error ) {
	print "Status: 200 OK\r\n";
	print "Content-type: application/json; charset=utf-8\r\n\r\n";
	print $response;
	#LOGOK "Parameters ok - responding with HTTP 200";
}
elsif ( defined $error and $error ne "" ) {
	print "Status: 500 Internal Server Error\r\n";
	print "Content-type: application/json; charset=utf-8\r\n\r\n";
	print to_json( { error => $error } );
	#LOGCRIT "$error - responding with HTTP 500";
}
else {
	print "Status: 501 Not implemented\r\n";
	print "Content-type: application/json; charset=utf-8\r\n\r\n";
	$error = "Action ". $q->{action} . " unknown";
	#LOGCRIT "Method not implemented - responding with HTTP 501";
	print to_json( { error => $error } );
}

END {
	#LOGEND if($log);
}
