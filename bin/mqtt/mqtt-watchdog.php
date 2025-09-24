#!/usr/bin/env php
<?php
require_once "REPLACELBHOMEDIR/libs/phplib/loxberry_system.php";

$logfile  = "REPLACELBPLOGDIR/mqtt-watchdog.log";
$services = [
    "mqtt-service-tts",
    "mqtt-config-watcher",
];

// === Optionen ===
const RESTART_WINDOW_SEC = 60; // Mind. Abstand zwischen Restarts je Service
// =================

// Logfile vorbereiten
@touch($logfile);
@chmod($logfile, 0664);

// --- Logging ---
function logmsg(string $level, string $service, string $message): void {
    global $logfile;
    $ts = date("Y-m-d H:i:s");
    $entry = "$ts $level [$service] $message\n";
    file_put_contents($logfile, $entry, FILE_APPEND | LOCK_EX);
}

function restart_rate_limited(string $service): bool {
    // Einfaches Rate-Limit per Timestamp-Datei je Service
    $state_file = "REPLACELBPLOGDIR/." . preg_replace('/[^A-Za-z0-9_.-]/', '_', $service) . ".restart.ts";
    $now = time();
    $last = @intval(@file_get_contents($state_file));
    if ($last && ($now - $last) < RESTART_WINDOW_SEC) {
        logmsg("⏳", $service, " Restart skipped (rate limit, wait " . (RESTART_WINDOW_SEC - ($now - $last)) . "s)");
        return false;
    }
    @file_put_contents($state_file, (string)$now, LOCK_EX);
    return true;
}

function service_exists(string $service): bool {
    // Prüft, ob systemd die Unit kennt
    $rc = trim(shell_exec("systemctl show " . escapeshellarg($service) . " >/dev/null 2>&1; echo $?") ?? "1");
    return $rc === "0";
}

function get_prop(string $service, string $prop): string {
    return trim(shell_exec("systemctl show -p " . escapeshellarg($prop) . " --value " . escapeshellarg($service) . " 2>/dev/null") ?? "");
}

// --- Service-Check & ggf. Neustart ---
function check_and_heal_service(string $service): int {
    if (!service_exists($service)) {
        logmsg("❌", $service, " Service not found (unit missing).");
        return 1;
    }

    // Status holen
    $active    = trim(shell_exec("systemctl is-active " . escapeshellarg($service) . " 2>/dev/null") ?? "");
    $exitcode  = get_prop($service, "ExecMainStatus");
    $substate  = get_prop($service, "SubState");
    $mainpid   = get_prop($service, "MainPID");

    // Kurzdiagnose
    logmsg("ℹ️", $service, " state=$active sub=$substate pid=$mainpid exit=$exitcode");

    if ($active !== "active") {
        if (!restart_rate_limited($service)) {
            return 1;
        }
        logmsg("❌", $service, " Service is not active (state='$active'). Restarting…");
        shell_exec("systemctl restart " . escapeshellarg($service) . " 2>/dev/null");
        sleep(2);
        $active_after = trim(shell_exec("systemctl is-active " . escapeshellarg($service) . " 2>/dev/null") ?? "");
        if ($active_after === "active") {
            logmsg("✅", $service, " Restart successful.");
            return 0;
        } else {
            logmsg("❌", $service, " Restart failed (state='$active_after').");
            return 1;
        }
    }

    // Service ist active → Exitcode beachten
    if ($exitcode === "255") {
        logmsg("⚠️", $service, " Running but ExecMainStatus=255 (non-fatal). No action taken.");
        return 0;
    } elseif ($exitcode !== "0") {
        if (!restart_rate_limited($service)) {
            return 1;
        }
        logmsg("⚠️", $service, " Active but ExecMainStatus=$exitcode. Restarting…");
        shell_exec("systemctl restart " . escapeshellarg($service) . " 2>/dev/null");
        sleep(2);
        $active_after = trim(shell_exec("systemctl is-active " . escapeshellarg($service) . " 2>/dev/null") ?? "");
        if ($active_after === "active") {
            logmsg("✅", $service, " Restart successful after error.");
            return 0;
        } else {
            logmsg("❌", $service, " Restart after error failed (state='$active_after').");
            return 1;
        }
    } else {
        logmsg("✅", $service, " is running normally. ExecMainStatus=0");
        return 0;
    }
}

// --- Main: alle Services prüfen ---
$rc_total = 0;
foreach ($services as $svc) {
    $rc = check_and_heal_service($svc);
    if ($rc !== 0) {
        $rc_total = $rc; // mindestens ein Fehler
    }
}
exit($rc_total);
