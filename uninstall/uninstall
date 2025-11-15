#!/usr/bin/env perl
# t2s-uninstall.pl — Uninstall for T2S Master
#
# DEFAULT (no options):
#   - Keeps CA stores (safe default)
#   - Cleans certs contents (but keeps directory)
#   - Removes: drop-ins, ACL, bundle, role marker
#   - Stops: mosquitto + T2S units (best effort)
#   - Removes systemd units
#   - Restarts mosquitto via mqtt-handler
#
# FLAGS:
#   --purge-ca     : REMOVE CA stores (with confirmation prompt)
#   --no-certs     : keep existing cert files
#   --force-master : ignore missing t2s-master marker (support use only)
#   --debug        : verbose logging
#   --quiet        : minimal console output
#   --help         : this help

use strict;
use warnings;
use utf8;
use Getopt::Long qw(GetOptions);
use File::Spec;
use File::Path qw(make_path remove_tree);
use Time::HiRes qw(gettimeofday);
use open ':std', ':utf8';
binmode STDOUT, ':utf8';

# ---------- Static paths ----------
my $CONF_PER            = '/etc/mosquitto/conf.d/00-global-per-listener.conf';
my $CONF_TLS            = '/etc/mosquitto/conf.d/10-listener-tls.conf';
my $ACL_FILE            = '/etc/mosquitto/tts-aclfile';

my $CERTDIR             = '/etc/mosquitto/certs';
my $ALT_CERTDIR         = '/etc/certs';

my $CA_PERSIST          = '/etc/mosquitto/ca';
my $ALT_CA_PERSIST      = '/etc/ca';

my $ROLE_DIR            = '/etc/mosquitto/role';
my $MASTER_MARKER       = File::Spec->catfile($ROLE_DIR, 't2s-master');

my $BRIDGE_DIR          = 'REPLACELBHOMEDIR/config/plugins/text2speech/bridge';
my $BUNDLE              = File::Spec->catfile($BRIDGE_DIR, 't2s-bundle.tar.gz');

my $MQTT_HANDLER        = 'REPLACELBHOMEDIR/sbin/mqtt-handler.pl';

my @UNIT_SERVICES       = ('mqtt-service-tts.service','mqtt-watchdog.service','mqtt-handshake-tts.service');
my @UNIT_TIMERS         = ('mqtt-watchdog.timer');
my @UNIT_FILES          = map { File::Spec->catfile('/etc/systemd/system', $_) } (@UNIT_SERVICES, @UNIT_TIMERS);

# ---------- CLI (default: DO NOT purge CA) ----------
my ($HELP, $QUIET, $DEBUG) = (0,0,0);

# SAFE DEFAULTS
my $PURGE_CA     = 0;  # Default: Keep CA stores!
my $CLEAN_CERT   = 1;  # Default: Clean cert directories
my $FORCE_MASTER = 0;

GetOptions(
  'help'          => \$HELP,
  'quiet'         => \$QUIET,
  'debug'         => \$DEBUG,
  'purge-ca'      => sub { $PURGE_CA = 1 },   # NEW: explicit purge-ca flag
  'no-certs'      => sub { $CLEAN_CERT = 0 }, # keep cert contents
  'force-master'  => \$FORCE_MASTER,
) or die "Invalid options. Use --help\n";

# ---------- Help text ----------
sub print_help {
  print <<"USAGE";
t2s-uninstall.pl — Uninstall for T2S Master

Safe defaults:
  • CA stores are KEPT.
  • Cert directories are cleaned, but directories kept.

To destroy the entire PKI (USE WITH CARE):
  --purge-ca     Removes /etc/mosquitto/ca and /etc/ca (confirmation required)

Options:
  --purge-ca     Delete CA stores (requires confirmation)
  --no-certs     Do not clean cert directories
  --force-master Ignore role marker and uninstall anyway
  --debug        Verbose logging
  --quiet        Minimal output
  --help         This help
USAGE
}

if ($HELP) {
  print_help();
  exit 0;
}

# ---------- Logging ----------
my $LOG_DIR  = '/var/log';
my $LOG_FILE = File::Spec->catfile($LOG_DIR, 'uninstall_t2s_plugin.log');
my $LOG_OK   = 0;

