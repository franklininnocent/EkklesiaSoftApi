<?php

namespace Modules\EcclesiasticalData\Support;

enum AppointmentStatus: string
{
    case Current = 'current';
    case Future = 'future';
    case Ended = 'ended';
    case Cancelled = 'cancelled';
}
