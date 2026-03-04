@props([
    'size' => 44,
    'showWordmark' => true,
    'class' => '',
])

@php
    $size = (int) $size;
@endphp

@if($showWordmark)
    <x-application-logo class="{{ $class }}" style="font-size: {{ max(16, (int) floor($size * 0.42)) }}px;" />
@else
    <x-application-logo variant="icon" class="{{ $class }}" style="height: {{ $size }}px; width: {{ $size }}px;" />
@endif
