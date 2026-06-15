@props(['active'])

@php
$classes = ($active ?? false)
            ? 'ui-nav-link-active'
            : 'ui-nav-link';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
