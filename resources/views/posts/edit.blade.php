@extends('layouts.app')

@section('content')
@php
    $assetList = is_array($contentItem->assets ?? null) ? $contentItem->assets : [];
    $videoPath = null;
    foreach ($assetList as $asset) {
        if (!is_array($asset)) {
            continue;
        }
        $assetPath = trim((string) ($asset['path'] ?? ''));
        if ($assetPath === '') {
            continue;
        }
        $assetType = strtolower(trim((string) ($asset['type'] ?? '')));
        $ext = strtolower((string) pathinfo($assetPath, PATHINFO_EXTENSION));
        if (str_contains($assetType, 'video') || in_array($ext, ['mp4', 'mov', 'webm', 'm4v', 'avi'], true)) {
            $videoPath = $assetPath;
            break;
        }
    }

    $statusClass = ($contentItem->ai_status === 'done')
        ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
        : (($contentItem->ai_status === 'error')
            ? 'border-red-200 bg-red-50 text-red-700'
            : 'border-amber-200 bg-amber-50 text-amber-700');
@endphp

<style>
    .post-preview-media {
        display: block;
        width: auto;
        max-width: 100%;
        height: auto;
        max-height: 72vh;
        margin-inline: auto;
    }

    @media (min-width: 1024px) {
        .post-preview-media {
            max-height: 56vh;
        }
    }
</style>

