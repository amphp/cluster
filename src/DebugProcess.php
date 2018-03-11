<?php

namespace Amp\Cluster;

use Psr\Log\LoggerInterface as PsrLogger;
use function Amp\coroutine;

class DebugProcess extends Process {
    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    /** @var \Amp\Cluster\Bootable|null */
    private $bootable;

    public function __construct(PsrLogger $logger) {
        parent::__construct($logger);
        $this->logger = $logger;
    }

    protected function doStart(Console $console): \Generator {
        if ($console->isArgDefined("restart")) {
            $this->logger->critical("You cannot restart a debug cluster instance via command");
            exit(1);
        }

        if (ini_get("zend.assertions") === "-1") {
            $this->logger->warning(
                "Running a cluster in debug mode with assertions disabled is not recommended; " .
                "enable assertions in php.ini (zend.assertions = 1) " .
                "or disable debug mode (-d) to hide this warning."
            );
        } else {
            ini_set("zend.assertions", "1");
        }

        /** @var \Amp\Cluster\Bootable $bootable */
        $this->bootable = yield boot($this->logger, $console);

        yield $this->bootable->start(coroutine(function (array $addrContextMap) {
            $serverSockets = [];
            foreach ($addrContextMap as $address => $context) {
                $serverSockets[$address] = socketBinder($address, $context);
            }
            return $serverSockets;
        }));
    }

    protected function doStop(): \Generator {
        if ($this->bootable) {
            yield $this->bootable->stop();
            $this->bootable = null;
        }
    }
}
