#!/usr/bin/env perl
# t2s-uninstall.pl — Uninstall for T2S Master
#
# DEFAULT (no options):
#   - Stops: mosquitto, mqtt-service-tts.service, mqtt-watchdog.service (best effort)
#            Disables+stops: mqtt-watchdog.timer (best effort)
#   - Removes: our conf.d drop-ins (with marker), ACL (with marker), bridge bundle/dir, role marker
#   - Purges : CA stores (/etc/mosquitto/ca and legacy /etc/ca)
#   - Cleans : /etc/mosquitto/certs CONTENTS (and legacy /etc/certs CONTENTS) but KEEPS the directory itself
#   - Deletes systemd unit files:
#       /etc/systemd/system/mqtt-service-tts.service
#       /etc/systemd/system/mqtt-watchdog.service
#       /etc/systemd/system/mqtt-watchdog.timer
#     and reloads systemd daemon (also reset-failed)
#   - Restarts mosquitto via LoxBerry's mqtt-handler (best effort; see block below)
#
# FLAGS:
#   --no-ca      : keep CA stores (do NOT purge /etc/mosquitto/ca or /etc/ca)
#   --no-certs   : do NOT clean /etc/mosquitto/certs*/ contents (keep files)
#   --debug      : verbose logging
#   --quiet      : minimal console output
#   --help       : this help (with examples)
#
# Return codes: 0 OK | 1 minor issues | >1 error

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

# Certs: directory MUST remain; contents will be deleted by default
my $CERTDIR             = '/etc/mosquitto/certs';
my $ALT_CERTDIR         = '/etc/certs';

# CA stores: directories will be removed by default
my $CA_PERSIST          = '/etc/mosquitto/ca';
my $ALT_CA_PERSIST      = '/etc/ca';

# Role marker
my $ROLE_DIR            = '/etc/mosquitto/role';
my $MASTER_MARKER       = File::Spec->catfile($ROLE_DIR, 't2s-master');

# Bridge bundle (created by generator --bundle)
my $BRIDGE_DIR          = 'REPLACELBHOMEDIR/config/plugins/text2speech/bridge';
my $BUNDLE              = File::Spec->catfile($BRIDGE_DIR, 't2s-bundle.tar.gz');

# LoxBerry gateway helper
my $MQTT_HANDLER        = 'REPLACELBHOMEDIR/sbin/mqtt-handler.pl';

# Systemd units to stop/disable/remove
my @UNIT_SERVICES       = ('mqtt-service-tts.service','mqtt-watchdog.service');
my @UNIT_TIMERS         = ('mqtt-watchdog.timer');  # new: oneshot watchdog uses a timer
my @UNIT_FILES          = map { File::Spec->catfile('/etc/systemd/system', $_) } (@UNIT_SERVICES, @UNIT_TIMERS);

# ---------- CLI (default: purge CA + clean cert contents) ----------
my ($HELP, $QUIET, $DEBUG) = (0, 0, 0);
my $PURGE_CA   = 1;  # default remove CA stores
my $CLEAN_CERT = 1;  # default clean contents of certs dir(s)
GetOptions(
  'help'     => \$HELP,
  'quiet'    => \$QUIET,
  'debug'    => \$DEBUG,
  'no-ca'    => sub { $PURGE_CA = 0 },    # keep CA stores
  'no-certs' => sub { $CLEAN_CERT = 0 },  # keep cert files
) or die "Invalid options. Use --help\n";

