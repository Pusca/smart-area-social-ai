@props([
    'size' => 44,
    'showWordmark' => true,
    'class' => '',
])

@php
    $height = (int) $size;
    $width = (int) round($height * 4.45);
@endphp

@if($showWordmark)
    <x-application-logo class="{{ $class }}" style="height: {{ $height }}px; width: {{ $width }}px;" />
@else
    <x-application-logo class="{{ $class }}" style="height: {{ $height }}px; width: {{ $width }}px;" />
@endif
