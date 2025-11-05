#!/usr/bin/env php
<?php

require_once "loxberry_system.php";
require_once "loxberry_log.php";

register_shutdown_function('shutdown');

$log = LBLog::newLog( [ "name" => "Cleanup", "stderr" => 1, "addtime" => 1 ] );

LOGSTART("Cleanup MP3 files and logs");

// ======= PARAMETER (ohne Config) =======
$LOGDIR          	= "/run/shm/text2speech";
const LOG_KEEP_DAYS = 1;                 // Log Dateien älter als 2 Tage löschen (0 = aus)
const LOG_MAX_BYTES = 150 * 1024;        // Max. Gesamtgröße 250 KB (0 = aus)
// =======================================

$myConfigFolder = "$lbpconfigdir";              // get config folder
# $myConfigFile   = "tts_all.cfg";                // get config file
$hostname       = lbhostname();

// Laden der Konfigurationsdatei t2s_config.json
if (file_exists($myConfigFolder . "/t2s_config.json")) {
    $config = json_decode(file_get_contents($myConfigFolder . "/t2s_config.json"), TRUE);
    LOGOK("T2S config has been loaded");
} else {
    LOGCRIT('The file t2s_config.json could not be opened, please try again!');
    exit;
}

$folderpeace = explode("/", $config['SYSTEM']['path']);
if ($folderpeace[3] != "data") {
    // wenn NICHT local dir als Speichermedium selektiert wurde
    $MessageStorepath = rtrim($config['SYSTEM']['path'], '/') . "/tts/";
} else {
    // wenn local dir als Speichermedium selektiert wurde
    $MessageStorepath = rtrim($config['SYSTEM']['ttspath'], '/') . "/";
}

// Set defaults if needed
$storageinterval = trim($config['MP3']['MP3store']);
$cachesize       = !empty($config['MP3']['cachesize']) ? trim($config['MP3']['cachesize']) : "100";
$tosize          = $cachesize * 1024 * 1024;
if (empty($tosize)) {
    LOGCRIT("The size limit is not valid - stopping operation");
    LOGDEB("Config parameter MP3/cachesize is {$config['MP3']['cachesize']}, tosize is '$tosize'");
    exit;
}

delmp3();
clean_logs($LOGDIR, LOG_KEEP_DAYS, LOG_MAX_BYTES);

exit;

/**
 * delmp3 --> löscht die hash5 codierten MP3 Dateien aus dem Verzeichnis 'messageStorePath'
 */
function delmp3() {
    global $MessageStorepath, $storageinterval, $tosize, $cachesize;

    LOGINF("Deleting oldest files to reach $cachesize MB...");

    $dir = $MessageStorepath;
    LOGDEB("Directory: $dir");
    if (!is_dir($dir)) {
        LOGWARN("MP3 directory does not exist: $dir");
        return;
    }

    $files = glob("$dir/*") ?: [];

    // Älteste zuerst
    usort($files, function($a, $b) {
        return @filemtime($a) <=> @filemtime($b);
    });

    /******************/
    /* Delete to size */
    $fullsize = 0;
    foreach ($files as $file) {
        if (!is_file($file)) continue;
        $fullsize += (int)@filesize($file);
    }

    if ($fullsize < $tosize) {
        LOGINF("Current size $fullsize is below destination size $tosize");
        LOGOK("Nothing to do, quitting");
    } else {
        $newsize = $fullsize;
        foreach ($files as $file) {
            if (!is_file($file)) continue;
            $filesize = (int)@filesize($file);
            if (@unlink($file) !== false) {
                LOGDEB(basename($file) . ' has been deleted');
                $newsize -= $filesize;
            } else {
                LOGWARN(basename($file) . ' could not be deleted');
            }
            if ($newsize < $tosize) {
                LOGOK("New size $newsize reached destination size $tosize");
                break;
            }
        }
        if ($newsize > $tosize) {
            LOGERR("Used size $newsize is still greater than destination size $tosize - Something is strange.");
        }
    }

    LOGINF("Now check if files older x days should be deleted, too...");

    if ($storageinterval != "0") {
        LOGINF("Deleting files older than $storageinterval days...");

        $deltime = time() - $storageinterval * 24 * 60 * 60;
        foreach ($files as $file) {
            if (!is_file($file)) continue;
            $filetime = @filemtime($file);
            LOGDEB("Checking file " . basename($file));
            if ($filetime !== false && $filetime < $deltime) {
                if (@unlink($file) !== false)
                    LOGINF(basename($file) . ' has been deleted');
                else
                    LOGWARN(basename($file) . ' could not be deleted');
            }
        }
    } else {
        LOGINF("Files should be stored forever. Nothing to do here.");
    }

    LOGOK("T2S file reduction has completed");
}

