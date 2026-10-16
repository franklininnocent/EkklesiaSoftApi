@component('emails.layouts.master', [
    'preheader' => $preheader ?? 'Multiple password recovery attempts were detected.',
    'productName' => $productName ?? null,
])
    <x-email.heading>Security Alert — Password Recovery Limit Reached</x-email.heading>

    <x-email.paragraph>
        Multiple password recovery attempts were detected for:
    </x-email.paragraph>

    <x-email.key-value :rows="array_values(array_filter([
        ['label' => 'User', 'value' => $targetUserName.' ('.$targetUserEmail.')'],
        $tenantName ? ['label' => 'Church', 'value' => $tenantName] : null,
        ['label' => 'Recovery attempts today', 'value' => (string) $attemptCount],
        ['label' => 'Detected at', 'value' => $detectedAt],
    ]))" />

    <x-email.alert variant="danger" title="Security alert">
        If this activity was not expected, please investigate.
    </x-email.alert>
@endcomponent
