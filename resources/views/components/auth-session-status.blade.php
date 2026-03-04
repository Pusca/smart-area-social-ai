@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'ui-alert border-emerald-200 bg-emerald-50 font-medium text-success']) }}>
        {{ $status }}
    </div>
@endif
