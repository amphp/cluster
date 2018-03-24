<?php

namespace Amp\Cluster;

/**
 * Determine the total number of CPU cores on the system.
 *
 * @return int
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

    $cores = (int) \trim($execResult);

    return $cores;
}

/**
 * Determine if SO_REUSEPORT is supported on the system.
 *
 * @return bool
 */
function canReusePort(): bool {
    static $canReusePort;

    if ($canReusePort !== null) {
        return $canReusePort;
    }

    $os = (\stripos(\PHP_OS, "WIN") === 0) ? "win" : \strtolower(\PHP_OS);

    switch ($os) {
        case "win":
            return $canReusePort = true;

        case "linux":
            // We determine support based on Kernel version.
            // We don't care about backports, as socket transfer works fine, too.
            $version = \trim(`uname -r`);
            $version = \implode(".", \array_slice(\explode(".", $version), 0, 2));
            return $canReusePort = \version_compare($version, '3.9', '>=');

        default:
            return $canReusePort = false;
    }
}
