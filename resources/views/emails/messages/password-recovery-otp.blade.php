@component('emails.layouts.master', [
    'preheader' => $preheader ?? 'Your password reset verification code.',
    'productName' => $productName ?? null,
])
    <x-email.heading>Password Reset Verification</x-email.heading>

    <x-email.paragraph>
        A password reset was requested for your account.
    </x-email.paragraph>

    <x-email.info-card title="Verification code">
        <div style="font-family:Consolas, Monaco, 'Courier New', monospace;font-size:22px;font-weight:700;letter-spacing:0.12em;word-break:break-all;">
            {{ $otp }}
        </div>
    </x-email.info-card>

    <x-email.paragraph muted>
        This code expires in {{ $expiresInSeconds }} seconds.
    </x-email.paragraph>

    <x-email.security-notice>
        If you did not request this password reset, no action is required. Do not share this code with anyone.
    </x-email.security-notice>
@endcomponent
