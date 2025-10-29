#!/usr/bin/env php
<?php
require_once "REPLACELBHOMEDIR/libs/phplib/loxberry_system.php";
require_once "REPLACELBHOMEDIR/libs/phplib/loxberry_log.php";

/* Intern auch für die Spiegel-Textdatei nutzen */
$logfile = "REPLACELBHOMEDIR/log/plugins/text2speech/mqtt-watchdog.log";

/* --- Hier zu überwachende Services pflegen --- */
$services = [
    "mqtt-service-tts",
];

/* === Optionen === */
const RESTART_WINDOW_SEC = 60; // Mind. Abstand zwischen Restarts je Service
/* ================= */

/* Startmeldung (LB-Standard) */
#LOGSTART("Watchdog started…");

/* Logfile vorbereiten (Spiegeldatei) */
@touch($logfile);
@chmod($logfile, 0664);

/**
 * Logging-Wrapper:
 *  - Spiegelt in unsere Klartextdatei (wie bisher)
 *  - UND loggt im LoxBerry-Format via LOGINF/LOGWARN/LOGOK/LOGERR/LOGDEB
 *
 * $level akzeptiert Emojis aus dem bisherigen Code (ℹ️, ⚠️, ✅, ❌, ⏳)
 * oder symbolische Level ("INFO", "WARN", "OK", "ERR", "DEB").
 */
function logmsg(string $level, string $service, string $message): void
{
    global $logfile;

    // Normalisieren
    $norm = strtoupper(trim($level));
    switch (true) {
        case in_array($norm, ['ℹ️','INFO']):
            LOGINF("[$service] $message");
            $lb = 'INFO';
            break;

        case in_array($norm, ['⚠️','WARN','WARNING']):
            LOGWARN("[$service] $message");
            $lb = 'WARNING';
            break;

        case in_array($norm, ['✅','OK','SUCCESS']):
            LOGOK("[$service] $message");
            $lb = 'OK';
            break;

        case in_array($norm, ['❌','ERR','ERROR','FAIL']):
            LOGERR("[$service] $message");
            $lb = 'ERROR';
            break;

        case in_array($norm, ['🐞','DEB','DEBUG']):
            LOGDEB("[$service] $message");
            $lb = 'DEB';
            break;

        // Sonderfall „⏳“ → eher Info
        case in_array($norm, ['⏳']):
            LOGINF("[$service] $message");
            $lb = 'DEB';
            break;

        default:
            // Fallback auf INFO
            LOGINF("[$service] $message");
            $lb = 'DEB';
            break;
    }

    // Spiegel in Klartextdatei:
    $ts = date("Y-m-d H:i:s");
    // Einheitliches, gut greppbares Format
    $entry = "$ts <$lb> [$service] $message\n";
    file_put_contents($logfile, $entry, FILE_APPEND | LOCK_EX);
}

function restart_rate_limited(string $service): bool
{
    $state_file = "REPLACELBHOMEDIR/log/plugins/text2speech/." . preg_replace('/[^A-Za-z0-9_.-]/', '_', $service) . ".restart.ts";
    $now  = time();
    $last = @intval(@file_get_contents($state_file));
    if ($last && ($now - $last) < RESTART_WINDOW_SEC) {
        $wait = RESTART_WINDOW_SEC - ($now - $last);
        logmsg("⏳", $service, "Restart skipped (rate limit, wait {$wait}s)");
        return false;
    }
    @file_put_contents($state_file, (string)$now, LOCK_EX);
    return true;
}

function service_exists(string $service): bool
{
    $rc = trim(shell_exec("systemctl show " . escapeshellarg($service) . " >/dev/null 2>&1; echo $?") ?? "1");
    return $rc === "0";
}

function get_prop(string $service, string $prop): string
{
    return trim(shell_exec("systemctl show -p " . escapeshellarg($prop) . " --value " . escapeshellarg($service) . " 2>/dev/null") ?? "");
}

/* --- Service-Check & ggf. Neustart --- */
function check_and_heal_service(string $service): int
{
    if (!service_exists($service)) {
        logmsg("❌", $service, "Service not found (unit missing).");
        return 1;
    }

    // Status holen
    $active   = trim(shell_exec("systemctl is-active " . escapeshellarg($service) . " 2>/dev/null") ?? "");
    $exitcode = get_prop($service, "ExecMainStatus");
    $substate = get_prop($service, "SubState");
    $mainpid  = get_prop($service, "MainPID");

    // Kurzdiagnose
    logmsg("⏳", $service, "state=$active sub=$substate pid=$mainpid exit=$exitcode");

    if ($active !== "active") {
        if (!restart_rate_limited($service)) {
            return 1;
        }
        logmsg("⚠️", $service, "Service is not active (state='$active'). Restarting…");
        shell_exec("systemctl restart " . escapeshellarg($service) . " 2>/dev/null");
        sleep(2);
        $active_after = trim(shell_exec("systemctl is-active " . escapeshellarg($service) . " 2>/dev/null") ?? "");
        if ($active_after === "active") {
            logmsg("✅", $service, "Restart successful.");
            return 0;
        } else {
            logmsg("❌", $service, "Restart failed (state='$active_after').");
            return 1;
        }
    }

    // Service ist active → Exitcode beachten
    if ($exitcode === "255") {
        logmsg("⚠️", $service, "Running but ExecMainStatus=255 (non-fatal). No action taken.");
        return 0;
    } elseif ($exitcode !== "0") {
        if (!restart_rate_limited($service)) {
            return 1;
        }
        logmsg("⚠️", $service, "Active but ExecMainStatus=$exitcode. Restarting…");
        shell_exec("systemctl restart " . escapeshellarg($service) . " 2>/dev/null");
        sleep(2);
        $active_after = trim(shell_exec("systemctl is-active " . escapeshellarg($service) . " 2>/dev/null") ?? "");
        if ($active_after === "active") {
            logmsg("✅", $service, "Restart successful after error.");
            return 0;
        } else {
            logmsg("❌", $service, "Restart after error failed (state='$active_after').");
            return 1;
        }
    } else {
        logmsg("✅", $service, "is running normally. ExecMainStatus=0");
        return 0;
    }
}

/* --- Main: alle Services prüfen --- */
$rc_total = 0;
foreach ($services as $svc) {
    $rc = check_and_heal_service($svc);
    if ($rc !== 0) {
        $rc_total = $rc; // mindestens ein Fehler
    }
}

/* Abschlussmeldung (LB-Standard) */
if ($rc_total === 0) {
    LOGOK("Watchdog finished without errors.");
} else {
    LOGWARN("Watchdog finished with issues (rc=$rc_total).");
}
#LOGEND("Watchdog finished.");

exit($rc_total);
