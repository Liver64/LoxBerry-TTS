<?php
require_once "/opt/loxberry/libs/phplib/loxberry_system.php";

$service = "mqtt-service-tts";
$logfile = LBPLOGDIR."/mqtt-watchdog.log";
$timestamp = date("d-m-Y H:i:s");

// Logging-Funktion
function logmsg($level, $message) {
    global $logfile, $timestamp;
    $entry = "$timestamp $message\n";
    file_put_contents($logfile, $entry, FILE_APPEND);
}

// Dienststatus prüfen
$active = trim(shell_exec("systemctl is-active $service"));
$exitcode = trim(shell_exec("systemctl show -p ExecMainStatus --value $service"));

if ($active !== "active") {
    logmsg("ERROR", "❌ Service $service is not active. Restarting...");
    $restart = shell_exec("systemctl restart $service");
    sleep(2);
    $active_after = trim(shell_exec("systemctl is-active $service"));
    if ($active_after === "active") {
        logmsg("INFO", "✅ Service $service restarted successfully.");
    } else {
        logmsg("ERROR", "❌ ERROR – Failed to restart $service.");
        exit(1);
    }
} elseif ($exitcode === "255") {
    logmsg("WARN", "⚠️ Service $service is running with ExitCode=255 (non-fatal). No action taken.");
} elseif ($exitcode !== "0") {
    logmsg("WARN", "⚠️ Service $service is active but returned error code $exitcode. Restarting...");
    $restart = shell_exec("systemctl restart $service");
    sleep(2);
    $active_after = trim(shell_exec("systemctl is-active $service"));
    if ($active_after === "active") {
        logmsg("INFO", "✅ Service $service restarted successfully after error.");
    } else {
        logmsg("ERROR", "❌ ERROR – Failed to restart $service after error.");
        exit(1);
    }
} elseif ($active == "active")  {
	logmsg("INFO", "✅ Service $service is running normally. ExitCode=$exitcode");
}

?>