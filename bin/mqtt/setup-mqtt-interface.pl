#!/usr/bin/env perl

use strict;
use warnings;
use utf8;
binmode STDOUT, ":encoding(UTF-8)";

use Getopt::Long qw(GetOptions);
use File::Spec;
use File::Path qw(make_path);
use File::Basename qw(dirname);
use POSIX qw(strftime);
use Fcntl qw(:mode);
use Socket;
use LoxBerry::System;

# ========= Pfade =========
my $CERTDIR       = "/etc/mosquitto/certs";                # Mosquitto Cert-Dir
my $CA_PERSIST    = "/etc/mosquitto/ca";                   # Persistente CA (Debian-konform)
my $CA_PRIVDIR    = File::Spec->catdir($CA_PERSIST, "private");
my $BRIDGE_DIR    = "REPLACELBHOMEDIR/config/plugins/text2speech/bridge"; # Bundle-Ziel
my $log_dir  	  = 'REPLACELBHOMEDIR/log/plugins/text2speech';
my $LOGFILE       = "REPLACELBHOMEDIR/log/plugins/text2speech/setup-mqtt-interface.log";
my $CLIENT_ID 	  = "t2s-bridge";
my $BUNDLE_NAME   = "t2s_bundle.tar.gz";

# conf.d Dateien
my $conf_per = "/etc/mosquitto/conf.d/00-global-per-listener.conf";
my $conf_tls = "/etc/mosquitto/conf.d/10-listener-tls.conf";

# CA Dateien (persistent)
my $CA_KEY        = File::Spec->catfile($CA_PRIVDIR, "mosq-ca.key");
my $CA_CRT        = File::Spec->catfile($CA_PERSIST,  "mosq-ca.crt");

# CA Symlinks/Kopien im Mosquitto-Verzeichnis (für bequeme Referenz)
my $CA_KEY_LINK   = File::Spec->catfile($CERTDIR, "mosq-ca.key");
my $CA_CRT_LINK   = File::Spec->catfile($CERTDIR, "mosq-ca.crt");

# Server Dateien (im Mosquitto-Verzeichnis)
my $SRV_KEY       = File::Spec->catfile($CERTDIR, "t2s.key");
my $SRV_CSR       = File::Spec->catfile($CERTDIR, "t2s.csr");
my $SRV_CRT       = File::Spec->catfile($CERTDIR, "t2s.crt");

# Client (SIP Bridge)
my $CLI_DIR       = File::Spec->catdir($CERTDIR, "clients", $CLIENT_ID);
my $CLI_KEY       = File::Spec->catfile($CLI_DIR, "client.key");
my $CLI_CSR       = File::Spec->catfile($CLI_DIR, "client.csr");
my $CLI_CRT       = File::Spec->catfile($CLI_DIR, "client.crt");

# ========= Default-Optionen =========
my $force           = 0;
my $force_ca        = 0;
my $force_server    = 0;
my $force_client    = 0;
my $write_conf      = 0;    # per --write-conf aktivieren
my $bundle          = 0;
my @sans            = ();   # via --san befüllbar, ansonsten Auto-SANs
my $server_cn       = "t2s.local";
my $days_ca         = 3650;
my $days_cert       = 825;
my $debug           = 0;
my $help            = 0;

# TLS-Config-Steuerung:
my $listener_port   = 8883;
my $listener_addr   = "";
my $acl_file_name	= "tts-aclfile";
my $acl_file_path   = "/etc/mosquitto/".$acl_file_name;

GetOptions(
  "force"           => \$force,
  "force-ca"        => \$force_ca,
  "force-server"    => \$force_server,
  "force-client"    => \$force_client,
  "write-conf!"     => \$write_conf,
  "bundle"          => \$bundle,
  "san=s@"          => \@sans,
  "server-cn=s"     => \$server_cn,
  "days-ca=i"       => \$days_ca,
  "days-cert=i"     => \$days_cert,
  "log-file=s"      => \$LOGFILE,
  "debug"           => \$debug,
  "listener-port=i" => \$listener_port,
  "listener-addr=s" => \$listener_addr,
  "acl-file=s"      => \$acl_file_path,
  "help|h"          => \$help,
) or die "Bad options. Try --help\n";
if ($help) { print_help(); exit 0; }

