@props([
    'size' => 44,          // px
    'showWordmark' => true,
    'class' => '',
])

@php
    $s = (int) $size;
@endphp

<div class="inline-flex items-center gap-3 {{ $class }}">
    {{-- Monogram --}}
    <div class="relative grid place-items-center rounded-2xl border border-white/10 bg-white/5"
         style="width: {{ $s }}px; height: {{ $s }}px;">
        <svg width="{{ (int)($s*0.72) }}" height="{{ (int)($s*0.72) }}" viewBox="0 0 48 48" fill="none" aria-hidden="true">
            <defs>
                <linearGradient id="sa_g1" x1="6" y1="6" x2="44" y2="44" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#6366F1" stop-opacity="0.95"/>
                    <stop offset="1" stop-color="#22D3EE" stop-opacity="0.95"/>
                </linearGradient>
                <radialGradient id="sa_g2" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse"
                    gradientTransform="translate(24 24) rotate(90) scale(22)">
                    <stop stop-color="#FFFFFF" stop-opacity="0.22"/>
                    <stop offset="1" stop-color="#FFFFFF" stop-opacity="0"/>
                </radialGradient>
            </defs>

            {{-- Outer ring --}}
            <path d="M24 4.8C34.6 4.8 43.2 13.4 43.2 24C43.2 34.6 34.6 43.2 24 43.2C13.4 43.2 4.8 34.6 4.8 24C4.8 13.4 13.4 4.8 24 4.8Z"
                  stroke="url(#sa_g1)" stroke-width="1.6" opacity="0.85"/>

            {{-- Glow core --}}
            <circle cx="24" cy="24" r="20" fill="url(#sa_g2)"/>

            {{-- "S" curve --}}
            <path d="M31.8 16.4c-1.2-2.4-3.7-3.7-7.5-3.7c-4.3 0-7.3 2-7.3 5.1c0 2.5 1.8 4 5.8 4.7l2.7.5c2.9.5 4 1.2 4 2.6
                     c0 1.9-2 3.2-5.1 3.2c-3 0-4.9-1-6-3.2"
                  stroke="url(#sa_g1)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" opacity="0.95"/>

            {{-- "AI" spark --}}
            <path d="M34.6 22.2l1.6-1.6m-1.6 5.2l1.6 1.6m-2.4-2.6h3.4"
                  stroke="#22D3EE" stroke-width="2" stroke-linecap="round" opacity="0.9"/>
            <path d="M36.8 30.2l-2.3 5.5m-3.2-5.5l2.3 5.5m-1.15-2.75h3.2"
                  stroke="#6366F1" stroke-width="1.8" stroke-linecap="round" opacity="0.9"/>
        </svg>

        {{-- tiny highlight --}}
        <div class="pointer-events-none absolute -top-2 -right-2 h-10 w-10 rounded-full blur-2xl bg-cyan-400/15"></div>
    </div>

    {{-- Wordmark --}}
    @if($showWordmark)
        <div class="leading-tight select-none">
            <div class="text-lg font-semibold tracking-tight">
                <span class="text-white">Social</span>
                <span class="text-transparent bg-clip-text bg-gradient-to-r from-indigo-400 to-cyan-300">AI</span>
            </div>
            <div class="text-[11px] text-white/55 tracking-wide uppercase">
                Studio · Automations
            </div>
        </div>
    @endif
</div>
