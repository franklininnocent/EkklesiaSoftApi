@php
    use App\Support\Email\EmailTheme;
    $t = EmailTheme::inlineBaseStyles();
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:8px 0 20px 0;">
    <tr>
        <td style="border-top:1px solid {{ $t['divider'] }};font-size:0;line-height:0;">&nbsp;</td>
    </tr>
</table>
