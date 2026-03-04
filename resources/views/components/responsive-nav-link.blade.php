@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block w-full rounded-lg border border-brand bg-surface-2 px-3 py-2 text-start text-base font-semibold text-brand transition'
            : 'block w-full rounded-lg px-3 py-2 text-start text-base font-medium text-muted transition hover:bg-surface-2 hover:text-text';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
