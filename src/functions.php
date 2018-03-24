<?php

namespace Amp\Cluster;

use Amp\ByteStream\ResourceOutputStream;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Monolog\Handler\HandlerInterface;
use Psr\Log\LogLevel;

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

    // Before you add new systems, please check whether they really support the option for load balancing,
    // e.g. macOS only supports it for failover, only the newest process will get connections there.
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

/**
 * @param HandlerInterface|null $handler Handler used if not running as a cluster. A default stream handler is
 *     created otherwise.
 * @param string                $logLevel Log level for the IPC handler and for the default handler if no handler
 *     is given.
 * @param bool                  $bubble Bubble flag for the IPC handler and for the default handler if no handler
 *     is given.
 *
 * @return HandlerInterface
 */
function createLogHandler(
    HandlerInterface $handler = null,
    string $logLevel = LogLevel::DEBUG,
    bool $bubble = false
): HandlerInterface {
    if (!Cluster::isWorker()) {
        return $handler ?? (function () use ($logLevel, $bubble) {
            $handler = new StreamHandler(new ResourceOutputStream(\STDOUT), $logLevel, $bubble);
            $handler->setFormatter(new ConsoleFormatter);

            return $handler;
        })();
    }

    return new Internal\IpcLogHandler($logLevel, $bubble);
}