/**
 * clean_logs --> räumt das Log-Verzeichnis auf.
 * 1) Größe begrenzen (älteste zuerst löschen, bis unter max_bytes)
 * 2) Zusätzlich alles löschen, was älter als keep_days ist
 *
 * @param string $logdir
 * @param int    $keep_days   0 = Alterslimit aus
 * @param int    $max_bytes   0 = Größenlimit aus
 */
function clean_logs(string $logdir, int $keep_days, int $max_bytes): void
{
    LOGINF("Cleaning logs in $logdir (keep_days=$keep_days, max_size=" . ($max_bytes ? format_bytes($max_bytes) : 'unlimited') . ")");

    if (!is_dir($logdir)) {
        LOGWARN("Log directory does not exist: $logdir");
        return;
    }

    // übliche Log-Muster
    $patterns = [
        "$logdir/*.log",
        "$logdir/*.txt",
        "$logdir/*.json.log",
        "$logdir/*.old",
        "$logdir/*.gz",
    ];

    $files = [];
    foreach ($patterns as $pat) {
        $g = glob($pat, GLOB_NOSORT);
        if ($g !== false) {
            foreach ($g as $f) {
                if (is_file($f)) $files[] = $f;
            }
        }
    }

    if (empty($files)) {
        LOGOK("No log files to clean");
        return;
    }

    // Älteste zuerst
    usort($files, function($a, $b) {
        return @filemtime($a) <=> @filemtime($b);
    });

    // 1) Größenbegrenzung
    if ($max_bytes > 0) {
        $sum = 0;
        foreach ($files as $f) { $sum += (int)@filesize($f); }

        if ($sum > $max_bytes) {
            LOGINF("Total log size " . format_bytes($sum) . " exceeds limit " . format_bytes($max_bytes) . ", deleting oldest…");
            foreach ($files as $idx => $f) {
                if ($sum <= $max_bytes) break;
                $sz = (int)@filesize($f);
                if (@unlink($f) !== false) {
                    LOGINF("Deleted (size-limit): " . basename($f) . " (" . format_bytes($sz) . ")");
                    $sum -= $sz;
                    unset($files[$idx]);
                } else {
                    LOGWARN("Could not delete (size-limit): " . basename($f));
                }
            }
        } else {
            LOGDEB("Total log size " . format_bytes($sum) . " is within limit " . format_bytes($max_bytes));
        }
    }

    // 2) Altersbegrenzung
    if ($keep_days > 0) {
        $threshold = time() - ($keep_days * 86400);
        foreach ($files as $f) {
            $mtime = @filemtime($f);
            if ($mtime !== false && $mtime < $threshold) {
                if (@unlink($f) !== false) {
                    LOGINF("Deleted (too old): " . basename($f) . " (" . date(DATE_ATOM, (int)$mtime) . ")");
                } else {
                    LOGWARN("Could not delete (too old): " . basename($f));
                }
            }
        }
    } else {
        LOGDEB("Age-based deletion disabled (keep_days=0).");
    }

    LOGOK("Log cleanup finished");
}

function format_bytes(int $bytes): string {
    if ($bytes < 1024) return $bytes . " B";
    $kb = $bytes / 1024;
    if ($kb < 1024) return number_format($kb, 1) . " KB";
    $mb = $kb / 1024;
    if ($mb < 1024) return number_format($mb, 1) . " MB";
    $gb = $mb / 1024;
    return number_format($gb, 2) . " GB";
}

function shutdown()
{
    global $log;
    $log->LOGEND("Cleanup finished");
}
