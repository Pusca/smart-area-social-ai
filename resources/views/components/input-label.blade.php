@props(['value'])

<label {{ $attributes->merge(['class' => 'ui-label block']) }}>
    {{ $value ?? $slot }}
</label>
