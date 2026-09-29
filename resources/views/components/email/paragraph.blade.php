@props([
    'muted' => false,
])
@php
    use App\Support\Email\EmailTheme;
    $t = EmailTheme::inlineBaseStyles();
    $color = $muted ? $t['muted'] : $t['textSecondary'];
    $size = $muted ? $t['secondarySize'] : $t['bodySize'];
@endphp
<p style="margin:0 0 16px 0;font-family:{{ $t['fontFamily'] }};font-size:{{ $size }};line-height:{{ $t['bodyLineHeight'] }};color:{{ $color }};word-break:break-word;overflow-wrap:anywhere;white-space:pre-line;">{{ $slot }}</p>
