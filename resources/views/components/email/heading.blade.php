@props([
    'level' => 1,
])
@php
    use App\Support\Email\EmailTheme;
    $t = EmailTheme::inlineBaseStyles();
    $size = $level === 2 ? $t['sectionSize'] : $t['headingSize'];
    $marginBottom = $level === 2 ? '12px' : '16px';
@endphp
@if($level === 2)
    <h2 style="margin:0 0 {{ $marginBottom }} 0;font-family:{{ $t['fontFamily'] }};font-size:{{ $size }};line-height:{{ $t['headingLineHeight'] }};font-weight:700;color:{{ $t['text'] }};word-break:break-word;overflow-wrap:anywhere;">
        {{ $slot }}
    </h2>
@else
    <h1 style="margin:0 0 {{ $marginBottom }} 0;font-family:{{ $t['fontFamily'] }};font-size:{{ $size }};line-height:{{ $t['headingLineHeight'] }};font-weight:700;color:{{ $t['text'] }};word-break:break-word;overflow-wrap:anywhere;">
        {{ $slot }}
    </h1>
@endif
