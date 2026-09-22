@component('emails.layouts.master', [
    'preheader' => $preheader ?? 'A password recovery request requires your review.',
    'productName' => $productName ?? null,
])
    <x-email.heading>Password Recovery Request</x-email.heading>

    <x-email.paragraph>
        A password recovery request requires your review.
    </x-email.paragraph>

    <x-email.key-value :rows="array_values(array_filter([
        ['label' => 'Request ID', 'value' => $requestId],
        ['label' => 'Name', 'value' => $requesterName],
        ['label' => 'Email', 'value' => $requesterEmail],
        ['label' => 'Role', 'value' => $requesterRole],
        $tenantName ? ['label' => 'Church', 'value' => $tenantName] : null,
        ['label' => 'Requested', 'value' => $requestedAt],
    ]))" />

    <x-email.paragraph muted>
        Sign in to EkklesiaSoft and open Settings → Forgot Password Requests to review this request.
    </x-email.paragraph>

    <x-email.security-notice>
        Only approve this request if you recognize the user and expect this recovery. Approving issues a temporary password to the requesting user.
    </x-email.security-notice>
@endcomponent
