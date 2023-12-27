<?php declare(strict_types=1);

namespace Amp\Cluster\Internal;

/** @internal */
enum WatcherMessageType
{
    case Ping;
    case Data;
}
