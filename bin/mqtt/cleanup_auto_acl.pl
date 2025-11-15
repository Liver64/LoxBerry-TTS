#!/usr/bin/env perl
# =============================================================================
# health_cleanup.pl — Safe cleanup for T2S Health.json
# Version: 2.2 (Stable)
#
# - Removes old/expired entries
# - Removes entries with missing ACL users
# - Removes malformed entries
# - Pretty-writes JSON
# - Preserves valid entries
# =============================================================================

use strict;
use warnings;
use utf8;
binmode STDOUT, ":encoding(UTF-8)";

use JSON::PP;
use POSIX qw(strftime);
use File::Copy qw(copy);
use File::Basename;

# -------------------------------------------------------------------------
# CONFIG
# -------------------------------------------------------------------------
my $HEALTHFILE = "/dev/shm/text2speech/health.json";
my $ACLFILE    = "/etc/mosquitto/tts-aclfile";

# Days until health entry is considered expired
my $EXPIRY_DAYS = 10;   # << EDIT HERE IF NEEDED >>

my $expiry_seconds = $EXPIRY_DAYS * 24 * 3600;
my $now = time();

# -------------------------------------------------------------------------
# Logging helper
# -------------------------------------------------------------------------
sub ts   { strftime("%Y-%m-%d %H:%M:%S", localtime) }
sub logp { my ($lvl,$msg)=@_; print sprintf("[%s] <%s> %s\n", ts(), $lvl, $msg); }

logp("INFO", "Starting Health Cleanup");
logp("INFO", "Expiry threshold: $EXPIRY_DAYS days");

# -------------------------------------------------------------------------
# 1. Load ACL and extract valid AUTO-ACL user keys
# -------------------------------------------------------------------------
if (!-f $ACLFILE) {
    logp("ERR","ACL file missing: $ACLFILE");
    exit 1;
}

my %acl_users;

{
    open my $fh, "<:encoding(UTF-8)", $ACLFILE or die "Cannot read $ACLFILE: $!";
    while (<$fh>) {
        if (/^\s*user\s+([A-Za-z0-9._-]+)/) {
            $acl_users{$1} = 1;
        }
    }
    close $fh;
}

logp("INFO", "Loaded ACL users: " . scalar(keys %acl_users));

# -------------------------------------------------------------------------
# 2. Load health.json
# -------------------------------------------------------------------------
if (!-f $HEALTHFILE) {
    logp("WARN","Health file not found — nothing to clean");
    exit 0;
}

my $json_text = do {
    open my $fh, "<:encoding(UTF-8)", $HEALTHFILE or die;
    local $/;
    <$fh>;
};

my $health;
eval { $health = decode_json($json_text); };

if ($@ || ref $health ne 'HASH') {
    logp("ERR","Malformed health.json — abort");
    exit 1;
}

logp("INFO", "Loaded health entries: ".scalar(keys %$health));

# -------------------------------------------------------------------------
# 3. Validate & filter health entries
# -------------------------------------------------------------------------
my %new_health;

foreach my $key (keys %$health) {

    my $entry = $health->{$key};

    # -------- A) malformed entries --------
    unless (ref $entry eq 'HASH') {
        logp("WARN","Removing '$key' — entry is not a HASH");
        next;
    }
    unless ($entry->{timestamp} && $entry->{client} && $entry->{hostname}) {
        logp("WARN","Removing '$key' — missing fields");
        next;
    }

    # -------- B) expired entries --------
    my $age = $now - $entry->{timestamp};
    if ($age > $expiry_seconds) {
        my $days_old = int($age/86400);
        logp("WARN","Removing '$key' — $days_old days old (expired)");
        next;
    }

    # -------- C) ACL mismatch --------
    unless ($acl_users{$key}) {
        logp("WARN","Removing '$key' — no matching ACL user exists");
        next;
    }

    # -------- D) Valid entry --------
    $new_health{$key} = $entry;
}

logp("INFO", "Remaining valid entries: ".scalar(keys %new_health));

# -------------------------------------------------------------------------
# 4. Sort entries (newest first)
# -------------------------------------------------------------------------
my @sorted_keys = sort {
    $new_health{$b}{timestamp} <=> $new_health{$a}{timestamp}
} keys %new_health;

my %sorted_health;
foreach my $k (@sorted_keys) {
    $sorted_health{$k} = $new_health{$k};
}

# -------------------------------------------------------------------------
# 5. Backup original file
# -------------------------------------------------------------------------
my $backup = $HEALTHFILE.".bak";
copy($HEALTHFILE, $backup);
logp("OK","Backup saved: $backup");

# -------------------------------------------------------------------------
# 6. Write cleaned health.json
# -------------------------------------------------------------------------
my $json_out = JSON::PP->new->utf8->pretty->canonical->encode(\%sorted_health);

open my $fhw, ">:encoding(UTF-8)", $HEALTHFILE or die "Cannot write $HEALTHFILE: $!";
print $fhw $json_out;
close $fhw;

logp("OK","Health cleanup completed");
logp("OK","Health.json rewritten successfully");

exit 0;