<section class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
    <div class="overflow-hidden rounded-3xl border border-gray-200 bg-gradient-to-br from-white via-indigo-50/40 to-cyan-50/40 p-6 shadow-sm lg:p-8">
        <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr] xl:items-center">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Content Editor</div>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">Modifica contenuto</h1>
                <p class="mt-3 max-w-2xl text-sm text-gray-600">
                    Aggiorna pianificazione, caption, prompt immagine e stato AI del contenuto.
                </p>

                <div class="mt-5 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700">
                        {{ strtoupper((string) $contentItem->platform) }} - {{ strtoupper((string) $contentItem->format) }}
                    </span>
                    <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $statusClass }}">
                        AI {{ $contentItem->ai_status ?? 'n/a' }}
                    </span>
                    @if($contentItem->scheduled_at)
                        <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700">
                            {{ $contentItem->scheduled_at->format('d/m H:i') }}
                        </span>
                    @endif
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Azioni rapide</div>
                <div class="mt-3 grid grid-cols-1 gap-2">
                    <a href="{{ route('posts.index') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Torna ai contenuti
                    </a>
                    <a href="{{ route('calendar') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Apri calendario
                    </a>
                    <a href="{{ route('posts.create') }}" class="inline-flex items-center justify-center rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                        Nuovo contenuto
                    </a>
                </div>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    @if($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <p class="font-semibold">Controlla i campi:</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('ai.content.generate', $contentItem) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                            Rigenera AI completo
                        </button>
                    </form>

                    <form method="POST" action="{{ route('ai.content.generateImage', $contentItem) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                            Rigenera visual
                        </button>
                    </form>
                </div>
            </div>

            <form method="POST" action="{{ route('posts.update', $contentItem) }}" class="space-y-6">
                @csrf
                @method('PUT')

                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">Dati contenuto</h2>
                    <p class="mt-1 text-sm text-gray-600">Metadati di pubblicazione e stato operativo.</p>

                    <div class="mt-4 space-y-4">
                        <div>
                            <label for="title" class="mb-1 block text-sm font-semibold text-gray-700">Titolo</label>
                            <input id="title" type="text" name="title" value="{{ old('title', $contentItem->title) }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label for="platform" class="mb-1 block text-sm font-semibold text-gray-700">Piattaforma</label>
                                <input id="platform" type="text" name="platform" value="{{ old('platform', $contentItem->platform) }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" required>
                            </div>
                            <div>
                                <label for="format" class="mb-1 block text-sm font-semibold text-gray-700">Formato</label>
                                <input id="format" type="text" name="format" value="{{ old('format', $contentItem->format) }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" required>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label for="scheduled_at" class="mb-1 block text-sm font-semibold text-gray-700">Programmazione</label>
                                <input id="scheduled_at" type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at', optional($contentItem->scheduled_at)->format('Y-m-d\TH:i')) }}" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                            </div>
                            <div>
                                <label for="status" class="mb-1 block text-sm font-semibold text-gray-700">Stato</label>
                                <select id="status" name="status" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                    @php $statusValue = old('status', $contentItem->status); @endphp
                                    <option value="draft" @selected($statusValue === 'draft')>draft</option>
                                    <option value="review" @selected($statusValue === 'review')>review</option>
                                    <option value="approved" @selected($statusValue === 'approved')>approved</option>
                                    <option value="scheduled" @selected($statusValue === 'scheduled')>scheduled</option>
                                    <option value="published" @selected($statusValue === 'published')>published</option>
                                    <option value="failed" @selected($statusValue === 'failed')>failed</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">Caption AI</h2>
                    <textarea name="ai_caption" rows="10" class="js-autogrow mt-4 block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">{{ old('ai_caption', $contentItem->ai_caption) }}</textarea>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">Prompt visual AI</h2>
                    <textarea name="ai_image_prompt" rows="8" class="js-autogrow mt-4 block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">{{ old('ai_image_prompt', $contentItem->ai_image_prompt) }}</textarea>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button type="submit" class="inline-flex items-center rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                        Salva modifiche
                    </button>
                    <a href="{{ route('posts.index') }}" class="inline-flex items-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Annulla
                    </a>
                </div>
            </form>
        </div>

        <div class="space-y-6">
            <aside class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900">Preview asset</h2>
                    <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ ($contentItem->ai_image_path || $videoPath) ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-indigo-200 bg-indigo-50 text-indigo-700' }}">
                        {{ ($contentItem->ai_image_path || $videoPath) ? 'Generato' : 'Nessun asset' }}
                    </span>
                </div>

                @if($videoPath)
                    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200 bg-white p-1">
                        <video class="post-preview-media rounded-lg bg-black" controls preload="metadata">
                            <source src="{{ asset('storage/' . ltrim($videoPath, '/')) }}" type="video/mp4">
                        </video>
                    </div>
                    <p class="mt-2 break-words text-xs text-gray-500">Video: <span class="font-mono">{{ $videoPath }}</span></p>
                    @if($contentItem->ai_image_path)
                        <p class="mt-1 text-xs text-gray-500">Anteprima griglia: <span class="font-mono">{{ $contentItem->ai_image_path }}</span></p>
                    @endif
                @elseif($contentItem->ai_image_path)
                    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200 bg-white p-1">
                        <img
                            src="{{ asset('storage/' . ltrim($contentItem->ai_image_path, '/')) }}"
                            alt="AI image"
                            class="post-preview-media rounded-lg"
                            loading="lazy"
                        >
                    </div>
                    <p class="mt-2 break-words text-xs text-gray-500">Path: <span class="font-mono">{{ $contentItem->ai_image_path }}</span></p>
                @else
                    <div class="mt-4 rounded-xl border border-dashed border-gray-300 bg-gray-50 p-4 text-sm text-gray-600">
                        Nessun asset visual disponibile.
                    </div>
                @endif
            </aside>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Info contenuto</h2>
                <div class="mt-4 space-y-3">
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">ID</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">#{{ $contentItem->id }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Creato il</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">{{ optional($contentItem->created_at)->format('d/m/Y H:i') ?: '-' }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">AI generato il</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">{{ optional($contentItem->ai_generated_at)->format('d/m/Y H:i') ?: '-' }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
    (function () {
        const autosize = (el) => {
            el.style.height = 'auto';
            el.style.overflowY = 'hidden';
            el.style.height = (el.scrollHeight + 2) + 'px';
        };

        document.querySelectorAll('textarea.js-autogrow').forEach((el) => {
            autosize(el);
            el.addEventListener('input', () => autosize(el));
        });
    })();
</script>
@endsection
