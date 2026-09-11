<?php

namespace Modules\ApplicationAccess\Exceptions;

use RuntimeException;

class ApplicationAccessStreamCapacityExceededException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Application Access stream capacity exceeded.');
    }
}
