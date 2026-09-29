<?php

namespace Modules\EcclesiasticalData\Support;

enum AppointmentEndReason: string
{
    case Resignation = 'resignation';
    case Transfer = 'transfer';
    case Retirement = 'retirement';
    case Death = 'death';
    case Removal = 'removal';
    case AppointmentEnded = 'appointment_ended';
    case Correction = 'correction';
    case Other = 'other';
}
