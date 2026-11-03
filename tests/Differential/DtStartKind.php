<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Differential;

enum DtStartKind
{

    case Date;
    case Floating;
    case Utc;
    case Zoned;

}
