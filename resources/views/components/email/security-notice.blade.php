@props([
    'title' => 'Security notice',
])
@php
    use App\Support\Email\EmailTheme;
    $t = EmailTheme::inlineBaseStyles();
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 16px 0;border:1px solid {{ $t['border'] }};border-radius:{{ $t['cardRadius'] }};background-color:{{ $t['background'] }};">
    <tr>
        <td style="padding:{{ $t['cardPad'] }};font-family:{{ $t['fontFamily'] }};font-size:{{ $t['secondarySize'] }};line-height:1.5;color:{{ $t['textSecondary'] }};word-break:break-word;overflow-wrap:anywhere;">
            <div style="margin:0 0 6px 0;font-weight:700;color:{{ $t['text'] }};">{{ $title }}</div>
            {{ $slot }}
        </td>
    </tr>
</table>
