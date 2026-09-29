<?php

namespace Modules\ApplicationAccess\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicationAccessModuleTest extends TestCase
{
    #[Test]
    public function module_is_enabled_and_config_merged(): void
    {
        $this->assertTrue(
            (bool) config('modules.ApplicationAccess.enabled', false)
                || in_array('ApplicationAccess', array_keys((array) json_decode(
                    (string) file_get_contents(base_path('modules_statuses.json')),
                    true
                )), true)
        );

        $this->assertSame('ApplicationAccess', config('applicationaccess.name'));
        $this->assertFalse((bool) config('applicationaccess.telemetry_enabled'));
    }

    #[Test]
    public function telemetry_flag_defaults_to_false_in_phpunit(): void
    {
        $this->assertFalse((bool) config('applicationaccess.telemetry_enabled'));
    }
}
