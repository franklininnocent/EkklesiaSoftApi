<?php

namespace Modules\Marketing\Services;

use Illuminate\Support\Str;

/**
 * Resolves the platform Super Admin recipient for website contact enquiry notifications.
 * Values come from config (authentication.super_admin.*) — never from the public form.
 */
class ContactEnquiryNotificationRecipient
{
    public function email(): ?string
    {
        $email = strtolower(trim((string) config('authentication.super_admin.email')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }

    public function name(): string
    {
        $name = trim(str_replace(["\r", "\n"], ' ', (string) config('authentication.super_admin.name')));
        if ($name === '') {
            return 'EkklesiaNexus Super Admin';
        }

        return Str::limit($name, 80, '');
    }

    public function adminEnquiriesUrl(): string
    {
        $base = config('app.frontend_url') ?: config('brand.application_url', 'https://app.ekklesianexus.com');

        return rtrim((string) $base, '/').'/settings/contact-enquiries';
    }
}
