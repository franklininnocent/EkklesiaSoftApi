<?php

namespace Modules\EcclesiasticalData\Support;

enum DioceseLeadershipState: string
{
    case Occupied = 'occupied';
    case Vacant = 'vacant';
    case Administered = 'administered';
}