# LoxBerry::System Debug optional lauter schalten
$LoxBerry::System::DEBUG = 1 if $debug;

# ========= Logging / Helpers =========
sub ts { strftime("%d-%m-%Y %H:%M:%S", localtime) }
sub logp {
  my ($lvl,$msg)=@_;
  my $line = sprintf("%s <%s> %s\n", ts(), $lvl, $msg);

  # immer auf STDOUT ausgeben (STDOUT hast du bereits auf UTF-8 gesetzt)
  print $line;

  # Logfile UTF-8-sicher öffnen
  eval {
    make_path(dirname($LOGFILE)) unless -d dirname($LOGFILE);
    open my $fh, ">>:encoding(UTF-8)", $LOGFILE or die $!;
    print $fh $line;
    close $fh;
  };
}
sub sh {
  my ($cmd)=@_;
  logp("DEB", "CMD: $cmd") if $debug;
  my $out = `$cmd 2>&1`;
  my $rc  = $? >> 8;
  return ($rc,$out);
}
sub esc { my($s)=@_; $s =~ s/'/'\\''/g; return "'$s'"; }

# Restlaufzeit (Tage) – WARN < 30
sub cert_days_remaining {
  my ($crt) = @_;
  return undef unless -f $crt;
  my ($rc1,$end) = sh("openssl x509 -enddate -noout -in ".esc($crt)." | cut -d= -f2");
  return undef if $rc1 || !$end;
  chomp $end;
  my ($rc2,$ts)  = sh("date -d ".esc($end)." +%s");
  return undef if $rc2 || !$ts;
  chomp $ts;
  my $days = int( ($ts - time())/86400 );
  return $days;
}
sub ensure_dir { my($d)=@_; make_path($d) unless -d $d; }

# Verzeichnis-/ACL-Helfer für loxberry: Leserechte + Betreten
sub ensure_loxb_acl {
  my ($ca_crt,$cli_dir,$cli_key,$cli_crt)=@_;

  # Basispfade begehbar + richtiger Gruppenbesitz für Traversal
  sh("chgrp -R mosquitto /etc/mosquitto/certs");
  sh("chmod 0750 /etc/mosquitto/certs");
  sh("chmod 0750 /etc/mosquitto/certs/clients");

  # Client-Unterordner (redundant, aber idempotent & sicher)
  sh("chgrp -R mosquitto ".esc($cli_dir));
  sh("chmod 0750 ".esc($cli_dir));

  # setfacl vorhanden?
  return if system("command -v setfacl >/dev/null 2>&1");

  # Verzeichnisse: Listing + Betreten
  sh("setfacl -m u:loxberry:rx /etc/mosquitto");
  sh("setfacl -m u:loxberry:rx /etc/mosquitto/certs");
  sh("setfacl -m u:loxberry:rx /etc/mosquitto/certs/clients");
  sh("setfacl -m u:loxberry:rx ".esc($cli_dir));

  # Dateien: Lesen
  sh("setfacl -m u:loxberry:r ".esc($cli_key)) if -f $cli_key;
  sh("setfacl -m u:loxberry:r ".esc($cli_crt)) if -f $cli_crt;
  sh("setfacl -m u:loxberry:r ".esc($ca_crt))  if -f $ca_crt;

  # Default-ACLs (Vererbung für neu entstehende Dateien)
  sh("setfacl -d -m u:loxberry:rx /etc/mosquitto/certs");
  sh("setfacl -d -m u:loxberry:rx /etc/mosquitto/certs/clients");
  sh("setfacl -d -m u:loxberry:rx ".esc($cli_dir));
}

