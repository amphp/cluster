<?php

namespace Amp\Cluster;

use League\CLImate\CLImate;

/**
 * This class solely exists to wrap CLImate to make our code more testable, otherwise we have a mess of LoD violations
 * due to CLImate's exposure public property behavior objects.
 */
class Console {
    private $climate;
    private $hasParsedArgs;

    public function __construct(CLImate $climate = null) {
        $this->climate = $climate ?? new CLImate;
    }

    public function output(string $message) {
        return $this->climate->out($message);
    }

    public function forceAnsiOn() {
        $this->climate->forceAnsiOn();
    }

    public function hasArgument(string $arg): bool {
        if (empty($this->hasParsedArgs)) {
            $this->parseArguments();
        }

        return $this->climate->arguments->defined($arg);
    }

    public function getArgument(string $arg) {
        if (empty($this->hasParsedArgs)) {
            $this->parseArguments();
        }

        return $this->climate->arguments->get($arg);
    }

    private function parseArguments() {
        if (empty($this->hasParsedArgs)) {
            @$this->climate->arguments->parse();
            $this->hasParsedArgs = true;
        }
    }
}
