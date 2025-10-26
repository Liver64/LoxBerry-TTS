#!/usr/bin/env perl
# uninstall_t2s_master.pl — Uninstall for T2S Master (matches generate_mosquitto_certs.pl)
#
# DEFAULT (no options): remove generator artifacts EXCEPT certificates/keys/CA.
#   - Removes: conf.d drop-ins, ACL file, bridge bundle
#   - Keeps : server/client certs, CA store, CA links
#   - Restarts mosquitto
#
# SUPPORT OPTIONS:
#   --full  : additionally remove server/client certs, CA store and CA links
#   --debug : verbose logging
#   --quiet : minimal console output
#   --help  : this help
#
# Return codes: 0 OK | 1 minor issues | >1 error

use strict;
use warnings;
use utf8;
use Getopt::Long qw(GetOptions);
use File::Spec;
use File::Path qw(make_path remove_tree);
use File::Basename qw(dirname);
use Time::HiRes qw(gettimeofday);
use POSIX qw(setsid);
use open ':std', ':utf8';
binmode STDOUT, ':utf8';
use LoxBerry::System;

# ---------- Constants (must match generator) ----------
my $CONF_PER     = '/etc/mosquitto/conf.d/00-global-per-listener.conf';
my $CONF_TLS     = '/etc/mosquitto/conf.d/10-listener-tls.conf';
my $ACL_FILE     = '/etc/mosquitto/tts-aclfile';

my $CERTDIR      = '/etc/mosquitto/certs';
my $CA_PERSIST   = '/etc/mosquitto/ca';
my $CA_PRIVDIR   = File::Spec->catdir($CA_PERSIST, 'private');

my $SRV_KEY      = File::Spec->catfile($CERTDIR, 't2s.key');
my $SRV_CRT      = File::Spec->catfile($CERTDIR, 't2s.crt');
my $CA_CRT_LINK  = File::Spec->catfile($CERTDIR, 'mosq-ca.crt');
my $CA_KEY_LINK  = File::Spec->catfile($CERTDIR, 'mosq-ca.key');

my $CLI_DIR      = File::Spec->catdir($CERTDIR, 'clients', 'sip_bridge');
my $CLI_CRT      = File::Spec->catfile($CLI_DIR, 'client.crt');
my $CLI_KEY      = File::Spec->catfile($CLI_DIR, 'client.key');

# Bridge bundle (created by generator --bundle)
my $BRIDGE_DIR   = 'REPLACELBHOMEDIR/config/plugins/text2speech/bridge';
my $BUNDLE       = File::Spec->catfile($BRIDGE_DIR, 'sip_bundle.tar.gz');

# ---------- Logging (persistent; not tied to plugin dirs) ----------
my $LOG_DIR   = '/var/log';
my $LOG_FILE  = File::Spec->catfile($LOG_DIR, 'uninstall_t2s_plugin.log');

my $SETFACL   = (-x '/usr/bin/setfacl') ? '/usr/bin/setfacl'
             : (-x '/bin/setfacl')      ? '/bin/setfacl' : '';
my $SYSTEMCTL = (-x '/bin/systemctl')   ? '/bin/systemctl'
             : (-x '/usr/bin/systemctl')? '/usr/bin/systemctl' : '';

# ---------- CLI ----------
my ($HELP,$QUIET,$DEBUG,$FULL) = (0,0,0,0);
GetOptions(
  'help'  => \$HELP,
  'quiet' => \$QUIET,
  'debug' => \$DEBUG,
  'full'  => \$FULL,
) or die "Invalid options. Use --help\n";

if ($HELP) {
  print <<"USAGE";
Usage:
  perl uninstall_t2s_master.pl            # Default: remove drop-ins + ACL + bundle, keep certs/CA, restart mosquitto
  perl uninstall_t2s_master.pl --full     # Support: additionally remove certs/CA/links and client materials, restart
  perl uninstall_t2s_master.pl --debug    # Support: verbose logging
  perl uninstall_t2s_master.pl --quiet    # Support: minimal console output
  perl uninstall_t2s_master.pl --help     # This help
USAGE
  exit 0;
}

# ---------- Logger ----------
my $LOG_OK = 0;
sub ts    { my ($s,$us)=gettimeofday(); my @t=localtime($s); sprintf("%02d-%02d-%04d %02d:%02d:%02d",$t[3],$t[4]+1,$t[5]+1900,$t[2],$t[1],$t[0]) }
sub _line { my($lvl,$m)=@_; sprintf("%s <%s> %s\n", ts(), $lvl, $m) }
sub logx  { my($lvl,$m)=@_; my $L=_line($lvl,$m); print $L unless $QUIET; print LOG $L if $LOG_OK; }
sub OK    { logx('OK',   shift) } sub INFO{ logx('INFO', shift) } sub WARN{ logx('WARN', shift) } sub ERR{ logx('ERR', shift) }
sub DEB   { logx('DEB',  shift) if $DEBUG }

