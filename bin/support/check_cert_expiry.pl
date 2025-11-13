#!/usr/bin/env perl
# =============================================================================
# check_cert_expiry.pl — Check certificate expiry for T2S Master or SIP Client
# Version: 1.7 (no logging, clean terminal + notify only)
# =============================================================================

use strict;
use warnings;
use Getopt::Long;
use POSIX qw(strftime);
use File::Basename;
use LoxBerry::System;
use LoxBerry::Log;

# ==== CONFIGURATION ====
my $DAYS_WARNING = 60;

# --- MASTER (T2S) ---
my $MASTER_CERT_DIR = "/etc/mosquitto/certs";
my $MASTER_CA       = "/etc/mosquitto/ca/mosq-ca.crt";
my $MASTER_CERT     = "$MASTER_CERT_DIR/t2s.crt";
my $MASTER_KEY      = "$MASTER_CERT_DIR/t2s.key";

# --- CLIENT (SIP Bridge) ---
my $CLIENT_CERT_DIR = "/etc/mosquitto/certs/sip-bridge";
my $CLIENT_CA       = "/etc/mosquitto/ca/mosq-ca.crt";
my $CLIENT_CERT     = "$CLIENT_CERT_DIR/t2s-bridge.crt";
my $CLIENT_KEY      = "$CLIENT_CERT_DIR/t2s-bridge.key";

# ==== COLORS ====
my $COLOR_OK    = "\033[1;32m<OK>\033[0m";
my $COLOR_WARN  = "\033[1;31m<WARNING>\033[0m";
my $COLOR_INFO  = "\033[1;30m<INFO>\033[0m";
my $COLOR_ERROR = "\033[1;31m<ERROR>\033[0m";

# ==== CLI ====
my $role;
my $help;
GetOptions(
    "master" => sub { $role = "master" },
    "client" => sub { $role = "client" },
    "help"   => \$help,
);

if ($help || !$role) {
    print <<"USAGE";
Usage: $0 [--master | --client]
  --master   Check T2S Master certificates
  --client   Check SIP bridge client certificates
  --help     Show this help message
USAGE
    exit 0;
}

print "\n$COLOR_INFO Checking certificate expiry for role: $role\n";

# ==== Determine file paths ====
my ($ca, $cert, $key);
if ($role eq 'master') {
    $ca   = $MASTER_CA;
    $cert = $MASTER_CERT;
    $key  = $MASTER_KEY;
} else {
    $ca   = $CLIENT_CA;
    $cert = $CLIENT_CERT;
    $key  = $CLIENT_KEY;
}

# ==== Verify files ====
foreach my $file ($ca, $cert, $key) {
    unless (-e $file) {
        print "$COLOR_ERROR File not found: $file\n";
        exit 2;
    }
}

# ==== Function to read expiry ====
sub cert_expiry_days {
    my ($cert_path) = @_;
    my $output = `openssl x509 -enddate -noout -in "$cert_path" 2>/dev/null`;
    chomp($output);
    if ($output =~ /notAfter=(.*)/) {
        my $date_str = $1;
        my $epoch_expiry = `date -d "$date_str" +%s 2>/dev/null`;
        chomp($epoch_expiry);
        my $now = time();
        my $days_left = int(($epoch_expiry - $now) / 86400);
        return ($days_left, $date_str);
    }
    return (undef, undef);
}

# ==== Check certificates ====
my $exit_code = 0;
my @warnings;

foreach my $certfile ($ca, $cert) {
    my ($days, $date_str) = cert_expiry_days($certfile);
    if (!defined $days) {
        print "$COLOR_ERROR Could not read expiry from $certfile\n";
        $exit_code = 2;
        next;
    }

    my $basename = basename($certfile);
    if ($days <= $DAYS_WARNING) {
        print "$COLOR_WARN $basename expires in $days days ($date_str)\n";
        push @warnings, "$basename ($days days left, expires $date_str)";
        $exit_code = 1 if $exit_code == 0;
    } else {
        print "$COLOR_OK $basename valid for $days more days (until $date_str)\n";
    }
}

# ==== Notification if needed ====
if (@warnings) {
    my $msg = "The following certificates for role '$role' will expire soon:\n" .
              join("\n", @warnings);
    print "\n$COLOR_WARN The following certificates for role '$role' will expire soon:\n$msg\n";
    notify('text2speech', 'cert_expiry', $msg);
} else {
    print "\n$COLOR_OK All certificates valid for more than $DAYS_WARNING days.\n";
}

print "\n$COLOR_INFO Certificate check completed for role: $role\n";
exit $exit_code;
