@props([
    'variant' => 'info',
    'title' => null,
])
@php
    use App\Support\Email\EmailTheme;
    $t = EmailTheme::inlineBaseStyles();
    $map = [
        'success' => ['fg' => $t['success'], 'bg' => $t['successSoft']],
        'warning' => ['fg' => $t['warning'], 'bg' => $t['warningSoft']],
        'danger' => ['fg' => $t['danger'], 'bg' => $t['dangerSoft']],
        'error' => ['fg' => $t['danger'], 'bg' => $t['dangerSoft']],
        'info' => ['fg' => $t['info'], 'bg' => $t['infoSoft']],
    ];
    $palette = $map[$variant] ?? $map['info'];
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 16px 0;border-radius:{{ $t['cardRadius'] }};background-color:{{ $palette['bg'] }};">
    <tr>
        <td style="padding:{{ $t['cardPad'] }};border-left:4px solid {{ $palette['fg'] }};font-family:{{ $t['fontFamily'] }};font-size:{{ $t['secondarySize'] }};line-height:1.5;color:{{ $palette['fg'] }};word-break:break-word;overflow-wrap:anywhere;">
            @if($title)
                <div style="margin:0 0 6px 0;font-weight:700;">{{ $title }}</div>
            @endif
            {{ $slot }}
        </td>
    </tr>
</table>
