@component('emails.layouts.master', [
    'preheader' => $preheader ?? 'A password reset was completed for an account you oversee.',
    'productName' => $productName ?? null,
])
    <x-email.heading>Password Reset Completed</x-email.heading>

    <x-email.paragraph>
        A password reset was completed for the following account:
    </x-email.paragraph>

    <x-email.key-value :rows="array_values(array_filter([
        ['label' => 'User', 'value' => $targetUserName.' ('.$targetUserEmail.')'],
        $tenantName ? ['label' => 'Church', 'value' => $tenantName] : null,
        ['label' => 'Time', 'value' => $completedAt],
    ]))" />

    <x-email.alert variant="warning" title="Action may be required">
        If this was unexpected, please investigate immediately.
    </x-email.alert>
@endcomponent
