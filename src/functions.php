<?php declare(strict_types=1);

namespace Amp\Cluster;

/**
 * Determine the total number of CPU cores on the system.
 *
 */
function countCpuCores(): int
{
    static $cores;

    if ($cores !== null) {
        return $cores;
    }

    $os = (\stripos(\PHP_OS, "WIN") === 0) ? "win" : \strtolower(\PHP_OS);

    $cmd = match ($os) {
        "win" => "wmic cpu get NumberOfCores",
        "linux", "darwin" => "getconf _NPROCESSORS_ONLN",
        "netbsd", "openbsd", "freebsd" => "sysctl hw.ncpu | cut -d ':' -f2",
        default => null,
    };

    /** @psalm-suppress ForbiddenCode */
    $execResult = $cmd ? (string) \shell_exec($cmd) : '1';

    if ($os === 'win') {
        $execResult = \explode("\n", $execResult)[1];
    }

    $cores = (int) \trim($execResult);
    if ($cores <= 0) {
        $cores = 1;
    }

    return $cores;
}

/**
 * Determine if SO_REUSEPORT is supported on the system.
 *
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
            /** @psalm-suppress ForbiddenCode */
            $version = (string) \shell_exec('uname -r');
            $version = \trim($version);
            $version = \implode(".", \array_slice(\explode(".", $version), 0, 2));
            $canReusePort = \version_compare($version, '3.9', '>=');
            break;

        default:
            $canReusePort = false;
            break;
    }

    return $canReusePort;
}
