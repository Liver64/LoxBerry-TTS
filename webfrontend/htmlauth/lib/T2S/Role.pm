package T2S::Role;

use strict;
use warnings;

# Role/Marker-Dateien direkt im vorhandenen /etc/mosquitto (keine Unterordner!)
my $BASE_DIR       = '/etc/mosquitto/tts-role';
my $ROLE_FILE      = "$BASE_DIR/t2s.role";           # Inhalt: 'master' | 'client'
my $OWNER_FILE     = "$BASE_DIR/t2s.role.owner";     # Inhalt: 'text2speech' | 'text2sip'
my $PRESENT_T2S    = "$BASE_DIR/t2s.present.text2speech";
my $PRESENT_SIP    = "$BASE_DIR/t2s.present.text2sip";

sub _exists_base { -d $BASE_DIR }

# Falls BASE_DIR nicht existiert: anlegen (root via sudo), Rechte setzen
sub _ensure_base {
    return 1 if _exists_base();
    system('sudo','/bin/mkdir','-p',$BASE_DIR) == 0 or die "mkdir failed for $BASE_DIR";
    system('sudo','/usr/bin/chown','root:root',$BASE_DIR) == 0 or die "chown failed for $BASE_DIR";
    system('sudo','/usr/bin/chmod','0755',$BASE_DIR) == 0 or die "chmod 0755 failed for $BASE_DIR";
    return 1;
}

# Schreibt Textinhalt per erlaubten sudo-Kommandos:
# touch -> chmod 0666 -> als loxberry schreiben -> chmod 0644 -> chown root:root
sub _write_text_rootfile {
    my ($path, $content) = @_;
    _ensure_base();
    system('sudo','/usr/bin/touch',$path) == 0 or die "touch failed for $path";
    system('sudo','/usr/bin/chmod','0666',$path) == 0 or die "chmod 0666 failed for $path";

    open my $fh, '>', $path or die "open $path for write failed: $!";
    print $fh $content;
    close $fh;

    system('sudo','/usr/bin/chmod','0644',$path) == 0 or die "chmod 0644 failed for $path";
    system('sudo','/usr/bin/chown','root:root',$path) == 0 or die "chown root:root failed for $path";
    return 1;
}

sub _safe_touch_root {
    my ($file) = @_;
    _ensure_base();
    system('sudo','/usr/bin/touch',$file);
    system('sudo','/usr/bin/chmod','0644',$file);
    system('sudo','/usr/bin/chown','root:root',$file);
    return 1;
}

sub _safe_rm {
    my (@files) = @_;
    return 1 unless @files;
    system('sudo','/usr/bin/rm','-f',@files);
    return 1;
}

# --- Public API ---
sub role_file      { $ROLE_FILE }
sub owner_file     { $OWNER_FILE }
sub present_t2s    { $PRESENT_T2S }
sub present_sip    { $PRESENT_SIP }

sub read_role {
    return unless -e $ROLE_FILE;
    open my $fh, '<', $ROLE_FILE or return;
    my $r = <$fh>;
    close $fh;
    $r //= '';
    $r =~ s/\s+//g;
    return $r if $r eq 'master' || $r eq 'client';
    return;
}

sub read_owner {
    return unless -e $OWNER_FILE;
    open my $fh, '<', $OWNER_FILE or return;
    my $o = <$fh>;
    close $fh;
    $o //= '';
    $o =~ s/\s+//g;
    return $o if $o eq 'text2speech' || $o eq 'text2sip';
    return;
}

# Rolle nur setzen, wenn noch keine existiert
sub set_role_if_empty {
    my ($role, $owner) = @_;
    my $current = read_role();
    return 1 if $current;  # respektieren
    die "invalid role"  unless defined $role  && $role  =~ /^(master|client)$/;
    die "invalid owner" unless defined $owner && $owner =~ /^(text2speech|text2sip)$/;
    _write_text_rootfile($ROLE_FILE,  "$role\n");
    _write_text_rootfile($OWNER_FILE, "$owner\n");
    return 1;
}

# Rolle erzwingen (Admin-/Repair-Fall)
sub force_set_role {
    my ($role, $owner) = @_;
    die "invalid role"  unless defined $role  && $role  =~ /^(master|client)$/;
    die "invalid owner" unless defined $owner && $owner =~ /^(text2speech|text2sip)$/;
    _write_text_rootfile($ROLE_FILE,  "$role\n");
    _write_text_rootfile($OWNER_FILE, "$owner\n");
    return 1;
}

# Präsenzmarker anlegen/entfernen
sub touch_present {
    my ($which) = @_;
    if    ($which eq 'text2speech') { _safe_touch_root($PRESENT_T2S) }
    elsif ($which eq 'text2sip')    { _safe_touch_root($PRESENT_SIP) }
    else { die "invalid present marker: $which" }
    return 1;
}

sub remove_present {
    my ($which) = @_;
    if    ($which eq 'text2speech') { _safe_rm($PRESENT_T2S) }
    elsif ($which eq 'text2sip')    { _safe_rm($PRESENT_SIP) }
    else { die "invalid present marker: $which" }
    return 1;
}

# Löscht role/owner nur, wenn beide Plugins weg sind
sub cleanup_role_if_last {
    my $t2s = -e $PRESENT_T2S;
    my $sip = -e $PRESENT_SIP;
    return 0 if $t2s || $sip; # noch jemand da → Rolle bleibt
    _safe_rm($ROLE_FILE, $OWNER_FILE);
    return 1;
}

1; # end package