sub ensure_conf_acl {
  my ($conf_per,$conf_tls,$acl_path) = @_;

  # setfacl vorhanden?
  return if system("command -v setfacl >/dev/null 2>&1");

  # Verzeichnisse: begehbar/lesbar für loxberry + Default-Vererbung
  sh("setfacl -m u:loxberry:rx /etc/mosquitto");
  sh("setfacl -m u:loxberry:rx /etc/mosquitto/conf.d");
  sh("setfacl -d -m u:loxberry:rx /etc/mosquitto/conf.d");

  # Einzeldateien: lesbar für loxberry (nur wenn vorhanden)
  for my $f ($conf_per, $conf_tls, $acl_path) {
    next unless defined $f && -f $f;
    sh("setfacl -m u:loxberry:r ".esc($f));
  }

  logp("OK","ACLs for conf.d and ACL file set for user 'loxberry' (read-only).");
}

# Ensure the T2S log path exists and is writable by user/group 'loxberry'
sub ensure_tts_log_path {
  my $log_file = "$log_dir/interface.log";
  my ($uid) = (getpwnam('loxberry'))[2];
  my ($gid) = (getgrnam('loxberry'))[2];

  # Create dir 0775 if missing
  make_path($log_dir, { mode => 0775 }) unless -d $log_dir;

  # Fix dir owner/perms if needed
  my @st = stat($log_dir);
  if (!@st || $st[4] != $uid || $st[5] != $gid || (S_IMODE($st[2]) != 0775)) {
    chown $uid, $gid, $log_dir or die "chown $log_dir: $!";
    chmod 0775, $log_dir      or die "chmod $log_dir: $!";
  }

  # Ensure file exists 0664
  if (!-e $log_file) {
    open my $fh, '>>', $log_file or die "touch $log_file: $!";
    close $fh;
  }

  # Fix file owner/perms if needed
  @st = stat($log_file);
  if (!@st || $st[4] != $uid || $st[5] != $gid || (S_IMODE($st[2]) != 0664)) {
    chown $uid, $gid, $log_file or die "chown $log_file: $!";
    chmod 0664, $log_file      or die "chmod $log_file: $!";
  }
}

sub print_help {
  my $defaults = <<"DEF";
Defaults:
  --server-cn         = $server_cn
  --days-ca           = $days_ca
  --days-cert         = $days_cert
  --log-file          = $LOGFILE
  Certdir             = $CERTDIR
  CA (persistent)     = $CA_PERSIST
  CA private dir      = $CA_PRIVDIR
  Server cert/key     = $SRV_CRT / $SRV_KEY
  Client dir          = $CLI_DIR
  Listener-Port       = $listener_port
  Listener-Addr       = @{[ $listener_addr eq '' ? '(alle Interfaces)' : $listener_addr ]}
  ACL file            = $acl_file_path
DEF

  print <<"USAGE";
setup-mqtt-interface.pl - Create/maintain persistent CA + Server/Client certs for Mosquitto (TLS v1.2)

USAGE:
  sudo ./setup-mqtt-interface.pl [OPTIONS]

OPTIONS:
  --force                 Recreate CA/Server/Client even if present (global)
  --force-ca              Only recreate CA (persistent under /etc/mosquitto/ca)
  --force-server          Only (re)issue server cert/key (t2s.crt/t2s.key)
  --force-client          Only (re)issue SIP client cert/key

  --write-conf / --no-write-conf
                          Write TLS listener drop-ins in /etc/mosquitto/conf.d (default: off)
  --listener-port N       Listener port (default: 8883)
  --listener-addr ADDR    Bind address (empty = all), e.g. 127.0.0.1 or 0.0.0.0
  --acl-file PATH         ACL file path to write (created/updated by this script)

  --bundle                Create bundle (tar.gz) with CA + server + SIP client cert/key
  --san DNS:x / --san IP:y
                          Extra SANs for server cert (in addition to auto-detected)
  --server-cn NAME        Common Name for server cert (default: t2s.local)
  --days-ca N             CA validity in days
  --days-cert N           Server/Client validity in days
  --log-file PATH         Logfile path
  --debug                 Verbose commands; also enables LoxBerry::System debug
  -h, --help              Show help and exit

BEHAVIOR:
  * Persistent CA under /etc/mosquitto/ca (private key in /etc/mosquitto/ca/private).
  * CA is mirrored into $CERTDIR as mosq-ca.crt/key for simple cafile/keyfile references.
  * Server cert/key ($SRV_CRT / $SRV_KEY) are reused if valid; same for client cert.
  * WARN if any cert expires <= 30 days (no auto-renew).
  * With --write-conf, TLS listener (TLS v1.2, mTLS) drop-ins are written. ACL file is created.
  * SANs: Auto-augment with lbhostname, lbfriendlyname (dnsified) and get_localip(); duplicates removed.

EXAMPLES:
  # Fresh install (write confs, build bundle):
  sudo ./setup-mqtt-interface.pl --write-conf --bundle

  # Reissue server only:
  sudo ./setup-mqtt-interface.pl --force-server

$defaults
USAGE
}

