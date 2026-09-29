<?php

namespace Modules\Tenants\Support;

enum SupportSessionMode: string
{
    case Readonly = 'readonly';
    case Standard = 'standard';
    case Emergency = 'emergency';
}
