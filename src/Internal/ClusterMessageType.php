<?php

namespace Amp\Cluster\Internal;

enum ClusterMessageType
{
    case Ping;
    case Pong;
    case Data;
    case Log;
}