# ========= Start =========
logp("INFO", "=== setup-mqtt-interface start ===");
ensure_dir($CA_PERSIST);
ensure_dir($CA_PRIVDIR);
ensure_dir($CERTDIR);
ensure_dir($CLI_DIR);
ensure_dir($BRIDGE_DIR);
ensure_tts_log_path();

# Sichere Basisrechte für CA-Privatbereich (WinSCP-Sichtbarkeit; Key bleibt 0600)
sh("chown -R root:root ".esc($CA_PERSIST));
sh("chmod 0755 ".esc($CA_PRIVDIR));
logp("WARN","CA private dir set to 0755 for WinSCP visibility. Files remain protected (key=0600), but directory listing is world-readable.");

# ========= Auto-SANs via LoxBerry =========
my $lb_ip   = LoxBerry::System::get_localip();
my $lb_host = LoxBerry::System::lbhostname();
my $lb_friendly = LoxBerry::System::lbfriendlyname();
# DNSify friendly (nur Buchstaben/Zahlen/Bindestrich)
my $friendly_dns = $lb_friendly // "";
$friendly_dns =~ s/[^A-Za-z0-9\-]/-/g;
$friendly_dns =~ s/^-+//; $friendly_dns =~ s/-+$//;

my @auto_sans;
push @auto_sans, "DNS:$lb_host"        if defined $lb_host && $lb_host ne '';
push @auto_sans, "DNS:$friendly_dns"   if defined $lb_friendly && $friendly_dns ne '' && $friendly_dns ne $lb_host;
push @auto_sans, "IP:$lb_ip"           if defined $lb_ip && $lb_ip ne '' && $lb_ip ne '127.0.0.1';

# ========= (1) CA persistent nutzen/erstellen =========
my $have_ca = (-f $CA_CRT && -f $CA_KEY) ? 1 : 0;

if ($have_ca && !$force && !$force_ca) {
  my $days = cert_days_remaining($CA_CRT);
  if (!defined $days) {
    logp("ERR","Cannot read CA expiry, will recreate.");
    $have_ca = 0;
  } elsif ($days <= 0) {
    logp("ERR","CA expired ($days days). Please plan renewal.");
  } elsif ($days <= 30) {
    logp("WARN","CA expires in $days days: $CA_CRT");
  } else {
    logp("INFO","Using existing persistent CA (valid $days days).");
  }
}

if (!$have_ca || $force || $force_ca) {
  logp("INFO","Creating new persistent CA under $CA_PERSIST …");
  my ($r1,$o1) = sh("openssl genrsa -out ".esc($CA_KEY)." 4096");
  die "CA key failed: $o1" if $r1;
  my ($r2,$o2) = sh("openssl req -x509 -new -nodes -key ".esc($CA_KEY)." -sha256 -days $days_ca ".
                    "-subj ".esc("/CN=T2S-CA")." -out ".esc($CA_CRT));
  die "CA crt failed: $o2" if $r2;
  sh("chmod 600 ".esc($CA_KEY));
  sh("chmod 644 ".esc($CA_CRT));
  logp("OK","New CA created.");
}

