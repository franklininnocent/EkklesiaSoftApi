@component('emails.layouts.master', [
    'preheader' => $preheader ?? 'New website contact enquiry',
    'productName' => $productName ?? null,
])
    @php
        use App\Support\Email\EmailTheme;
        $t = EmailTheme::inlineBaseStyles();
        $navy = $brandNavy ?? '#003070';
        $gold = $brandGold ?? '#E0A830';
    @endphp
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 20px 0;">
        <tr>
            <td style="height:4px;background-color:{{ $gold }};font-size:0;line-height:0;">&nbsp;</td>
        </tr>
        <tr>
            <td style="padding:12px 0 4px 0;font-family:{{ $t['fontFamily'] }};font-size:{{ $t['secondarySize'] }};line-height:1.4;color:{{ $t['muted'] }};">
                {{ $productTagline ?? 'The Next Generation of Church Management System' }}
            </td>
        </tr>
    </table>

    <x-email.heading>New contact enquiry received</x-email.heading>

    <x-email.paragraph>
        A visitor submitted a contact enquiry on the EkklesiaNexus website. Reply to this email to respond directly to the visitor.
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

    <x-email.heading :level="2">Details</x-email.heading>
    <x-email.paragraph>
        {{ $enquiry->message }}
    </x-email.paragraph>

    @if (!empty($adminEnquiriesUrl))
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 16px 0;">
            <tr>
                <td align="center" bgcolor="{{ $navy }}" style="border-radius:{{ $t['buttonRadius'] }};background-color:{{ $navy }};border:1px solid {{ $navy }};">
                    <a href="{{ $adminEnquiriesUrl }}" target="_blank" rel="noopener noreferrer" style="display:inline-block;min-height:{{ $t['buttonMinHeight'] }};padding:{{ $t['buttonPadY'] }} {{ $t['buttonPadX'] }};font-family:{{ $t['fontFamily'] }};font-size:{{ $t['buttonSize'] }};font-weight:600;line-height:1.25;color:#ffffff;text-decoration:none;border-radius:{{ $t['buttonRadius'] }};background-color:{{ $navy }};">
                        View in EkklesiaNexus
                    </a>
                </td>
            </tr>
        </table>
        <p style="margin:0 0 20px 0;font-family:{{ $t['fontFamily'] }};font-size:{{ $t['labelSize'] }};line-height:1.5;color:{{ $t['muted'] }};word-break:break-all;overflow-wrap:anywhere;">
            Sign in to the application to review this enquiry. If the button does not work, copy and paste this link into your browser:<br>
            <a href="{{ $adminEnquiriesUrl }}" style="color:{{ $navy }};text-decoration:underline;">{{ $adminEnquiriesUrl }}</a>
        </p>
    @endif
@endcomponent
