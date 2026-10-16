@component('emails.layouts.master', [
    'preheader' => $preheader ?? 'Your password recovery request has been approved.',
    'productName' => $productName ?? null,
])
    <x-email.heading>Password Recovery</x-email.heading>

    @if(!empty($recipientName))
        <x-email.paragraph>Hello {{ $recipientName }},</x-email.paragraph>
    @endif

    <x-email.paragraph>
        Your password recovery request has been approved.
    </x-email.paragraph>

    <x-email.info-card title="Temporary password">
        <div style="font-family:Consolas, Monaco, 'Courier New', monospace;font-size:18px;font-weight:700;letter-spacing:0.04em;word-break:break-all;">
            {{ $temporaryPassword }}
        </div>
    </x-email.info-card>

    <x-email.paragraph>
        Please sign in using this temporary password. You will be required to create a new password after signing in.
    </x-email.paragraph>

    <x-email.security-notice>
        For security reasons, do not share this password with anyone. If you did not request this change, contact your church administrator immediately.
    </x-email.security-notice>
@endcomponent
