<?php

namespace Amp\Cluster;

use Amp\Promise;
use Psr\Log\LoggerInterface as PsrLogger;
use function Amp\call;

/**
 * Bootstrap a server from command line options.
 *
 * @param PsrLogger $logger
 * @param Console $console
 *
 * @return \Amp\Promise
 */
function boot(PsrLogger $logger, Console $console): Promise {
    return call(function () use ($logger, $console) {
        $configFile = selectConfigFile((string) $console->getArg("config"));

        // Protect current scope by requiring script within another function.
        $initializer = (function () use ($configFile) {
            return require $configFile;
        })();

        $logger->info("Using config file found at $configFile");

        if (!\is_callable($initializer)) {
            throw new \Error("The config file at $configFile must return a callable");
        }

        try {
            $bootable = yield call($initializer, $logger, $console);
        } catch (\Throwable $exception) {
            throw new \Error(
                "Callable invoked from file at $configFile threw an exception",
                0,
                $exception
            );
        }

        if (!$bootable instanceof Bootable) {
            throw new \Error(
                "Callable invoked from file at $configFile did not return an instance of " . Bootable::class
            );
        }

        return $bootable;
    });
}

/**
 * Gives the absolute path of a config file.
 *
 * @param string $configFile path to config file used by Aerys instance
 *
 * @return string
 */
function selectConfigFile(string $configFile): string {
    if ($configFile == "") {
        throw new \Error(
            "No config file found, specify one via the -c switch on command line"
        );
    }

    $path = realpath(is_dir($configFile) ? rtrim($configFile, "/") . "/config.php" : $configFile);

    if ($path === false) {
        throw new \Error("No config file found at " . $configFile);
    }

    return $path;
}

/**
 * Binds a server socket using the given address and context.
 *
 * @param string $address
 * @param array $context
 *
 * @return resource
 */
function socketBinder(string $address, array $context) {
    if (!strncmp($address, "unix://", 7)) {
        @unlink(substr($address, 7));
    }

    if (!$socket = stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create($context))) {
        throw new \RuntimeException(sprintf("Failed binding socket on %s: [Err# %s] %s", $address, $errno, $errstr));
    }

    return $socket;
}