# CA in $CERTDIR referenzierbar machen (per Symlink/Kopie)
for my $pair ( [$CA_CRT,$CA_CRT_LINK,0644], [$CA_KEY,$CA_KEY_LINK,0640] ) {
  my ($src,$dst,$mode) = @$pair;
  unlink $dst if -l $dst || -f $dst;
  if (symlink($src,$dst)) {
    sh("chown root:mosquitto ".esc($dst));
    sh("chmod ".sprintf("%04o",$mode)." ".esc($dst));
  } else {
    my ($r,$o) = sh("install -o root -g mosquitto -m ".sprintf("%04o",$mode)." ".esc($src)." ".esc($dst));
    $r && logp("ERR","Failed to place CA link/copy: $dst: $o");
  }
}
logp("OK","CA linked/placed in $CERTDIR (mosq-ca.crt / mosq-ca.key)");

# ========= (2) Server-Zertifikat (re)use/erstellen =========
my $reuse_server = 0;
if (-f $SRV_CRT && -f $SRV_KEY && !$force && !$force_server) {
  my $days = cert_days_remaining($SRV_CRT);
  if (defined $days) {
    if ($days <= 0) {
      logp("ERR","Server cert expired ($days days): $SRV_CRT");
    } elsif ($days <= 30) {
      logp("WARN","Server cert expires in $days days: $SRV_CRT");
      $reuse_server = 1;
    } else {
      logp("INFO","Reusing existing server cert (valid $days days).");
      $reuse_server = 1;
    }
  } else {
    logp("WARN","Cannot read server cert expiry; will recreate.");
  }
  sh("chown root:mosquitto ".esc($SRV_CRT)." ".esc($SRV_KEY));
  sh("chmod 0644 ".esc($SRV_CRT));
  sh("chmod 0640 ".esc($SRV_KEY));
}

# SANs: Basis + Auto + ggf. --san; Duplikate filtern
my %seen;
my @san_entries = grep { !$seen{$_}++ } (
  "DNS:$server_cn", "DNS:localhost", "IP:127.0.0.1",
  @auto_sans,
  @sans
);

if (!$reuse_server) {
  my $san_str = join(",", @san_entries);
  logp("INFO","Final SANs: $san_str");
  logp("INFO","(Re)creating server certificate t2s.crt with CN=$server_cn");

  my ($rk,$ok) = sh("openssl genrsa -out ".esc($SRV_KEY)." 4096");
  die "server key failed: $ok" if $rk;
  sh("chmod 0640 ".esc($SRV_KEY));
  sh("chown root:mosquitto ".esc($SRV_KEY));

  my ($rcsr,$oc) = sh("openssl req -new -key ".esc($SRV_KEY)." -subj ".esc("/CN=$server_cn")." -out ".esc($SRV_CSR));
  die "server csr failed: $oc" if $rcsr;

  my $extf = "/tmp/t2s_ext.$$";
  open my $ef, ">", $extf or die $!;
  print $ef "subjectAltName=$san_str\n";
  close $ef;

  my ($rs,$os) = sh("openssl x509 -req -in ".esc($SRV_CSR)." -CA ".esc($CA_CRT)." -CAkey ".esc($CA_KEY)." -CAcreateserial ".
                    "-out ".esc($SRV_CRT)." -days $days_cert -sha256 -extfile ".esc($extf));
  unlink $extf;
  die "server crt failed: $os" if $rs;

  sh("chown root:mosquitto ".esc($SRV_CRT));
  sh("chmod 0644 ".esc($SRV_CRT));
  logp("OK","Server certificate created: $SRV_CRT");
} else {
  my $san_str = join(",", @san_entries);
  logp("INFO","Final SANs: $san_str");
}

# ========= (3) Client (SIP Bridge) – reuse/erstellen =========
my $reuse_client = 0;
if (-f $CLI_CRT && -f $CLI_KEY && !$force && !$force_client) {
  my $days = cert_days_remaining($CLI_CRT);
  if (defined $days) {
    if ($days <= 0) {
      logp("ERR","Client cert expired ($days days): $CLI_CRT");
    } elsif ($days <= 30) {
      logp("WARN","Client cert expires in $days days: $CLI_CRT");
      $reuse_client = 1;
    } else {
      logp("INFO","Reusing existing client cert (valid $days days).");
      $reuse_client = 1;
    }
  }
  sh("chown root:mosquitto ".esc($CLI_KEY)." ".esc($CLI_CRT));
  sh("chmod 0640 ".esc($CLI_KEY));
  sh("chmod 0644 ".esc($CLI_CRT));
}

