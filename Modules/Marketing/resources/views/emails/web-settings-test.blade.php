@component('emails.layouts.master', [
    'preheader' => $preheader ?? 'Mail transport test',
    'productName' => $productName ?? null,
])
    <x-email.heading>Mail transport test</x-email.heading>
    <x-email.paragraph>
        This message confirms that EkklesiaNexus can send email using the configured mail transport. It does not prove inbox delivery with the recipient’s provider.
    </x-email.paragraph>
@endcomponent