sub ts    { my ($s,$us)=gettimeofday(); my @t=localtime($s); sprintf("%02d-%02d-%04d %02d:%02d:%02d",$t[3],$t[4]+1,$t[5]+1900,$t[2],$t[1],$t[0]) }
sub _line { my ($lvl,$m)=@_; sprintf("%s <%s> %s\n", ts(), $lvl, $m) }
sub logx  { my ($lvl,$m)=@_; my $L=_line($lvl,$m); print $L unless $QUIET; print LOG $L if $LOG_OK; }
sub OK    { logx('OK',        shift) }
sub INFO  { logx('INFO',      shift) }
sub WARNING { logx('WARNING', shift) }
sub ERROR { logx('ERROR',     shift) }
sub DEB   { logx('DEB',       shift) if $DEBUG }

eval { make_path($LOG_DIR) unless -d $LOG_DIR; 1; };
if (open(LOG, ">>:utf8", $LOG_FILE)) { select((select(LOG), $|=1)[0]); $LOG_OK = 1; }

INFO("=== T2S Master Uninstall ===");
INFO("CA purge: ".($PURGE_CA ? "ON (confirmation required)" : "OFF")." | Cert clean: ".($CLEAN_CERT ? "ON" : "OFF"));

# ---------- Role marker protection ----------
if (! -e $MASTER_MARKER && !$FORCE_MASTER) {
  INFO("Role marker not found: $MASTER_MARKER");
  OK("Uninstall skipped (not T2S Master)");
  exit 0;
}
if ($FORCE_MASTER && ! -e $MASTER_MARKER) {
  WARNING("Force-master active — ignoring missing role marker");
}

# ---------- Helpers ----------
sub _read_head {
  my ($path, $bytes) = (@_, 2048);
  return '' unless -f $path && -r _;
  open my $fh, '<:utf8', $path or return '';
  read $fh, my $buf, $bytes; close $fh; return $buf // '';
}
my $MARK_RE = qr{Auto[-\s]?(?:generated|written)\s+by\s+(?:generate_mosquitto_certs\.pl|Text2Speech\s+Plugin(?:\.pl)?) }ix;
sub _has_marker { my ($p)=@_; _read_head($p) =~ $MARK_RE }
sub _run { my (@cmd)=@_; DEB("exec: @cmd"); system(@cmd)==0 }

sub _clear_immutable {
  my ($path) = @_;
  return 1 unless -e $path;
  if    (-x '/usr/bin/chattr') { _run('/usr/bin/chattr','-i','-R',$path) }
  elsif (-x '/bin/chattr')     { _run('/bin/chattr','-i','-R',$path) }
  else { 1 }
}

sub _safe_unlink {
  my ($p)=@_;
  return 1 unless $p && -e $p;
  unlink $p and do { OK("Removed: $p"); return 1; };
  _clear_immutable($p);
  _run('/bin/chmod','u+w',$p);
  if (_run('/bin/rm','-f',$p)) { OK("Removed (forced): $p"); return 1; }
  WARNING("Could not remove $p");
  return 0;
}

sub _force_purge_dir {
  my ($dir) = @_;
  return 1 unless $dir && -e $dir;
  _clear_immutable($dir);
  _run('/bin/chmod','-R','u+w',$dir);
  if (_run('/bin/rm','-rf',$dir)) { OK("Removed dir: $dir"); return 1; }
  eval { remove_tree($dir, { keep_root => 0 }); 1 } ? (OK("Removed via remove_tree: $dir"), 1)
                                                    : (ERROR("Failed to remove $dir: $@"), 0);
}