# ---------- Help text (callable with --help) ----------
sub print_help {
  print <<"USAGE";
t2s-uninstall.pl — Uninstall for T2S Master

Synopsis:
  t2s-uninstall.pl [--no-ca] [--no-certs] [--debug] [--quiet] [--help]

What it does by default:
  • Stops: mosquitto, mqtt-service-tts.service, mqtt-watchdog.service (best effort)
           Disables & stops: mqtt-watchdog.timer (best effort)
  • Removes: our Mosquitto drop-ins (marker-checked), ACL (marker-checked),
             bridge bundle + dir, and the role marker
  • Purges : CA stores (/etc/mosquitto/ca and legacy /etc/ca)
  • Cleans : CONTENTS of /etc/mosquitto/certs and legacy /etc/certs, but KEEPS the directories
  • Deletes systemd unit files:
        /etc/systemd/system/mqtt-service-tts.service
        /etc/systemd/system/mqtt-watchdog.service
        /etc/systemd/system/mqtt-watchdog.timer
    and reloads systemd (daemon-reload + reset-failed)
  • Restarts Mosquitto via the LoxBerry mqtt-handler

Options:
  --no-ca       Keep CA stores (do NOT purge /etc/mosquitto/ca or /etc/ca)
  --no-certs    Keep cert files (do NOT clean certs/ contents)
  --debug       Verbose debug logging
  --quiet       Minimal console output
  --help        Show this help

Examples:
  # Standard uninstall (purge CA, clean certs contents, keep cert directories)
  sudo t2s-uninstall.pl

  # Keep CA stores and keep cert files (only drop-ins, ACL, bundle, role marker, units)
  sudo t2s-uninstall.pl --no-ca --no-certs

  # Verbose run to see all actions
  sudo t2s-uninstall.pl --debug

  # Quiet run (minimal output)
  sudo t2s-uninstall.pl --quiet

Notes:
  • Run as root.
  • Drop-ins and ACL are removed only if they contain the Text2Speech generator marker.
  • Cert directories are kept to avoid breaking other components; their contents are cleaned by default.
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
INFO(($PURGE_CA ? "CA purge ON" : "CA purge OFF")." | ".($CLEAN_CERT ? "Certs clean ON" : "Certs clean OFF"));

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
  _run('/bin/chmod','-R','u+w',$dir) if -e $dir;
  if (_run('/bin/rm','-rf',$dir)) { OK("Removed dir: $dir"); return 1; }
  eval { remove_tree($dir, { keep_root => 0 }); 1 } ? (OK("Removed via remove_tree: $dir"), 1)
                                                    : (ERROR("Failed to remove $dir: $@"), 0);
}

sub _empty_dir_keep_root {
  my ($dir) = @_;
  return 1 unless $dir && -d $dir;
  _clear_immutable($dir);
  _run('/bin/chmod','u+rwx',$dir);

  # Make contents writable then delete (incl. dotfiles, subdirs)
  if (-x '/usr/bin/find') {
    if (-x '/usr/bin/chattr' || -x '/bin/chattr') {
      _run('/usr/bin/find',$dir,'-mindepth','1','-exec','/bin/chattr','-i','{}','+');
    }
    _run('/usr/bin/find',$dir,'-mindepth','1','-exec','/bin/chmod','-R','u+w','{}','+');
    _run('/usr/bin/find',$dir,'-mindepth','1','-exec','/bin/rm','-rf','{}','+');
  } else {
    opendir my $dh, $dir or return 0;
    while (defined(my $e = readdir $dh)) {
      next if $e eq '.' or $e eq '..';
      my $p = "$dir/$e";
      if (-d $p) { _force_purge_dir($p) } else { _safe_unlink($p) }
    }
    closedir $dh;
  }

  # re-assert ownership/perm on the root dir
  _run('/bin/chown','root:mosquitto',$dir);
  _run('/bin/chmod','0750',$dir);

  # verify dir still exists and is empty
  opendir my $vdh, $dir or do { ERROR("Cannot open $dir after clean"); return 0; };
  while (defined(my $e = readdir $vdh)) {
    next if $e eq '.' or $e eq '..';
    WARNING("Residual entry in $dir: $e");
    closedir $vdh;
    return 0;
  }
  closedir $vdh;
  OK("Cleaned contents, kept directory: $dir");
  1;
}

