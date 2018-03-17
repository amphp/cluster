<?php

namespace Amp\Cluster;

/**
 * Determine the total number of CPU cores on the system.
 */
function countCpuCores(): int {
    static $cores;

    if ($cores !== null) {
        return $cores;
    }

    $os = (\stripos(\PHP_OS, "WIN") === 0) ? "win" : \strtolower(\PHP_OS);

    switch ($os) {
        case "win":
            $cmd = "wmic cpu get NumberOfCores";
            break;
        case "linux":
        case "darwin":
            $cmd = "getconf _NPROCESSORS_ONLN";
            break;
        case "netbsd":
        case "openbsd":
        case "freebsd":
            $cmd = "sysctl hw.ncpu | cut -d ':' -f2";
            break;
        default:
            $cmd = null;
            break;
    }

    $execResult = $cmd ? \shell_exec($cmd) : 1;

    if ($os === 'win') {
        $execResult = \explode("\n", $execResult)[1];
    }

    return $cores = (int) \trim($execResult);
}
