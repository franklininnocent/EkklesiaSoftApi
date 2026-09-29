<?php

namespace Modules\Tenants\Tests\Unit;

use Modules\Tenants\Support\AuditPiiRedactor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditPiiRedactorTest extends TestCase
{
    #[Test]
    public function it_redacts_passwords_tokens_and_masks_email_and_phone(): void
    {
        $redactor = new AuditPiiRedactor;

        $redacted = $redactor->redact([
            'password' => 'secret123',
            'access_token' => 'abc',
            'otp' => '123456',
            'reset_token' => 'reset-me',
            'session_id' => 'sess-abc',
            'user_email' => 'priest@example.com',
            'phone' => '+1-555-123-4567',
            'nested' => [
                'refresh_token' => 'xyz',
            ],
            'safe' => 'family_code',
        ]);

        $this->assertSame('[redacted]', $redacted['password']);
        $this->assertSame('[redacted]', $redacted['access_token']);
        $this->assertSame('[redacted]', $redacted['otp']);
        $this->assertSame('[redacted]', $redacted['reset_token']);
        $this->assertSame('[redacted]', $redacted['session_id']);
        $this->assertSame('p***@example.com', $redacted['user_email']);
        $this->assertSame('***4567', $redacted['phone']);
        $this->assertSame('[redacted]', $redacted['nested']['refresh_token']);
        $this->assertSame('family_code', $redacted['safe']);
    }
}
