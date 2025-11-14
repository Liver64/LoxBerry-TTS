#!/usr/bin/env perl
# =============================================================================
# cleanup_auto_acl.pl — Safe AUTO-ACL cleanup for T2S Master
# Version: 2.1  (Expiry days support + stable marker parsing)
# =============================================================================

use strict;
use warnings;
use utf8;
binmode STDOUT, ":encoding(UTF-8)";

use JSON::PP;
use POSIX qw(strftime);

# -------------------------------------------------------------------------
# CONFIG
# -------------------------------------------------------------------------
my $ACLFILE     = "/etc/mosquitto/tts-aclfile";
my $HEALTHFILE  = "/dev/shm/text2speech/health.json";

# Number of days until a client is considered expired
my $EXPIRY_DAYS = 10;    # <<<<<< HIER EINSTELLEN

# Marker definitions
my $BEGIN_MARK  = "### BEGIN AUTO-ACL ###";
my $END_MARK    = "### END AUTO-ACL ###";

# -------------------------------------------------------------------------
# Logging helper
# -------------------------------------------------------------------------
sub ts  { strftime("%Y-%m-%d %H:%M:%S", localtime) }
sub logp { my ($lvl,$msg)=@_; print sprintf("[%s] <%s> %s\n", ts(), $lvl, $msg); }

# -------------------------------------------------------------------------
# 1. Load health.json
# -------------------------------------------------------------------------
my %alive;
my %age;

if (-f $HEALTHFILE) {
    eval {
        my $txt = do { open my $fh, "<:encoding(UTF-8)", $HEALTHFILE or die; local $/; <$fh> };
        my $h   = decode_json($txt);
        if (ref $h eq 'HASH') {
            foreach my $client (keys %$h) {
                my $ts = $h->{$client}{timestamp} // 0;
                $alive{$client} = 1;
                $age{$client}   = $ts;
            }
        }
    };
}

logp("INFO", "Loaded health entries: " . scalar(keys %alive));
logp("INFO", "Expiry threshold: $EXPIRY_DAYS days");

my $now = time();
my $expiry_seconds = $EXPIRY_DAYS * 86400;

# -------------------------------------------------------------------------
# 2. Load ACL file
# -------------------------------------------------------------------------
if (!-f $ACLFILE) {
    logp("ERR","ACL file missing: $ACLFILE");
    exit 1;
}

my $acl = do {
    open my $fh, "<:encoding(UTF-8)", $ACLFILE or die;
    local $/;
    <$fh>;
};

# -------------------------------------------------------------------------
# 3. Extract AUTO-ACL block
# -------------------------------------------------------------------------
my $begin_pos = index($acl, $BEGIN_MARK);
my $end_pos   = index($acl, $END_MARK);

if ($begin_pos < 0 || $end_pos < 0 || $end_pos <= $begin_pos) {
    logp("ERR","AUTO-ACL markers not found or corrupt.");
    exit 1;
}

my $before = substr($acl, 0, $begin_pos + length($BEGIN_MARK));
my $middle = substr($acl, $begin_pos + length($BEGIN_MARK),
                    $end_pos - ($begin_pos + length($BEGIN_MARK)));
my $after  = substr($acl, $end_pos);

$middle =~ s/\r//g;

# -------------------------------------------------------------------------
# 4. Parse AUTO-ACL user blocks
# -------------------------------------------------------------------------
my @lines = split(/\n/, $middle);
my @new_middle;
my $skip_mode = 0;
my $current_user;

foreach my $line (@lines) {

    # Detect user block
    if ($line =~ /^\s*user\s+([A-Za-z0-9._-]+)/) {
        $current_user = $1;

        my $remove = 0;

        # A) Not in health.json → remove
        if (!$alive{$current_user}) {
            logp("WARN", "Removing AUTO-ACL user '$current_user' (not in health.json)");
            $remove = 1;
        }

        # B) Expired → remove
        elsif ($age{$current_user} && ($now - $age{$current_user}) > $expiry_seconds) {
            my $days_old = int(($now - $age{$current_user}) / 86400);
            logp("WARN", "Removing AUTO-ACL user '$current_user' (age $days_old days > $EXPIRY_DAYS)");
            $remove = 1;
        }

        if ($remove) {
            $skip_mode = 1;
            next;
        }

        # User is OK → keep user line
        push @new_middle, $line;
        $skip_mode = 0;
        next;
    }

    # If skipping, ignore block until next user
    if ($skip_mode) {
        next;
    }

    # Keep line
    push @new_middle, $line;
}

my $clean_middle = join("\n", @new_middle);
$clean_middle =~ s/\n+/\n/sg;

# -------------------------------------------------------------------------
# 5. Write back ACL
# -------------------------------------------------------------------------
my $new_acl = $before . "\n" . $clean_middle . "\n" . $after;

open my $fhw, ">:encoding(UTF-8)", $ACLFILE or die "Cannot write $ACLFILE: $!";
print $fhw $new_acl;
close $fhw;

logp("OK", "Cleanup finished");

# -------------------------------------------------------------------------
# 6. Reload Mosquitto
# -------------------------------------------------------------------------
system("systemctl reload mosquitto >/dev/null 2>&1") == 0
    ? logp("OK","Mosquitto reloaded")
    : logp("WARN","Mosquitto reload failed");

exit 0;
