@props([
    'productName' => null,
    'tenantName' => null,
])
@php
    use App\Support\Email\EmailTheme;
    $t = EmailTheme::inlineBaseStyles();
    $productName = $productName ?? EmailTheme::productName();
    $logoUrl = EmailTheme::logoUrl();
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
    <tr>
        <td style="background-color:{{ $t['headerBar'] }};padding:20px {{ $t['pagePad'] }};text-align:left;">
            @if($logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ $productName }}" width="160" style="display:block;max-width:160px;height:auto;border:0;font-family:{{ $t['fontFamily'] }};font-size:18px;font-weight:700;color:{{ $t['primaryText'] }};">
            @else
                <span style="font-family:{{ $t['fontFamily'] }};font-size:20px;font-weight:700;letter-spacing:0.02em;color:{{ $t['primaryText'] }};">
                    {{ $productName }}
                </span>
            @endif
            @if($tenantName)
                <div style="margin-top:6px;font-family:{{ $t['fontFamily'] }};font-size:{{ $t['labelSize'] }};color:{{ $t['primaryText'] }};opacity:0.9;word-break:break-word;overflow-wrap:anywhere;">
                    {{ $tenantName }}
                </div>
            @endif
        </td>
    </tr>
</table>