if (!$reuse_client) {
  logp("INFO","(Re)creating SIP client certificate …");
  my ($rk,$ok) = sh("openssl genrsa -out ".esc($CLI_KEY)." 2048");
  die "client key failed: $ok" if $rk;
  sh("chown root:mosquitto ".esc($CLI_KEY));
  sh("chmod 0640 ".esc($CLI_KEY));
  my ($rcsr,$oc) = sh("openssl req -new -key ".esc($CLI_KEY)." -subj ".esc("/CN=$CLIENT_ID")." -out ".esc($CLI_CSR));
  die "client csr failed: $oc" if $rcsr;
  my ($rs,$os) = sh("openssl x509 -req -in ".esc($CLI_CSR)." -CA ".esc($CA_CRT)." -CAkey ".esc($CA_KEY)." -CAcreateserial ".
                    "-out ".esc($CLI_CRT)." -days $days_cert -sha256");
  die "client crt failed: $os" if $rs;
  sh("chown root:mosquitto ".esc($CLI_CRT));
  sh("chmod 0644 ".esc($CLI_CRT));
  logp("OK","Client certificate created: $CLI_CRT");
}

# ========= (3b) loxberry-ACL/Verzeichnisrechte immer sicherstellen =========
ensure_loxb_acl($CA_CRT, $CLI_DIR, $CLI_KEY, $CLI_CRT);

# ========= (4) Mosquitto TLS-Config schreiben (optional) + ACL-Datei =========
if ($write_conf) {
  # 00-global: nur wirklich globale Settings
  open my $f1, ">", $conf_per or die "Cannot write $conf_per: $!";
  print $f1 <<"PER";
# Auto-generated by Text2Speech Plugin
per_listener_settings true
sys_interval 10
PER
  close $f1;
  sh("chown root:mosquitto ".esc($conf_per));
  sh("chmod 0640 ".esc($conf_per));

  # 10-tls: Listener + TLS/mTLS + allow_anonymous false HIER
  open my $f2, ">", $conf_tls or die "Cannot write $conf_tls: $!";
  print $f2 "# Auto-generated by setup-mqtt-interface.pl\n";
  if (defined $listener_addr && $listener_addr ne '') {
    print $f2 "bind_address $listener_addr\n";
  }
  print $f2 <<"TLS";
# Auto-generated by Text2Speech Plugin

listener $listener_port
protocol mqtt

allow_anonymous false

cafile $CA_CRT_LINK
certfile $SRV_CRT
keyfile  $SRV_KEY

require_certificate true
use_identity_as_username true
tls_version tlsv1.2

# ACL is referenced here
acl_file $acl_file_path

# Optional: ciphers je nach OpenSSL-Version
# ciphers ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384
TLS
  close $f2;
  sh("chown root:mosquitto ".esc($conf_tls));
  sh("chmod 0640 ".esc($conf_tls));

  logp("OK","Mosquitto TLS config written: $conf_per, $conf_tls");

  # ACL-Datei erzeugen/aktualisieren (statisches Fallback)
  my $server_cn_acl = $server_cn;
  my ($rsubj,$subj) = sh("openssl x509 -in ".esc($SRV_CRT)." -noout -subject");
  if (!$rsubj && $subj =~ /CN\s*=\s*([^\/\n]+)/) {
    $server_cn_acl = $1;
    logp("OK","Detected server CN for ACL: $server_cn_acl");
  }
  my $acl = <<"ACL";
# Auto-generated by Text2Speech Plugin

# --- Client (certificate CN = $CLIENT_ID) ---
user $CLIENT_ID
topic write tts-publish/#
topic read tts-subscribe/#
topic write tts-subscribe/#
topic read tts-publish/#
topic write tts-handshake/#
topic read tts-handshake/#

# --- T2S Master (certificate CN = $server_cn_acl) ---
user $server_cn_acl
topic write tts-publish/#
topic read tts-subscribe/#
topic write tts-subscribe/#
topic read tts-publish/#
topic write tts-handshake/#
topic read tts-handshake/#

# --- optional diagnostics ---
topic read \$SYS/#
ACL
  open my $af, ">", $acl_file_path or die "Cannot write $acl_file_path: $!";
  print $af $acl; close $af;
  sh("chown root:mosquitto ".esc($acl_file_path));
  sh("chmod 0640 ".esc($acl_file_path));
  logp("OK","ACL written: $acl_file_path");
  # WinSCP-Leserechte für loxberry auf conf.d + ACL-Datei
  ensure_conf_acl($conf_per, $conf_tls, $acl_file_path);
} else {
  logp("WARN","--no-write-conf: skipping conf generation");
  ensure_conf_acl($conf_per, $conf_tls, $acl_file_path);
}

