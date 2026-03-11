@props([
    'size' => 44,
    'showWordmark' => true,
    'class' => '',
])

@php
    $size = (int) $size;
    $lockupHeight = max(28, (int) floor($size * 0.7));
@endphp

@if($showWordmark)
    <x-application-logo class="{{ $class }}" style="height: {{ $lockupHeight }}px;" />
@else
    <x-application-logo variant="icon" class="{{ $class }}" style="height: {{ $size }}px; width: {{ $size }}px;" />
@endif
