#!/usr/bin/env perl
# install_sip_client_bridge.pl — Install the MQTT bridge config to a T2S Master via mTLS
# RUN AS: loxberry (NOT root)
#
# - Creates /etc/mosquitto/role/sip-bridge via sudo (idempotent)
# - Aborts if /etc/mosquitto/role/t2s-master exists (to protect Master role)
# - Extracts sip_bundle.tar.gz, parses master.info (JSON or KEY:VALUE/KEY=VALUE)
# - Installs CA/cert/key into /etc/mosquitto/{ca,certs}/sip-bridge with secure perms
# - Writes 30-bridge-t2s.conf
# - Moves legacy mqttgateway confs to conf.d/disabled
# - Optionally installs a local listener drop-in
# - Restarts Mosquitto via mqtt-handler (unless --no-restart)

use strict;
use warnings;
use utf8;
use Getopt::Long qw(GetOptions);
use File::Temp qw(tempdir);
use File::Spec;
use File::Path qw(make_path);
use File::Basename qw(basename dirname);
use POSIX qw(strftime);
use File::Copy qw(move);
use Sys::Hostname qw(hostname);
use open ':std', ':utf8';

# ---------- Logging Setup (English messages, tag scheme) ----------
my $logfile = '/opt/loxberry/log/plugins/text2sip/client_install.log';
open(my $logfh, '>>', $logfile) or die "Cannot open log file $logfile: $!";

sub _ts { strftime "%Y-%m-%d %H:%M:%S", localtime }
sub _log { my ($tag,$msg)=@_; print $logfh "["._ts()."] $tag $msg\n" }

sub log_ok     { _log('<OK>',      "@_"); }
sub log_info   { _log('<INFO>',    "@_"); }
sub log_warn   { _log('<WARNING>', "@_"); }
sub log_error  { _log('<ERROR>',   "@_"); print STDERR "<ERROR>: @_ \n"; exit 1; }

log_info("==== Starting Text2SIP bridge client install ====");

# ---------- Constants ----------
my $BUNDLE_DEFAULT = '/opt/loxberry/config/plugins/text2sip/bridge/t2s_bundle.tar.gz';

my $CA_DIR_SYS     = '/etc/mosquitto/ca';
my $CERTS_DIR_SYS  = '/etc/mosquitto/certs';
my $CONF_DIR_SYS   = '/etc/mosquitto/conf.d';
my $ROLE_DIR       = '/etc/mosquitto/role';

my $MASTER_MARKER  = File::Spec->catfile($ROLE_DIR, 't2s-master');
my $BRIDGE_MARKER  = File::Spec->catfile($ROLE_DIR, 'sip-bridge');

my $listener_src   = '/opt/loxberry/webfrontend/htmlauth/plugins/text2sip/conf/10-local-listener.conf';
my $listener_dst   = File::Spec->catfile($CONF_DIR_SYS, '10-local-listener.conf');
my $disable_dir    = File::Spec->catdir($CONF_DIR_SYS, 'disabled');

my $BRIDGE_CONF    = File::Spec->catfile($CONF_DIR_SYS, '30-bridge-t2s.conf');

# Parsed / derived later
my ($CA_FILE_SYS, $CERT_FILE_SYS, $KEY_FILE_SYS, $CLIENT_ID);
my ($BRIDGE_HOST, $BRIDGE_PORT) = ('t2s.local', 8883);

# ---------- CLI ----------
my $bundle     = $BUNDLE_DEFAULT;
my $no_restart = 0;
my $help       = 0;

Getopt::Long::Configure('bundling');
GetOptions(
  'bundle|b=s'   => \$bundle,
  'no-restart!'  => \$no_restart,
  'help|h!'      => \$help,
) or log_error("Invalid options. Use --help");

if ($help) {
  print <<"USAGE";
Usage: install_sip_client_bridge.pl [--bundle PATH] [--no-restart]
  --bundle PATH   Path to sip_bundle.tar.gz (default: $BUNDLE_DEFAULT)
  --no-restart    Skip Mosquitto restart at the end
  --help          Show this help
USAGE
  exit 0;
}

# ---------- Guard: must run as loxberry (not root) ----------
if ($> == 0) {
  log_error("Run this script as 'loxberry', not root.");
}

# ---------- Role directory + markers (via sudo) ----------
# Ensure role dir exists (root:root 0755)
system('sudo','/usr/bin/install','-o','root','-g','root','-m','0755','-d', $ROLE_DIR) == 0
  or log_error("Cannot create role directory '$ROLE_DIR' via sudo install -d");

# Abort if Master marker exists
if (-e $MASTER_MARKER) {
  log_error("Found role marker '$MASTER_MARKER' (T2S Master) — aborting installation to protect Mosquitto.");
}

# Create sip-bridge marker idempotently (root:root 0644)
if (! -e $BRIDGE_MARKER) {
  system('sudo','/usr/bin/install','-o','root','-g','root','-m','0644','/dev/null', $BRIDGE_MARKER) == 0
    or log_error("Failed to create role marker '$BRIDGE_MARKER' with sudo install");
  log_info("Created role marker '$BRIDGE_MARKER'.");
}

