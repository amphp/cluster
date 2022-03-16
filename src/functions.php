<?php

namespace Amp\Cluster;

/**
 * Determine the total number of CPU cores on the system.
 *
 * @return int
 */
function countCpuCores(): int
{
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

    return (int) \trim($execResult);
}

/**
 * Determine if SO_REUSEPORT is supported on the system.
 *
 * @return bool
 */
function canReusePort(): bool
{
    static $canReusePort;

    if ($canReusePort !== null) {
        return $canReusePort;
    }

    $os = (\stripos(\PHP_OS, "WIN") === 0) ? "win" : \strtolower(\PHP_OS);

    // Before you add new systems, please check whether they really support the option for load balancing,
    // e.g. macOS only supports it for failover, only the newest process will get connections there.
    switch ($os) {
        case "win":
            $canReusePort = true;
            break;

        case "linux":
            // We determine support based on Kernel version.
            // We don't care about backports, as socket transfer works fine, too.
            $version = \trim(\shell_exec('uname -r'));
            $version = \implode(".", \array_slice(\explode(".", $version), 0, 2));
            $canReusePort = \version_compare($version, '3.9', '>=');
            break;

        default:
            $canReusePort = false;
            break;
    }

    return $canReusePort;
}
