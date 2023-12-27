<?php declare(strict_types=1);

namespace Amp\Cluster\Internal;

/** @internal */
enum WorkerMessageType
{
    case Pong;
    case Data;
    case Log;
}