eval { make_path($LOG_DIR) unless -d $LOG_DIR; 1; };
if (open(LOG, ">>:utf8", $LOG_FILE)) { select((select(LOG), $|=1)[0]); $LOG_OK = 1; }

INFO("=== T2S Master Uninstall ===");
INFO($FULL ? "Mode: SUPPORT --full (purge certs/CA as well)" : "Mode: DEFAULT (drop-ins+ACL+bundle only; keep certs/CA)");

# ---------- Helpers ----------
sub _read_head {
  my ($path, $bytes) = (@_, 2048);
  return '' unless -f $path && -r _;
  open my $fh, '<:utf8', $path or return '';
  read $fh, my $buf, $bytes; close $fh; return $buf // '';
}
# Permissive marker
my $MARK_RE = qr{
  Auto[-\s]?(?:generated|written)\s+by\s+
  (?:generate_mosquitto_certs\.pl|Text2Speech\s+Plugin(?:\.pl)?)
}ix;
sub _has_marker_conf  { my ($p)=@_; _read_head($p) =~ $MARK_RE }
sub _has_marker_acl   { my ($p)=@_; _read_head($p) =~ $MARK_RE }

sub _safe_unlink { my ($p)=@_; return 1 unless $p && -e $p;
  if (unlink $p) { OK("Removed: $p"); return 1; } WARN("Failed to remove $p: $!"); 0 }
sub _safe_rmdir  { my ($d)=@_; return 1 unless $d && -d $d; rmdir $d and OK("Removed dir: $d"); 1 }
sub _safe_rmtree { my ($d)=@_; return 1 unless $d && -d $d;
  eval { remove_tree($d, {keep_root=>0}); 1 } ? OK("Removed dir: $d") : WARN("Failed to remove $d: $@"); }
sub _sudo        { my (@cmd)=@_; DEB("exec: @cmd"); system(@cmd)==0 }
sub _facl_x      { my ($t)=@_; return 1 unless $t && -e $t && $SETFACL;
  _sudo($SETFACL,'-x','u:loxberry',$t); _sudo($SETFACL,'-x','u:www-data',$t); 1 }
sub _systemctl_restart { my ($unit)=@_; return 0 unless $SYSTEMCTL && $unit;
  DEB("systemctl restart $unit"); system($SYSTEMCTL,'restart',$unit)==0 }

# ---------- DEFAULT removal ----------
INFO("Removing conf.d drop-ins (marker-checked) …");
for my $p ($CONF_PER, $CONF_TLS) {
  next unless -e $p;
  _has_marker_conf($p) ? (_facl_x($p), _safe_unlink($p)) : WARN("Skipping $p — no generator marker");
}
INFO("Removing ACL file (marker-checked) …");
if (-e $ACL_FILE) {
  _has_marker_acl($ACL_FILE) ? (_facl_x($ACL_FILE), _safe_unlink($ACL_FILE)) : WARN("Skipping $ACL_FILE — no generator marker");
}
INFO("Removing bridge bundle …");
_safe_unlink($BUNDLE);
_safe_rmdir($BRIDGE_DIR);

# ---------- FULL purge ----------
if ($FULL) {
  INFO("Support --full: removing certificates/CA as well …");
  INFO("Removing server cert/key …");
  _facl_x($SRV_CRT); _safe_unlink($SRV_CRT);
  _facl_x($SRV_KEY); _safe_unlink($SRV_KEY);

  INFO("Removing client (sip_bridge) material …");
  _facl_x($CLI_DIR);
  _safe_unlink($CLI_CRT);
  _safe_unlink($CLI_KEY);
  _safe_rmtree($CLI_DIR);
  my $clients_dir = File::Spec->catdir($CERTDIR, 'clients');
  _safe_rmdir($clients_dir);

  INFO("Removing CA links and persistent CA …");
  _facl_x($CA_CRT_LINK); _safe_unlink($CA_CRT_LINK);
  _facl_x($CA_KEY_LINK); _safe_unlink($CA_KEY_LINK);
  _facl_x($CA_PRIVDIR);  _facl_x($CA_PERSIST);
  _safe_rmtree($CA_PRIVDIR);
  _safe_rmtree($CA_PERSIST);

  for my $d ('/etc/mosquitto', $CERTDIR, File::Spec->catdir($CERTDIR,'clients'), $CA_PERSIST) {
    next unless $SETFACL && -d $d;
    _sudo($SETFACL,'-x','u:loxberry',$d); _sudo($SETFACL,'-x','u:www-data',$d);
  }
}

# ---------- Restart broker & gateway (UI-like, simple) ----------
# If you run as non-root, ensure sudoers allow these commands.
system('sudo timeout 15 REPLACELBHOMEDIR/sbin/mqtt-handler.pl action=restartgateway >/dev/null 2>&1 || true');
OK("Mosquitto has been restarted");

OK("=== Uninstall finished (T2S Master) ===");
exit 0;
