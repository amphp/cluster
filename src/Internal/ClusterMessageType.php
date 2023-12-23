<?php declare(strict_types=1);

namespace Amp\Cluster\Internal;

/** @internal */
enum ClusterMessageType
{
    case Ping;
    case Pong;
    case Data;
    case Log;
}
