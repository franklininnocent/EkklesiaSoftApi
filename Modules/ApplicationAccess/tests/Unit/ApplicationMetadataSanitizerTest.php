<?php

namespace Modules\ApplicationAccess\Tests\Unit;

use Modules\ApplicationAccess\Support\ApplicationMetadataSanitizer;
use Modules\Tenants\Support\AuditPiiRedactor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicationMetadataSanitizerTest extends TestCase
{
    #[Test]
    public function it_allowlists_keys_and_redacts_sensitive_values(): void
    {
        $sanitizer = new ApplicationMetadataSanitizer(new AuditPiiRedactor);

        $result = $sanitizer->sanitize([
            'edge_ray_id' => 'abc',
            'password' => 'secret',
            'access_token' => 'tok',
            'arbitrary' => 'drop-me',
        ]);

        $this->assertSame('abc', $result['edge_ray_id']);
        $this->assertArrayNotHasKey('password', $result);
        $this->assertArrayNotHasKey('access_token', $result);
        $this->assertArrayNotHasKey('arbitrary', $result);
    }
}
