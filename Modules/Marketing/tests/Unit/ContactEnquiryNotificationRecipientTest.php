<?php

namespace Modules\Marketing\Tests\Unit;

use Modules\Marketing\Services\ContactEnquiryNotificationRecipient;
use Tests\TestCase;

class ContactEnquiryNotificationRecipientTest extends TestCase
{
    public function test_resolves_email_and_name_from_authentication_config(): void
    {
        config([
            'authentication.super_admin.email' => 'Admin@Example.COM',
            'authentication.super_admin.name' => 'Platform Super Admin',
            'app.frontend_url' => 'https://app.ekklesianexus.com',
        ]);

        $resolver = new ContactEnquiryNotificationRecipient();

        $this->assertSame('admin@example.com', $resolver->email());
        $this->assertSame('Platform Super Admin', $resolver->name());
        $this->assertSame(
            'https://app.ekklesianexus.com/settings/contact-enquiries',
            $resolver->adminEnquiriesUrl(),
        );
    }

    public function test_invalid_email_returns_null(): void
    {
        config(['authentication.super_admin.email' => 'not-valid']);

        $this->assertNull((new ContactEnquiryNotificationRecipient())->email());
    }
}
