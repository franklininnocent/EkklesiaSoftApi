@props([
    'rows' => [],
])
@php
    use App\Support\Email\EmailTheme;
    $t = EmailTheme::inlineBaseStyles();
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 16px 0;border:1px solid {{ $t['border'] }};border-radius:{{ $t['cardRadius'] }};background-color:{{ $t['background'] }};">
    <tr>
        <td style="padding:{{ $t['cardPad'] }};">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                @foreach($rows as $row)
                    @php
                        $label = is_array($row) ? (string) ($row['label'] ?? $row[0] ?? '') : '';
                        $value = is_array($row) ? (string) ($row['value'] ?? $row[1] ?? '') : '';
                    @endphp
                    <tr>
                        <td valign="top" width="38%" style="padding:6px 12px 6px 0;font-family:{{ $t['fontFamily'] }};font-size:{{ $t['labelSize'] }};line-height:1.4;color:{{ $t['muted'] }};font-weight:600;word-break:break-word;overflow-wrap:anywhere;">
                            {{ $label }}
                        </td>
                        <td valign="top" style="padding:6px 0;font-family:{{ $t['fontFamily'] }};font-size:{{ $t['secondarySize'] }};line-height:1.4;color:{{ $t['text'] }};word-break:break-word;overflow-wrap:anywhere;">
                            {{ $value }}
                        </td>
                    </tr>
                @endforeach
            </table>
        </td>
    </tr>
</table>
