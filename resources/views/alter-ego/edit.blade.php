@extends('layouts.app')

@section('content')

<section class="mx-auto w-full max-w-2xl px-4 py-8 sm:px-6">

    <div class="mb-6">
        <a href="{{ route('alter-ego.index') }}" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            Alter ego
        </a>
        <h1 class="mt-2 text-2xl font-semibold text-gray-950">Modifica: {{ $alterEgo->name }}</h1>
    </div>

    @if($errors->any())
        <div class="mb-4 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <ul class="space-y-0.5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ── Identità Visiva ──────────────────────────────────────────────────── --}}
    <div
        x-data="alterEgoMedia(
            @js(array_values(array_filter((array) ($alterEgo->visual_references ?? [])))),
            @js(array_values(array_filter((array) ($alterEgo->audio_references ?? [])))),
            @js(array_values(array_filter((array) ($alterEgo->video_references ?? [])))),
            '{{ route('alter-ego.media.upload', $alterEgo) }}',
            '{{ route('alter-ego.media.destroy', $alterEgo) }}',
            '{{ csrf_token() }}'
        )"
        class="overflow-hidden rounded-[28px] border border-app bg-white shadow-sm"
    >
        <div class="border-b border-app bg-gradient-to-r from-indigo-50 to-cyan-50 px-6 py-4">
            <div class="flex items-center gap-2">
                <svg class="h-5 w-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                <h2 class="text-base font-semibold text-gray-900">Identità visiva</h2>
            </div>
            <p class="mt-0.5 text-sm text-gray-500">Carica foto, audio e video della persona. L'AI userà questi file come riferimento per mantenere l'aspetto sempre coerente in ogni contenuto generato.</p>
        </div>

        <div class="space-y-6 p-6">

            {{-- ── FOTO ── --}}
            <div>
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-gray-800">Foto di riferimento</p>
                        <p class="text-xs text-gray-500">Almeno 3–5 foto recenti, buona luce, volto ben visibile. Più foto carichi, più stabile sarà la coerenza visiva.</p>
                    </div>
                    <label class="cursor-pointer inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700 transition">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Aggiungi foto
                        <input type="file" accept="image/*" multiple class="hidden" @change="upload($event.target.files, 'photos'); $event.target.value=''">
                    </label>
                </div>

                {{-- Griglia foto --}}
                <div class="mt-3">
                    <template x-if="photos.length === 0">
                        <label class="flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed border-gray-300 bg-gray-50 px-6 py-8 text-center transition hover:border-indigo-400 hover:bg-indigo-50/30">
                            <svg class="h-8 w-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            <p class="mt-2 text-sm font-medium text-gray-600">Trascina le foto qui o clicca per selezionarle</p>
                            <p class="mt-1 text-xs text-gray-400">JPG, PNG, WEBP — max 50 MB ciascuna</p>
                            <input type="file" accept="image/*" multiple class="hidden" @change="upload($event.target.files, 'photos'); $event.target.value=''">
                        </label>
                    </template>
                    <div class="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-6">
                        <template x-for="(item, i) in photos" :key="item.path">
                            <div class="group relative aspect-square overflow-hidden rounded-xl border border-app bg-gray-100">
                                <img :src="item.url" :alt="`Foto ${i+1}`" class="h-full w-full object-cover">
                                <button
                                    type="button"
                                    @click="remove(item.path, 'photos')"
                                    class="absolute right-1 top-1 flex h-6 w-6 items-center justify-center rounded-full bg-black/60 text-white opacity-0 transition group-hover:opacity-100 hover:bg-red-600"
                                    title="Rimuovi"
                                >
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            {{-- ── AUDIO ── --}}
            <div>
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-gray-800">Campioni audio <span class="font-normal text-gray-400">(opzionale)</span></p>
                        <p class="text-xs text-gray-500">Voce della persona, 20–60 secondi. Usato per calibrare voce e cadenza narrativa.</p>
                    </div>
                    <label class="cursor-pointer inline-flex items-center gap-1.5 rounded-xl bg-slate-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800 transition">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Aggiungi audio
                        <input type="file" accept="audio/*" multiple class="hidden" @change="upload($event.target.files, 'audio'); $event.target.value=''">
                    </label>
                </div>
                <div class="mt-3 space-y-1.5">
                    <template x-for="(item, i) in audios" :key="item.path">
                        <div class="flex items-center gap-3 rounded-xl border border-app bg-gray-50 px-3 py-2">
                            <svg class="h-4 w-4 shrink-0 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"/></svg>
                            <audio :src="item.url" controls class="h-7 flex-1 min-w-0"></audio>
                            <button type="button" @click="remove(item.path, 'audio')" class="shrink-0 text-gray-400 hover:text-red-500 transition" title="Rimuovi">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                    </template>
                    <template x-if="audios.length === 0">
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-3 hover:border-indigo-300 hover:bg-indigo-50/20 transition">
                            <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            <span class="text-sm text-gray-500">Nessun audio — clicca per aggiungere</span>
                            <input type="file" accept="audio/*" multiple class="hidden" @change="upload($event.target.files, 'audio'); $event.target.value=''">
                        </label>
                    </template>
                </div>
            </div>

            {{-- ── VIDEO ── --}}
            <div>
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-gray-800">Campioni video <span class="font-normal text-gray-400">(opzionale)</span></p>
                        <p class="text-xs text-gray-500">Clip brevi (10–30 sec) della persona in situazioni comunicative reali.</p>
                    </div>
                    <label class="cursor-pointer inline-flex items-center gap-1.5 rounded-xl bg-slate-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800 transition">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Aggiungi video
                        <input type="file" accept="video/*" multiple class="hidden" @change="upload($event.target.files, 'video'); $event.target.value=''">
                    </label>
                </div>
                <div class="mt-3 space-y-1.5">
                    <template x-for="(item, i) in videos" :key="item.path">
                        <div class="flex items-center gap-3 rounded-xl border border-app bg-gray-50 px-3 py-2">
                            <svg class="h-4 w-4 shrink-0 text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 10l4.553-2.069A1 1 0 0121 8.879V15.12a1 1 0 01-1.447.89L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                            <video :src="item.url" controls class="h-16 flex-1 min-w-0 rounded-lg object-cover"></video>
                            <button type="button" @click="remove(item.path, 'video')" class="shrink-0 text-gray-400 hover:text-red-500 transition" title="Rimuovi">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                    </template>
                    <template x-if="videos.length === 0">
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-3 hover:border-cyan-300 hover:bg-cyan-50/20 transition">
                            <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            <span class="text-sm text-gray-500">Nessun video — clicca per aggiungere</span>
                            <input type="file" accept="video/*" multiple class="hidden" @change="upload($event.target.files, 'video'); $event.target.value=''">
                        </label>
                    </template>
                </div>
            </div>

            {{-- Stato upload --}}
            <div x-show="uploading" x-cloak class="flex items-center gap-2 rounded-xl bg-indigo-50 px-4 py-2.5 text-sm text-indigo-700">
                <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/></svg>
                Caricamento in corso...
            </div>
            <div x-show="uploadError" x-cloak class="rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700" x-text="uploadError"></div>

        </div>
    </div>

    <form method="POST" action="{{ route('alter-ego.update', $alterEgo) }}" class="space-y-5">
        @csrf
        @method('PUT')

        {{-- Nome --}}
        <div>
            <label class="block text-sm font-semibold text-gray-700">Nome</label>
            <input type="text" name="name" value="{{ old('name', $alterEgo->name) }}" required maxlength="150"
                class="mt-1.5 block w-full rounded-2xl border border-app bg-app/30 px-4 py-2.5 text-sm text-gray-950 focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
        </div>

        {{-- Archetipo --}}
        <div>
            <label class="block text-sm font-semibold text-gray-700">Archetipo</label>
            <select name="archetype"
                class="mt-1.5 block w-full rounded-2xl border border-app bg-app/30 px-4 py-2.5 text-sm text-gray-950 focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                @foreach(['thought_leader','educator','storyteller','entertainer','provocateur','connector'] as $key)
                    <option value="{{ $key }}" @selected(old('archetype', $alterEgo->archetype) === $key)>
                        {{ \App\Services\AlterEgoService::archetypeLabel($key) }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            {{-- Tono --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700">Tono</label>
                <select name="tone"
                    class="mt-1.5 block w-full rounded-2xl border border-app bg-app/30 px-4 py-2.5 text-sm text-gray-950 focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    @foreach(['autorevole','diretto','empatico','ironico','formale','colloquiale'] as $key)
                        <option value="{{ $key }}" @selected(old('tone', $alterEgo->tone) === $key)>
                            {{ \App\Services\AlterEgoService::toneLabel($key) }}
                        </option>
                    @endforeach
                </select>
            </div>
            {{-- Struttura frasi --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700">Struttura frasi</label>
                <select name="sentence_style"
                    class="mt-1.5 block w-full rounded-2xl border border-app bg-app/30 px-4 py-2.5 text-sm text-gray-950 focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    @foreach(['brevi_incisive','narrative','domande_retoriche'] as $key)
                        <option value="{{ $key }}" @selected(old('sentence_style', $alterEgo->sentence_style) === $key)>
                            {{ \App\Services\AlterEgoService::sentenceStyleLabel($key) }}
                        </option>
                    @endforeach
                </select>
            </div>
            {{-- Vocabolario --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700">Vocabolario</label>
                <select name="vocabulary_level"
                    class="mt-1.5 block w-full rounded-2xl border border-app bg-app/30 px-4 py-2.5 text-sm text-gray-950 focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    @foreach(['tecnico','accessibile','misto'] as $key)
                        <option value="{{ $key }}" @selected(old('vocabulary_level', $alterEgo->vocabulary_level) === $key)>
                            {{ \App\Services\AlterEgoService::vocabularyLabel($key) }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Frasi firma --}}
        <div x-data="tagInput(@js(old('signature_phrases', $alterEgo->signature_phrases ?? [])), 'signature_phrases')">
            <label class="block text-sm font-semibold text-gray-700">Frasi caratteristiche</label>
            <div class="mt-1.5 flex flex-wrap gap-1.5 rounded-2xl border border-app bg-app/30 px-3 py-2">
                <template x-for="(tag, i) in tags" :key="i">
                    <span class="flex items-center gap-1 rounded-full bg-indigo-100 px-3 py-1 text-xs font-medium text-indigo-700">
                        <input type="hidden" :name="`signature_phrases[]`" :value="tag">
                        <span x-text="tag"></span>
                        <button type="button" @click="tags.splice(i,1)" class="ml-1 text-indigo-400 hover:text-indigo-700">&times;</button>
                    </span>
                </template>
                <input type="text" @keydown.enter.prevent="add" @keydown.comma.prevent="add"
                    placeholder="Aggiungi frase…"
                    class="min-w-[140px] flex-1 bg-transparent text-sm text-gray-800 placeholder-gray-400 focus:outline-none">
            </div>
        </div>

        {{-- Topics owned --}}
        <div x-data="tagInput(@js(old('topics_owned', $alterEgo->topics_owned ?? [])), 'topics_owned')">
            <label class="block text-sm font-semibold text-gray-700">Temi di proprietà</label>
            <div class="mt-1.5 flex flex-wrap gap-1.5 rounded-2xl border border-app bg-app/30 px-3 py-2">
                <template x-for="(tag, i) in tags" :key="i">
                    <span class="flex items-center gap-1 rounded-full bg-cyan-100 px-3 py-1 text-xs font-medium text-cyan-700">
                        <input type="hidden" :name="`topics_owned[]`" :value="tag">
                        <span x-text="tag"></span>
                        <button type="button" @click="tags.splice(i,1)" class="ml-1 text-cyan-400 hover:text-cyan-700">&times;</button>
                    </span>
                </template>
                <input type="text" @keydown.enter.prevent="add" @keydown.comma.prevent="add"
                    placeholder="Aggiungi tema…"
                    class="min-w-[140px] flex-1 bg-transparent text-sm text-gray-800 placeholder-gray-400 focus:outline-none">
            </div>
        </div>

        {{-- Topics avoided --}}
        <div x-data="tagInput(@js(old('topics_avoided', $alterEgo->topics_avoided ?? [])), 'topics_avoided')">
            <label class="block text-sm font-semibold text-gray-700">Temi da evitare</label>
            <div class="mt-1.5 flex flex-wrap gap-1.5 rounded-2xl border border-app bg-app/30 px-3 py-2">
                <template x-for="(tag, i) in tags" :key="i">
                    <span class="flex items-center gap-1 rounded-full bg-red-100 px-3 py-1 text-xs font-medium text-red-600">
                        <input type="hidden" :name="`topics_avoided[]`" :value="tag">
                        <span x-text="tag"></span>
                        <button type="button" @click="tags.splice(i,1)" class="ml-1 text-red-400 hover:text-red-700">&times;</button>
                    </span>
                </template>
                <input type="text" @keydown.enter.prevent="add" @keydown.comma.prevent="add"
                    placeholder="Aggiungi tema…"
                    class="min-w-[140px] flex-1 bg-transparent text-sm text-gray-800 placeholder-gray-400 focus:outline-none">
            </div>
        </div>

        {{-- Prospettiva unica --}}
        <div>
            <label class="block text-sm font-semibold text-gray-700">Prospettiva unica</label>
            <textarea name="unique_perspective" rows="3" maxlength="800"
                class="mt-1.5 block w-full rounded-2xl border border-app bg-app/30 px-4 py-2.5 text-sm text-gray-950 focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">{{ old('unique_perspective', $alterEgo->unique_perspective) }}</textarea>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            {{-- Ruolo pubblico --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700">Ruolo verso il pubblico</label>
                <select name="audience_role"
                    class="mt-1.5 block w-full rounded-2xl border border-app bg-app/30 px-4 py-2.5 text-sm text-gray-950 focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    <option value="">— Nessuno —</option>
                    @foreach(['teacher','peer','mentor','challenger','entertainer'] as $key)
                        <option value="{{ $key }}" @selected(old('audience_role', $alterEgo->audience_role) === $key)>
                            {{ \App\Services\AlterEgoService::audienceRoleLabel($key) }}
                        </option>
                    @endforeach
                </select>
            </div>
            {{-- CTA style --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700">Stile CTA</label>
                <select name="cta_style"
                    class="mt-1.5 block w-full rounded-2xl border border-app bg-app/30 px-4 py-2.5 text-sm text-gray-950 focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    @foreach(['soft','direct','domanda','nessuna'] as $key)
                        <option value="{{ $key }}" @selected(old('cta_style', $alterEgo->cta_style) === $key)>
                            {{ \App\Services\AlterEgoService::ctaStyleLabel($key) }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Training samples --}}
        <div>
            <label class="block text-sm font-semibold text-gray-700">Campioni di scrittura</label>
            <div class="mt-2 space-y-2">
                @php $samples = old('training_samples', $alterEgo->training_samples ?? []); @endphp
                @foreach(array_pad((array) $samples, 3, '') as $i => $sample)
                    <textarea name="training_samples[]" rows="3" maxlength="3000"
                        placeholder="Testo campione {{ $i + 1 }} (opzionale)"
                        class="block w-full rounded-2xl border border-app bg-app/30 px-4 py-2.5 text-sm text-gray-950 placeholder-gray-400 focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">{{ $sample }}</textarea>
                @endforeach
            </div>
        </div>

        {{-- Default --}}
        <label class="flex cursor-pointer items-center gap-3 rounded-2xl border border-app bg-app/30 px-4 py-3">
            <input type="checkbox" name="is_default" value="1" {{ old('is_default', $alterEgo->is_default) ? 'checked' : '' }}
                class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
            <div>
                <p class="text-sm font-semibold text-gray-900">Imposta come alter ego default</p>
                <p class="text-xs text-gray-500">Applicato automaticamente a ogni nuovo contenuto.</p>
            </div>
        </label>

        <div class="flex justify-end gap-3 border-t border-app pt-5">
            <a href="{{ route('alter-ego.index') }}" class="rounded-2xl border border-app px-5 py-2.5 text-sm font-semibold text-gray-600 transition hover:bg-gray-50">
                Annulla
            </a>
            <button type="submit" class="rounded-2xl bg-slate-900 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">
                Salva modifiche
            </button>
        </div>
    </form>
</section>

<script>
function tagInput(initial, fieldName) {
    return {
        tags: Array.isArray(initial) ? initial.filter(Boolean) : [],
        add(e) {
            const val = (e.target.value || '').trim().replace(/,$/, '');
            if (val && !this.tags.includes(val)) this.tags.push(val);
            e.target.value = '';
        },
    };
}

function alterEgoMedia(initialPhotos, initialAudios, initialVideos, uploadUrl, destroyUrl, csrfToken) {
    const toItems = (paths) => paths.map(p => ({
        path: p,
        url: '/storage/' + p,
    }));
    return {
        photos:      toItems(initialPhotos),
        audios:      toItems(initialAudios),
        videos:      toItems(initialVideos),
        uploading:   false,
        uploadError: '',

        async upload(files, type) {
            if (!files || files.length === 0) return;
            this.uploading   = true;
            this.uploadError = '';
            const fd = new FormData();
            fd.append('type', type);
            for (const f of files) fd.append('files[]', f);
            try {
                const res = await fetch(uploadUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                    body: fd,
                });
                const json = await res.json();
                if (!res.ok || !json.ok) throw new Error(json.message || 'Errore caricamento');
                const newItems = (json.paths || []).map((p, i) => ({ path: p, url: json.urls[i] || '/storage/' + p }));
                if (type === 'photos')      this.photos = [...this.photos, ...newItems];
                else if (type === 'audio')  this.audios = [...this.audios, ...newItems];
                else if (type === 'video')  this.videos = [...this.videos, ...newItems];
            } catch (e) {
                this.uploadError = e.message || 'Caricamento non riuscito.';
            } finally {
                this.uploading = false;
            }
        },

        async remove(path, type) {
            this.uploadError = '';
            try {
                const res = await fetch(destroyUrl, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ type, path }),
                });
                const json = await res.json();
                if (!res.ok || !json.ok) throw new Error(json.message || 'Errore eliminazione');
                if (type === 'photos')      this.photos = this.photos.filter(i => i.path !== path);
                else if (type === 'audio')  this.audios = this.audios.filter(i => i.path !== path);
                else if (type === 'video')  this.videos = this.videos.filter(i => i.path !== path);
            } catch (e) {
                this.uploadError = e.message || 'Eliminazione non riuscita.';
            }
        },
    };
}
</script>
@endsection
