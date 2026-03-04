@props([
    'variant' => 'lockup',
])

@php
    $isIcon = in_array($variant, ['icon', 'favicon', 'app-icon'], true);
@endphp

@if($isIcon)
    <span {{ $attributes->merge(['class' => 'inline-flex h-9 w-9 items-center justify-center rounded-xl border border-brand bg-surface font-heading text-sm font-extrabold uppercase tracking-[0.16em] text-brand']) }}>
        sa
    </span>
@else
    <span {{ $attributes->merge(['class' => 'inline-flex items-baseline gap-1 font-heading text-xl font-extrabold tracking-tight']) }}>
        <span class="lowercase text-text">social</span>
        <span class="bg-brand bg-clip-text lowercase text-transparent">ai</span>
    </span>
@endif
