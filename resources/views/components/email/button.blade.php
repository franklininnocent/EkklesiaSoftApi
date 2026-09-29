@props([
    'url',
    'label',
    'secondary' => false,
])
@php
    use App\Support\Email\EmailTheme;
    $t = EmailTheme::inlineBaseStyles();
    $bg = $secondary ? $t['surface'] : $t['primary'];
    $fg = $secondary ? $t['primary'] : $t['primaryText'];
    $border = $secondary ? '1px solid '.$t['primary'] : '1px solid '.$t['primary'];
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px 0;">
    <tr>
        <td align="center" bgcolor="{{ $bg }}" style="border-radius:{{ $t['buttonRadius'] }};background-color:{{ $bg }};border:{{ $border }};">
            <!--[if mso]>
            <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $url }}" style="height:44px;v-text-anchor:middle;width:220px;" arcsize="18%" stroke="f" fillcolor="{{ $bg }}">
            <w:anchorlock/>
            <center style="color:{{ $fg }};font-family:Segoe UI, Helvetica, Arial, sans-serif;font-size:16px;font-weight:600;">{{ $label }}</center>
            </v:roundrect>
            <![endif]-->
            <!--[if !mso]><!-->
            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" style="display:inline-block;min-height:{{ $t['buttonMinHeight'] }};padding:{{ $t['buttonPadY'] }} {{ $t['buttonPadX'] }};font-family:{{ $t['fontFamily'] }};font-size:{{ $t['buttonSize'] }};font-weight:600;line-height:1.25;color:{{ $fg }};text-decoration:none;border-radius:{{ $t['buttonRadius'] }};background-color:{{ $bg }};">
                {{ $label }}
            </a>
            <!--<![endif]-->
        </td>
    </tr>
</table>
<p style="margin:0 0 20px 0;font-family:{{ $t['fontFamily'] }};font-size:{{ $t['labelSize'] }};line-height:1.5;color:{{ $t['muted'] }};word-break:break-all;overflow-wrap:anywhere;">
    If the button does not work, copy and paste this link into your browser:<br>
    <a href="{{ $url }}" style="color:{{ $t['link'] }};text-decoration:underline;">{{ $url }}</a>
</p>