sub _empty_dir_keep_root {
  my ($dir) = @_;
  return 1 unless $dir && -d $dir;
  _clear_immutable($dir);
  _run('/bin/chmod','u+rwx',$dir);

  if (-x '/usr/bin/find') {
    _run('/usr/bin/find',$dir,'-mindepth','1','-exec','/bin/chattr','-i','{}','+');
    _run('/usr/bin/find',$dir,'-mindepth','1','-exec','/bin/chmod','u+w','{}','+');
    _run('/usr/bin/find',$dir,'-mindepth','1','-exec','/bin/rm','-rf','{}','+');
  }

  _run('/bin/chown','root:mosquitto',$dir);
  _run('/bin/chmod','0750',$dir);

  opendir my $dh, $dir or return 0;
  while (defined(my $e = readdir $dh)) {
    next if $e eq '.' or $e eq '..';
    WARNING("Residual entry in $dir: $e");
    closedir $dh;
    return 0;
  }
  closedir $dh;
  OK("Cleaned contents, kept directory: $dir");
  1;
}

sub _svc_stop_disable {
  my ($name) = @_;
  INFO("Stopping systemd unit: $name");
  _run('/bin/systemctl','stop',$name);
  INFO("Disabling systemd unit: $name");
  _run('/bin/systemctl','disable',$name);
}

# ---------- Stop services ----------
INFO("Stopping mosquitto …");
_run('/bin/systemctl','stop','mosquitto');

for my $unit (@UNIT_SERVICES) { _svc_stop_disable($unit); }
for my $tmr (@UNIT_TIMERS) {
  INFO("Disabling timer: $tmr");
  _run('/bin/systemctl','disable','--now',$tmr);
}

# ---------- Remove drop-ins & ACL ----------
INFO("Removing conf.d drop-ins …");
for my $p ($CONF_PER, $CONF_TLS) {
  next unless -e $p;
  _has_marker($p) ? _safe_unlink($p) : WARNING("Skipping $p — no marker");
}

INFO("Removing ACL …");
if (-e $ACL_FILE) {
  _has_marker($ACL_FILE) ? _safe_unlink($ACL_FILE) : WARNING("Skipping $ACL_FILE — no marker");
}

# ---------- Bridge bundle ----------
INFO("Removing bridge bundle and dir …");
_safe_unlink($BUNDLE);
_force_purge_dir($BRIDGE_DIR);

# ---------- Role marker ----------
INFO("Removing role marker …");
_safe_unlink($MASTER_MARKER) if -e $MASTER_MARKER;

# ---------- PURGE CA (only if confirmed) ----------
if ($PURGE_CA) {

  print "\n";
  print "***************************************************************\n";
  print " WARNING: You have requested --purge-ca\n";
  print " This will DELETE the entire Certificate Authority!\n";
  print " All existing clients will immediately lose trust.\n";
  print " Every SIP/Bridge client must be reinstalled with a new bundle.\n";
  print "***************************************************************\n";
  print " Type YES to proceed: ";

  chomp(my $ans = <STDIN>);
  if ($ans ne 'YES') {
    WARNING("CA purge cancelled by user.");
  } 
  else {
    INFO("Purging CA stores …");
    _force_purge_dir($CA_PERSIST);
    _force_purge_dir($ALT_CA_PERSIST);
  }
}
else {
  INFO("CA purge disabled — keeping CA stores.");
}

# ---------- Clean cert contents ----------
if ($CLEAN_CERT) {
  INFO("Cleaning cert directories contents …");
  _empty_dir_keep_root($CERTDIR);
  _empty_dir_keep_root($ALT_CERTDIR);
} else {
  INFO("Skipping cert content cleanup (--no-certs).");
}

# ---------- Remove systemd unit files ----------
INFO("Removing unit files …");
for my $f (@UNIT_FILES) {
  _safe_unlink($f) if -e $f;
}
_run('/bin/systemctl','daemon-reload');
_run('/bin/systemctl','reset-failed');

# ---------- Restart Mosquitto ----------
INFO("Restarting Mosquitto via mqtt-handler.pl …");

my $rc = system("REPLACELBHOMEDIR/sbin/mqtt-handler.pl action=restartgateway >/dev/null 2>&1");

if ($rc == 0) {
    sleep 2;
    my $pid = `pgrep -x mosquitto 2>/dev/null`;
    chomp($pid);
    if ($pid) {
        OK("Mosquitto restarted (PID $pid)");
    } else {
        WARNING("Restart success but no PID found");
    }
} else {
    WARNING("Mosquitto restart failed: code $rc");
}

OK("=== Uninstall finished (T2S Master) ===");
exit 0;