# ---------- Bundle sanity ----------
(-f $bundle) or log_error("Bundle not found: $bundle");
(-r $bundle) or log_error("Bundle not readable: $bundle");
log_ok("Bundle found: $bundle");

# ---------- /etc/hosts helper (guarded) ----------
sub ensure_etc_hosts_entry {
  my ($ip, $hostname) = @_;
  return unless $ip && $hostname;
  my $line = "$ip\t$hostname\n";
  my $cmd = qq[bash -lc 'grep -qw "$hostname" /etc/hosts || echo "$line" | sudo /usr/bin/tee -a /etc/hosts >/dev/null'];
  system($cmd) == 0
    ? log_info("Ensured /etc/hosts entry: $hostname → $ip")
    : log_warn("Could not update /etc/hosts for $hostname (non-fatal).");
}

# ---------- Extract bundle ----------
my $tmpdir = tempdir('sip_bundle_XXXXXX', TMPDIR => 1, CLEANUP => 1);
log_info("Extracting bundle to $tmpdir ...");
system('tar', '-xzf', $bundle, '-C', $tmpdir) == 0
  or log_error("Failed to extract bundle");

# ---------- Helpers to find files in extracted tree ----------
sub find_first {
  my ($root, $regex) = @_;
  my @todo = ($root);
  while (@todo) {
    my $d = shift @todo;
    opendir(my $dh, $d) or next;
    while (my $e = readdir($dh)) {
      next if $e =~ /^\.\.?$/;
      my $p = "$d/$e";
      if (-d $p) { push @todo, $p; next; }
      return $p if $p =~ $regex;
    }
    closedir $dh;
  }
  return undef;
}

