@component('emails.layouts.master', [
    'preheader' => $preheader ?? 'New website contact enquiry',
    'productName' => $productName ?? null,
])
    <x-email.heading>Website contact enquiry</x-email.heading>

    <x-email.paragraph>
        A visitor submitted a contact enquiry on the EkklesiaNexus website. Reply to this email to respond to the visitor.
    </x-email.paragraph>

    <x-email.key-value :rows="array_values(array_filter([
        ['label' => 'Reference', 'value' => $enquiry->reference_number],
        ['label' => 'Submitted', 'value' => $submittedAtLabel],
        ['label' => 'Name', 'value' => $enquiry->full_name],
        ['label' => 'Email', 'value' => $enquiry->email],
        $enquiry->phone ? ['label' => 'Phone', 'value' => $enquiry->phone] : null,
        $enquiry->organisation ? ['label' => 'Organisation', 'value' => $enquiry->organisation] : null,
        $enquiry->subject ? ['label' => 'Subject', 'value' => $enquiry->subject] : null,
    ]))" />

    <x-email.heading :level="2">Message</x-email.heading>
    <x-email.paragraph>
        {{ $enquiry->message }}
    </x-email.paragraph>
@endcomponent
