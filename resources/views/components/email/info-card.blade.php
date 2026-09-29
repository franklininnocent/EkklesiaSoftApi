@props([
    'title' => null,
])
@php
    use App\Support\Email\EmailTheme;
    $t = EmailTheme::inlineBaseStyles();
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 16px 0;border:1px solid {{ $t['border'] }};border-radius:{{ $t['cardRadius'] }};background-color:{{ $t['surface'] }};">
    <tr>
        <td style="padding:{{ $t['cardPad'] }};border-left:4px solid {{ $t['primary'] }};font-family:{{ $t['fontFamily'] }};font-size:{{ $t['bodySize'] }};line-height:{{ $t['bodyLineHeight'] }};color:{{ $t['textSecondary'] }};word-break:break-word;overflow-wrap:anywhere;">
            @if($title)
                <div style="margin:0 0 8px 0;font-size:{{ $t['secondarySize'] }};font-weight:700;color:{{ $t['text'] }};">{{ $title }}</div>
            @endif
            {{ $slot }}
        </td>
    </tr>
</table>
