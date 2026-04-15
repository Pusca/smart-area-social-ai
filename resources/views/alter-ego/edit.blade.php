@extends('layouts.app')

@section('content')
@php
    use App\Services\AlterEgoService;
@endphp

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
                        {{ AlterEgoService::archetypeLabel($key) }}
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
                            {{ AlterEgoService::toneLabel($key) }}
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
                            {{ AlterEgoService::sentenceStyleLabel($key) }}
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
                            {{ AlterEgoService::vocabularyLabel($key) }}
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
                            {{ AlterEgoService::audienceRoleLabel($key) }}
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
                            {{ AlterEgoService::ctaStyleLabel($key) }}
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
</script>
@endsection
