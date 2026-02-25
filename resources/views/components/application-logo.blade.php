@php($logoId = 'tech' . uniqid())
@php($glowId = 'softGlow' . uniqid())

<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 980 220" role="img" aria-label="Social Ai logo" {{ $attributes }}>
    <defs>
        <linearGradient id="{{ $logoId }}" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#00E7FF" />
            <stop offset="0.55" stop-color="#6A5CFF" />
            <stop offset="1" stop-color="#B64CFF" />
        </linearGradient>
        <filter id="{{ $glowId }}" x="-40%" y="-40%" width="180%" height="180%">
            <feGaussianBlur stdDeviation="2.6" result="b" />
            <feColorMatrix in="b" type="matrix"
                values="1 0 0 0 0
                        0 1 0 0 0
                        0 0 1 0 0
                        0 0 0 .28 0" result="g" />
            <feMerge>
                <feMergeNode in="g" />
                <feMergeNode in="SourceGraphic" />
            </feMerge>
        </filter>
    </defs>

    <text x="40" y="140"
        font-family="ui-sans-serif, system-ui, -apple-system, Segoe UI, Inter, Roboto, Arial"
        font-size="112"
        font-weight="760"
        letter-spacing="-2.4"
        fill="#0B0F1A">Social</text>

    <text x="400" y="140"
        font-family="ui-sans-serif, system-ui, -apple-system, Segoe UI, Inter, Roboto, Arial"
        font-size="112"
        font-weight="920"
        letter-spacing="-2.4"
        fill="url(#{{ $logoId }})"
        filter="url(#{{ $glowId }})">Ai</text>
</svg>
