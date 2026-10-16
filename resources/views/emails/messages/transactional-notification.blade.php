@component('emails.layouts.master', [
    'preheader' => $preheader ?? $heading,
    'productName' => $productName ?? null,
])
    <x-email.heading>{{ $heading }}</x-email.heading>

    @foreach($paragraphs as $paragraph)
        <x-email.paragraph>{{ $paragraph }}</x-email.paragraph>
    @endforeach

    @if(!empty($ctaUrl) && !empty($ctaLabel))
        <x-email.button :url="$ctaUrl" :label="$ctaLabel" />
    @endif
@endcomponent