sub trim { my $s = shift // ''; $s =~ s/^\s+|\s+$//gr }

# ---------- Parse master.info ----------
sub parse_master_info {
  my ($path) = @_;
  my ($ip, $host, $port, $cid);
  open my $fh, '<', $path or return ();
  my $raw = do { local $/; <$fh> }; close $fh;

  if ($raw =~ /^\s*\{.*\}\s*$/s) {
    eval {
      require JSON::PP;
      my $j = JSON::PP::decode_json($raw);
      $ip   = $j->{MASTER_IP}   // $j->{IP};
      $host = $j->{MASTER_HOST} // $j->{HOST};
      $port = $j->{MQTT_TLS_PORT} // $j->{MQTT_PORT} // $j->{TLS_PORT} // $j->{PORT};
      $cid  = $j->{CLIENT_ID};
    };
  } else {
    for my $l (split /\R/, $raw) {
      next unless $l =~ /[:=]/;
      my ($k, $v) = $l =~ /^\s*([^:=\s]+)\s*[:=]\s*(.+?)\s*$/;
      next unless $k;
      $k = uc $k;
      $v = trim($v);
      $ip   = $v if $k eq 'MASTER_IP'   || $k eq 'IP';
      $host = $v if $k eq 'MASTER_HOST' || $k eq 'HOST';
      $port = $v if $k =~ /^(MQTT_TLS_PORT|TLS_PORT|MQTT_PORT|PORT)$/;
      $cid  = $v if $k eq 'CLIENT_ID';
    }
  }
  ensure_etc_hosts_entry($ip, $host) if $ip && $host;
  return ($ip, $host, $port, $cid);
}

my $master_info = find_first($tmpdir, qr/master\.info$/);
if ($master_info) {
  log_info("Found master.info: $master_info");
  my ($ip, $host, $port, $cid) = parse_master_info($master_info);
  $BRIDGE_HOST = $host // $ip if $host || $ip;
  $BRIDGE_PORT = $port if defined $port && $port =~ /^\d+$/;
  $CLIENT_ID   = $cid if defined $cid && $cid ne '';
  $CLIENT_ID ||= 't2s-bridge';
} else {
  log_warn("master.info not found — using fallback $BRIDGE_HOST:$BRIDGE_PORT");
  $CLIENT_ID = 't2s-bridge';
}

# ---------- Locate cert files in bundle ----------
my $ca_in  = find_first($tmpdir, qr{(?:^|/)(?:mosq-ca|ca)\.crt$}i);
my $crt_in = find_first($tmpdir, qr{(?:^|/).+\.crt$}i);
my $key_in = find_first($tmpdir, qr{(?:^|/).+\.key$}i);

$ca_in  or log_error("Missing CA file (*.crt) in bundle");
$crt_in or log_error("Missing client certificate (*.crt) in bundle");
$key_in or log_error("Missing client key (*.key) in bundle");

# ---------- Install certs to system paths (via sudo, secure perms) ----------
my $CERT_SUBDIR = File::Spec->catdir($CERTS_DIR_SYS, 'sip-bridge');
$CA_FILE_SYS    = File::Spec->catfile($CA_DIR_SYS, 'mosq-ca.crt');
$CERT_FILE_SYS  = File::Spec->catfile($CERT_SUBDIR, "$CLIENT_ID.crt");
$KEY_FILE_SYS   = File::Spec->catfile($CERT_SUBDIR, "$CLIENT_ID.key");

# Ensure dirs
system('sudo','/usr/bin/install','-o','root','-g','root','-m','0755','-d', $CA_DIR_SYS) == 0
  or log_error("Failed to ensure CA dir '$CA_DIR_SYS'");
system('sudo','/usr/bin/install','-o','root','-g','mosquitto','-m','0755','-d', $CERT_SUBDIR) == 0
  or log_error("Failed to ensure cert subdir '$CERT_SUBDIR'");

# Install files
# CA (root:root 0644) — overwrite allowed
system('sudo','/usr/bin/install','-o','root','-g','root','-m','0644', $ca_in,  $CA_FILE_SYS) == 0
  or log_warn("Could not install CA file to $CA_FILE_SYS (non-fatal)");

# Client cert (mosquitto:mosquitto 0644)
system('sudo','/usr/bin/install','-o','mosquitto','-g','mosquitto','-m','0644', $crt_in, $CERT_FILE_SYS) == 0
  or log_error("Failed to install client cert to $CERT_FILE_SYS");

# Client key (mosquitto:mosquitto 0640)
system('sudo','/usr/bin/install','-o','mosquitto','-g','mosquitto','-m','0640', $key_in, $KEY_FILE_SYS) == 0
  or log_error("Failed to install client key to $KEY_FILE_SYS");

log_ok("Installed certificates for client '$CLIENT_ID'.");

# ---------- Write bridge config ----------
my $conf_txt = <<"CONF";
# Auto-written by Text2SIP — MQTT bridge config (client: $CLIENT_ID)

connection t2s-master-bridge
address $BRIDGE_HOST:$BRIDGE_PORT

clientid $CLIENT_ID
cleansession true
restart_timeout 2 30
try_private true

bridge_cafile    $CA_FILE_SYS
bridge_certfile  $CERT_FILE_SYS
bridge_keyfile   $KEY_FILE_SYS
bridge_insecure  false
tls_version      tlsv1.2

# Robust options
notifications    true
bridge_protocol_version mqttv311

# Upstream: local → Master
topic tts-publish/# out 0
topic tts-handshake/request out 0

# Downstream: Master → local
topic tts-subscribe/# in 0
topic tts-handshake/response/# in 0
CONF

my $tmp_conf = File::Spec->catfile($tmpdir, '30-bridge-t2s.conf');
open my $cfh, '>', $tmp_conf or log_error("Failed to write temp conf: $tmp_conf");
print $cfh $conf_txt;
close $cfh;

system('sudo','/usr/bin/install','-o','root','-g','mosquitto','-m','0644', $tmp_conf, $BRIDGE_CONF) == 0
  or log_error("Failed to install bridge conf to $BRIDGE_CONF");

log_ok("Installed bridge config: $BRIDGE_CONF");

# ---------- Disable legacy mqttgateway configs (idempotent) ----------
system('sudo','/usr/bin/install','-o','root','-g','root','-m','0755','-d', $disable_dir) == 0
  or log_error("Failed to create $disable_dir via sudo install -d");

for my $file (
  File::Spec->catfile($CONF_DIR_SYS, 'mosq_mqttgateway.conf'),
  File::Spec->catfile($CONF_DIR_SYS, 'mosq_passwd'),
) {
  next unless -e $file;
  my $dest = File::Spec->catfile($disable_dir, basename($file));
  my $rc = system('sudo','/bin/mv', $file, $dest);
  if ($rc == 0) {
    log_ok("Moved $file → $dest");
  } else {
    log_warn("Failed to move $file → $dest (exit code $rc)");
  }
}

# ---------- Optional: copy local listener drop-in ----------
if (-e $listener_src) {
  system('sudo','/usr/bin/install','-o','root','-g','root','-m','0644', $listener_src, $listener_dst) == 0
    ? log_ok("Installed listener drop-in: $listener_dst")
    : log_error("Failed to install listener drop-in to $listener_dst");
} else {
  log_warn("Static listener config not found at $listener_src. Skipping.");
}

# ---------- Rename processed bundle (timestamped) ----------
my $timestamp  = strftime "%Y-%m-%d_%H-%M-%S", localtime;
my $bundle_dir = dirname($bundle);
my $new_bundle = File::Spec->catfile($bundle_dir, "t2s_bundle-$timestamp.tar.gz");
if (move($bundle, $new_bundle)) {
  log_ok("Bundle renamed to '$new_bundle'");
} else {
  log_warn("Failed to rename bundle: $!");
}

# ---------- Restart Mosquitto (unless suppressed) ----------
unless ($no_restart) {
  log_info("Restarting Mosquitto via mqtt-handler …");
  system('sudo /opt/loxberry/sbin/mqtt-handler.pl action=restartgateway >/dev/null 2>&1 || true');
}

log_ok("Bridge install completed.");
log_info("==== Finished install script ====");
exit 0;
