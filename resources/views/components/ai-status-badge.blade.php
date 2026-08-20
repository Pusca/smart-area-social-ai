@props(['status', 'itemId' => null])

@php($s = $status instanceof \App\Enums\AiStatus ? $status : \App\Enums\AiStatus::tryFrom((string) $status))

<span {{ $attributes->merge(['class' => 'text-xs px-2 py-1 rounded-full whitespace-nowrap ' . ($s?->badgeClass() ?? 'bg-gray-100 text-gray-700')]) }}
      data-ai-status-badge
      @if($itemId) data-item-id="{{ $itemId }}" @endif
      data-status="{{ $s?->value }}"
      data-busy="{{ $s?->isBusy() ? 1 : 0 }}">
    AI: {{ $s?->label() ?? '—' }}
</span>