# ========= (5) Optional: Bundle bauen =========
if ($bundle) {
  my $bundle_path = File::Spec->catfile($BRIDGE_DIR, $BUNDLE_NAME);
  my $tmpdir = "/tmp/sip_bundle.$$";
  ensure_dir($tmpdir);
  my $cli_rel = "clients/".$CLIENT_ID;

  sh("mkdir -p ".esc("$tmpdir/$cli_rel"));
  sh("cp ".esc($CA_CRT)." ".esc("$tmpdir/"));
  sh("cp ".esc($SRV_CRT)." ".esc("$tmpdir/"));
  sh("cp ".esc($SRV_KEY)." ".esc("$tmpdir/"));
  sh("cp ".esc($CLI_CRT)." ".esc("$tmpdir/$cli_rel/"));
  sh("cp ".esc($CLI_KEY)." ".esc("$tmpdir/$cli_rel/"));
  sh("cp ".esc($acl_file_path)." ".esc("$tmpdir/"));
  # --- master info for clients (minimal, no format changes) ---
  eval {
    my $meta = "$tmpdir/master.info";
    open my $mh, ">", $meta or die $!;
    print $mh "MASTER_HOST=$server_cn\n";
    if (defined $lb_ip && $lb_ip ne '' && $lb_ip ne '127.0.0.1') {
      print $mh "MASTER_IP=$lb_ip\n";
    }
    print $mh "MQTT_PORT=$listener_port\n";
	print $mh "CLIENT_ID=$CLIENT_ID\n";
    close $mh;
  };

  my ($rt,$ot) = sh("tar -C ".esc($tmpdir)." -czf ".esc($bundle_path)." .");
  if (!$rt) {
    logp("OK","Bundle created: $bundle_path (contains mosq-ca.crt, t2s.crt, t2s.key, master.info, clients/".$CLIENT_ID."/*)");
  } else {
    logp("ERR","Bundle tar failed: $ot");
  }
  sh("rm -rf ".esc($tmpdir));
}

# ========= Mosquitto neu starten (falls configs geschrieben oder einfach zur Sicherheit) =========
my ($rsys,$osys) = sh("systemctl restart mosquitto");
if ($rsys == 0) {
  logp("OK","mosquitto restarted");
} else {
  logp("WARN","mosquitto restart failed: $osys");
}

# ========= Abschluss =========
my $ca_days  = cert_days_remaining($CA_CRT);
my $srv_days = cert_days_remaining($SRV_CRT);
my $cli_days = cert_days_remaining($CLI_CRT);

logp("INFO", sprintf("Summary: CA valid %s days; Server %s days; Client %s days",
  (defined $ca_days ? $ca_days : "n/a"),
  (defined $srv_days ? $srv_days : "n/a"),
  (defined $cli_days ? $cli_days : "n/a"),
));
logp("INFO", "Certs dir: $CERTDIR ; Persistent CA: $CA_PERSIST");
logp("INFO", "=== setup-mqtt-interface end ===");
exit 0;
