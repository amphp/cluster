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

    $cores = (int) \trim($execResult);

    return $cores;
}

function hasColorSupport(): bool {
    $os = (\stripos(\PHP_OS, "WIN") === 0) ? "win" : \strtolower(\PHP_OS);

    // @see https://github.com/symfony/symfony/blob/v4.0.6/src/Symfony/Component/Console/Output/StreamOutput.php#L91
    // @license https://github.com/symfony/symfony/blob/v4.0.6/LICENSE
    if ($os === 'win') {
        $windowsVersion = PHP_WINDOWS_VERSION_MAJOR . '.' . PHP_WINDOWS_VERSION_MINOR . '.' . PHP_WINDOWS_VERSION_BUILD;

        return \function_exists('sapi_windows_vt100_support') && @\sapi_windows_vt100_support(\STDOUT)
            || \version_compare($windowsVersion, '10.0.10586', '>=')
            || false !== \getenv('ANSICON')
            || 'ON' === \getenv('ConEmuANSI')
            || 'xterm' === \getenv('TERM');
    }

    if (\function_exists('posix_isatty')) {
        return @\posix_isatty(\STDOUT);
    }

    return false;
}