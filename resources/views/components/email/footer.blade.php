@props([
    'productName' => null,
])
@php
    use App\Support\Email\EmailTheme;
    $t = EmailTheme::inlineBaseStyles();
    $productName = $productName ?? EmailTheme::productName();
    $support = EmailTheme::supportAddress();
    $year = date('Y');
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
    <tr>
        <td style="padding:{{ $t['sectionGap'] }} {{ $t['pagePad'] }};border-top:1px solid {{ $t['divider'] }};background-color:{{ $t['background'] }};font-family:{{ $t['fontFamily'] }};font-size:{{ $t['footerSize'] }};line-height:1.5;color:{{ $t['muted'] }};text-align:center;">
            <div style="font-weight:600;color:{{ $t['textSecondary'] }};margin-bottom:4px;">{{ $productName }}</div>
            <div>&copy; {{ $year }} {{ $productName }}</div>
            @if($support)
                <div style="margin-top:8px;word-break:break-word;overflow-wrap:anywhere;">
                    Support: <a href="mailto:{{ $support }}" style="color:{{ $t['link'] }};text-decoration:underline;">{{ $support }}</a>
                </div>
            @endif
        </td>
    </tr>
</table>