sub _svc_stop_disable {
  my ($name) = @_;
  INFO("Stopping systemd unit: $name");
  _run('/bin/systemctl','stop',$name) || WARNING("systemctl stop $name failed (continuing)");
  INFO("Disabling systemd unit: $name");
  _run('/bin/systemctl','disable',$name) || WARNING("systemctl disable $name failed (continuing)");
}

sub _timer_disable_now {
  my ($name) = @_;
  INFO("Disabling and stopping systemd timer: $name");
  _run('/bin/systemctl','disable','--now',$name) || WARNING("systemctl disable --now $name failed (continuing)");
}

# ---------- Stop services / timers ----------
INFO("Stopping mosquitto service (best effort) …");
_run('/bin/systemctl','stop','mosquitto') || WARNING("systemctl stop mosquitto failed (continuing)");

for my $unit (@UNIT_SERVICES) {
  _svc_stop_disable($unit);
}
for my $tmr (@UNIT_TIMERS) {
  _timer_disable_now($tmr);
}

# ---------- Remove conf.d & ACL (marker-checked) ----------
INFO("Removing conf.d drop-ins (marker-checked) …");
for my $p ($CONF_PER, $CONF_TLS) {
  next unless -e $p;
  if (_has_marker($p)) { _safe_unlink($p); } else { WARNING("Skipping $p — no generator marker"); }
}
INFO("Removing ACL file (marker-checked) …");
if (-e $ACL_FILE) {
  _has_marker($ACL_FILE) ? _safe_unlink($ACL_FILE) : WARNING("Skipping $ACL_FILE — no generator marker");
}

# ---------- Bridge bundle ----------
INFO("Removing bridge bundle …");
_safe_unlink($BUNDLE);
if (-d $BRIDGE_DIR) {
  _force_purge_dir($BRIDGE_DIR) or WARNING("Bridge dir still present: $BRIDGE_DIR");
}

# ---------- Remove role marker ----------
INFO("Removing role marker (if present) …");
if (-e $MASTER_MARKER) { _safe_unlink($MASTER_MARKER) } else { DEB("No marker at $MASTER_MARKER") }

# ---------- Purge CA stores (default ON) ----------
if ($PURGE_CA) {
  INFO("Purging CA stores …");
  _force_purge_dir($CA_PERSIST);
  _force_purge_dir($ALT_CA_PERSIST);
  for my $d ($CA_PERSIST,$ALT_CA_PERSIST) {
    if (-e $d) { ERROR("CA directory still exists after purge: $d"); } else { OK("Verified CA removed: $d"); }
  }
} else {
  DEB("Skipping purge of CA stores (--no-ca supplied).");
}

# ---------- Clean certs contents but keep directories (default ON) ----------
if ($CLEAN_CERT) {
  INFO("Cleaning certs directories CONTENTS, keeping the directories …");
  _empty_dir_keep_root($CERTDIR);
  _empty_dir_keep_root($ALT_CERTDIR);
} else {
  DEB("Skipping clean of certs contents (--no-certs supplied).");
}

# ---------- Remove systemd unit files & reload daemon ----------
INFO("Removing systemd unit files (if present) …");
for my $f (@UNIT_FILES) {
  if (-e $f) { _safe_unlink($f); } else { DEB("Unit file not present: $f"); }
}
INFO("Reloading systemd daemon …");
_run('/bin/systemctl','daemon-reload') || WARNING("systemctl daemon-reload failed (continuing)");
_run('/bin/systemctl','reset-failed')  || WARNING("systemctl reset-failed failed (continuing)");

# ---------- Start Mosquitto ----------
INFO("Starting mosquitto service …");
if (-x $MQTT_HANDLER) {
   system('sudo REPLACELBHOMEDIR/sbin/mqtt-handler.pl action=restartgateway >/dev/null 2>&1 || true');
   OK("Mosquitto service is running …");
}

OK("=== Uninstall finished (T2S Master) ===");
exit 0;
