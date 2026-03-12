@props([
    'variant' => 'lockup',
    'alt' => null,
])

@php
    $isIcon = in_array($variant, ['icon', 'favicon', 'app-icon'], true);
    $assetPath = $isIcon
        ? asset('brand/icona-socialai.png')
        : asset('brand/logo-socialai.png');
    $defaultClasses = $isIcon
        ? 'block h-11 w-11 object-contain'
        : 'block h-12 w-auto object-contain';
@endphp

<img
    src="{{ $assetPath }}"
    alt="{{ $alt ?: config('app.name', 'Social AI') }}"
    draggable="false"
    {{ $attributes->merge(['class' => $defaultClasses]) }}
>
