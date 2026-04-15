@extends('layouts.app')

@section('content')
<div
    x-data="alterEgoWizard()"
    class="mx-auto w-full max-w-3xl px-4 py-8 sm:px-6"
>
    {{-- Progress bar --}}
    <div class="mb-6">
        <div class="flex items-center justify-between text-xs text-gray-400 mb-2">
            <span x-text="`Passo ${step} di 5`"></span>
            <span x-text="stepLabels[step - 1]" class="font-semibold text-gray-600"></span>
        </div>
        <div class="h-1.5 overflow-hidden rounded-full bg-gray-100">
            <div
                class="h-full rounded-full bg-gradient-to-r from-cyan-400 via-indigo-500 to-emerald-400 transition-[width] duration-300"
                :style="`width: ${(step / 5) * 100}%`"
            ></div>
        </div>
    </div>

    <div class="overflow-hidden rounded-[32px] border border-app bg-white shadow-[0_24px_80px_rgba(15,23,42,0.08)]">
        <div class="h-1.5 bg-gradient-to-r from-cyan-400 via-indigo-500 to-emerald-400"></div>

        <div class="p-6 sm:p-8">

            {{-- STEP 1: Identità --}}
            <div x-show="step === 1" x-cloak>
                <h2 class="text-xl font-semibold text-gray-950">Chi è il tuo alter ego?</h2>
                <p class="mt-1 text-sm text-gray-500">Dagli un nome e scegli il suo archetipo comunicativo.</p>

                <div class="mt-6 space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Nome dell'alter ego <span class="text-red-500">*</span></label>
                        <input
                            type="text"
                            x-model="form.name"
                            placeholder="Es. Marco — Consulente Tech"
                            class="mt-1.5 block w-full rounded-2xl border border-app bg-app/30 px-4 py-2.5 text-sm text-gray-950 placeholder-gray-400 focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"
                        />
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Archetipo <span class="text-red-500">*</span></label>
                        <p class="text-xs text-gray-400">Il ruolo comunicativo principale.</p>
                        <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                            <template x-for="a in archetypes" :key="a.key">
                                <button
                                    type="button"
                                    @click="form.archetype = a.key"
                                    :class="form.archetype === a.key
                                        ? 'border-indigo-500 bg-indigo-50 ring-2 ring-indigo-300'
                                        : 'border-app bg-white hover:border-gray-300'"
                                    class="flex flex-col items-start rounded-2xl border p-3.5 text-left transition"
                                >
                                    <span class="text-lg" x-text="a.icon"></span>
                                    <span class="mt-1 text-sm font-semibold text-gray-900" x-text="a.label"></span>
                                    <span class="mt-0.5 text-xs text-gray-500" x-text="a.description"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="mt-8 flex justify-end">
                    <button @click="nextStep" :disabled="!form.name || !form.archetype" class="rounded-2xl bg-slate-900 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-40">
                        Continua
                    </button>
                </div>
            </div>

            {{-- STEP 2: Voce --}}
            <div x-show="step === 2" x-cloak>
                <h2 class="text-xl font-semibold text-gray-950">Voce e stile</h2>
                <p class="mt-1 text-sm text-gray-500">Come comunica questo alter ego? Definisci tono, ritmo e vocabolario.</p>

                <div class="mt-6 space-y-5">
                    {{-- Tono --}}
                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Tono <span class="text-red-500">*</span></label>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <template x-for="t in tones" :key="t.key">
                                <button
                                    type="button"
                                    @click="form.tone = t.key"
                                    :class="form.tone === t.key ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-app hover:border-gray-400'"
                                    class="rounded-full border px-4 py-1.5 text-sm font-medium transition"
                                    x-text="t.label"
                                ></button>
                            </template>
                        </div>
                    </div>

                    {{-- Struttura frasi --}}
                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Struttura delle frasi <span class="text-red-500">*</span></label>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <template x-for="s in sentenceStyles" :key="s.key">
                                <button
                                    type="button"
                                    @click="form.sentence_style = s.key"
                                    :class="form.sentence_style === s.key ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-app hover:border-gray-400'"
                                    class="rounded-full border px-4 py-1.5 text-sm font-medium transition"
                                    x-text="s.label"
                                ></button>
                            </template>
                        </div>
                    </div>

                    {{-- Vocabolario --}}
                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Vocabolario <span class="text-red-500">*</span></label>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <template x-for="v in vocabLevels" :key="v.key">
                                <button
                                    type="button"
                                    @click="form.vocabulary_level = v.key"
                                    :class="form.vocabulary_level === v.key ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-app hover:border-gray-400'"
                                    class="rounded-full border px-4 py-1.5 text-sm font-medium transition"
                                    x-text="v.label"
                                ></button>
                            </template>
                        </div>
                    </div>

                    {{-- Platform adaptations --}}
                    <details class="overflow-hidden rounded-2xl border border-app">
                        <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                            <span>Adattamenti per piattaforma <span class="ml-1 text-xs font-normal text-gray-400">(opzionale)</span></span>
                            <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </summary>
                        <div class="border-t border-app p-4 space-y-3">
                            <p class="text-xs text-gray-500">Se la voce cambia leggermente su LinkedIn rispetto a Instagram, specificalo qui. Lascia vuoto se la voce è uniforme.</p>
                            <template x-for="platform in platformAdaptPlatforms" :key="platform.key">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-600" x-text="platform.label"></label>
                                    <input
                                        type="text"
                                        @input="form.platform_adaptations[platform.key] = { modifier: $event.target.value }"
                                        :value="form.platform_adaptations[platform.key]?.modifier || ''"
                                        :placeholder="platform.placeholder"
                                        class="mt-1 block w-full rounded-xl border border-app bg-app/30 px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-brand focus:outline-none"
                                    />
                                </div>
                            </template>
                        </div>
                    </details>

                    {{-- Frasi firma --}}
                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Frasi caratteristiche <span class="text-xs font-normal text-gray-400">(opzionale)</span></label>
                        <p class="text-xs text-gray-400">Aggiungi frasi tipiche che userebbe. Premi Invio dopo ognuna.</p>
                        <div class="mt-2 flex flex-wrap gap-1.5 rounded-2xl border border-app bg-app/30 px-3 py-2">
                            <template x-for="(phrase, i) in form.signature_phrases" :key="i">
                                <span class="flex items-center gap-1 rounded-full bg-indigo-100 px-3 py-1 text-xs font-medium text-indigo-700">
                                    <span x-text="phrase"></span>
                                    <button type="button" @click="form.signature_phrases.splice(i, 1)" class="ml-1 text-indigo-400 hover:text-indigo-700">&times;</button>
                                </span>
                            </template>
                            <input
                                type="text"
                                x-ref="phraseInput"
                                @keydown.enter.prevent="addPhrase"
                                @keydown.comma.prevent="addPhrase"
                                placeholder="Es. Parliamoci chiari:"
                                class="min-w-[160px] flex-1 bg-transparent text-sm text-gray-800 placeholder-gray-400 focus:outline-none"
                            />
                        </div>
                    </div>
                </div>

                <div class="mt-8 flex justify-between">
                    <button @click="prevStep" class="rounded-2xl border border-app px-5 py-2.5 text-sm font-semibold text-gray-600 transition hover:bg-gray-50">
                        Indietro
                    </button>
                    <button @click="nextStep" :disabled="!form.tone || !form.sentence_style || !form.vocabulary_level" class="rounded-2xl bg-slate-900 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-40">
                        Continua
                    </button>
                </div>
            </div>

            {{-- STEP 3: Temi --}}
            <div x-show="step === 3" x-cloak>
                <h2 class="text-xl font-semibold text-gray-950">Temi e prospettiva</h2>
                <p class="mt-1 text-sm text-gray-500">Di cosa parla questo alter ego? Cosa lo rende unico?</p>

                <div class="mt-6 space-y-5">
                    {{-- Topics owned --}}
                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Temi di proprietà</label>
                        <p class="text-xs text-gray-400">Gli argomenti su cui è autorevole. Premi Invio dopo ognuno.</p>
                        <div class="mt-2 flex flex-wrap gap-1.5 rounded-2xl border border-app bg-app/30 px-3 py-2">
                            <template x-for="(topic, i) in form.topics_owned" :key="i">
                                <span class="flex items-center gap-1 rounded-full bg-cyan-100 px-3 py-1 text-xs font-medium text-cyan-700">
                                    <span x-text="topic"></span>
                                    <button type="button" @click="form.topics_owned.splice(i, 1)" class="ml-1 text-cyan-400 hover:text-cyan-700">&times;</button>
                                </span>
                            </template>
                            <input
                                type="text"
                                x-ref="topicOwnedInput"
                                @keydown.enter.prevent="addTopicOwned"
                                @keydown.comma.prevent="addTopicOwned"
                                placeholder="Es. AI nel business"
                                class="min-w-[160px] flex-1 bg-transparent text-sm text-gray-800 placeholder-gray-400 focus:outline-none"
                            />
                        </div>
                    </div>

                    {{-- Topics avoided --}}
                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Temi da evitare <span class="text-xs font-normal text-gray-400">(opzionale)</span></label>
                        <p class="text-xs text-gray-400">Argomenti che non toccherebbe mai. Premi Invio dopo ognuno.</p>
                        <div class="mt-2 flex flex-wrap gap-1.5 rounded-2xl border border-app bg-app/30 px-3 py-2">
                            <template x-for="(topic, i) in form.topics_avoided" :key="i">
                                <span class="flex items-center gap-1 rounded-full bg-red-100 px-3 py-1 text-xs font-medium text-red-600">
                                    <span x-text="topic"></span>
                                    <button type="button" @click="form.topics_avoided.splice(i, 1)" class="ml-1 text-red-400 hover:text-red-700">&times;</button>
                                </span>
                            </template>
                            <input
                                type="text"
                                x-ref="topicAvoidedInput"
                                @keydown.enter.prevent="addTopicAvoided"
                                @keydown.comma.prevent="addTopicAvoided"
                                placeholder="Es. Politica partitica"
                                class="min-w-[160px] flex-1 bg-transparent text-sm text-gray-800 placeholder-gray-400 focus:outline-none"
                            />
                        </div>
                    </div>

                    {{-- Prospettiva unica --}}
                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Prospettiva unica <span class="text-xs font-normal text-gray-400">(opzionale)</span></label>
                        <p class="text-xs text-gray-400">Cosa lo differenzia da chiunque altro nel suo settore?</p>
                        <textarea
                            x-model="form.unique_perspective"
                            rows="3"
                            placeholder="Es. Marco parla da professionista che ha vissuto i problemi dall'interno, non da consulente esterno che teorizza."
                            class="mt-1.5 block w-full rounded-2xl border border-app bg-app/30 px-4 py-2.5 text-sm text-gray-950 placeholder-gray-400 focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"
                        ></textarea>
                    </div>
                </div>

                <div class="mt-8 flex justify-between">
                    <button @click="prevStep" class="rounded-2xl border border-app px-5 py-2.5 text-sm font-semibold text-gray-600 transition hover:bg-gray-50">
                        Indietro
                    </button>
                    <button @click="nextStep" class="rounded-2xl bg-slate-900 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">
                        Continua
                    </button>
                </div>
            </div>

            {{-- STEP 4: Campioni e pubblico --}}
            <div x-show="step === 4" x-cloak>
                <h2 class="text-xl font-semibold text-gray-950">Esempi e pubblico</h2>
                <p class="mt-1 text-sm text-gray-500">Incolla 1-3 testi reali scritti da questo alter ego. L'AI li userà per calibrare la voce.</p>

                <div class="mt-6 space-y-5">
                    {{-- Ruolo pubblico --}}
                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Ruolo verso il pubblico</label>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <template x-for="r in audienceRoles" :key="r.key">
                                <button
                                    type="button"
                                    @click="form.audience_role = r.key"
                                    :class="form.audience_role === r.key ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-app hover:border-gray-400'"
                                    class="rounded-full border px-4 py-1.5 text-sm font-medium transition"
                                    x-text="r.label"
                                ></button>
                            </template>
                        </div>
                    </div>

                    {{-- CTA style --}}
                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Stile CTA</label>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <template x-for="c in ctaStyles" :key="c.key">
                                <button
                                    type="button"
                                    @click="form.cta_style = c.key"
                                    :class="form.cta_style === c.key ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-app hover:border-gray-400'"
                                    class="rounded-full border px-4 py-1.5 text-sm font-medium transition"
                                    x-text="c.label"
                                ></button>
                            </template>
                        </div>
                    </div>

                    {{-- Training samples + Analizza voce --}}
                    <div>
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700">Campioni di scrittura <span class="text-xs font-normal text-gray-400">(consigliato)</span></label>
                                <p class="text-xs text-gray-400">Incolla 1-3 testi reali. L'AI ne estrae voce, temi e frasi caratteristiche.</p>
                            </div>
                            <button
                                type="button"
                                @click="analyzeVoice"
                                :disabled="analyzingVoice || form.training_samples.filter(s => s.trim().length > 29).length === 0"
                                class="shrink-0 inline-flex items-center gap-1.5 rounded-2xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-40"
                            >
                                <svg x-show="analyzingVoice" class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/></svg>
                                <span x-text="analyzingVoice ? 'Analisi...' : 'Analizza la mia voce'"></span>
                            </button>
                        </div>
                        <div class="mt-2 space-y-2">
                            <template x-for="(sample, i) in form.training_samples" :key="i">
                                <div class="relative">
                                    <textarea
                                        x-model="form.training_samples[i]"
                                        rows="3"
                                        :placeholder="`Testo campione ${i + 1}`"
                                        class="block w-full rounded-2xl border border-app bg-app/30 px-4 py-2.5 pr-10 text-sm text-gray-950 placeholder-gray-400 focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"
                                    ></textarea>
                                    <button
                                        x-show="form.training_samples.length > 1"
                                        type="button"
                                        @click="form.training_samples.splice(i, 1)"
                                        class="absolute right-3 top-3 text-gray-400 hover:text-red-500"
                                    >
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                            </template>
                            <button
                                x-show="form.training_samples.length < 3"
                                type="button"
                                @click="form.training_samples.push('')"
                                class="flex items-center gap-1.5 text-xs font-semibold text-indigo-600 hover:text-indigo-800"
                            >
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                Aggiungi campione
                            </button>
                        </div>
                        {{-- Risultato analisi voce --}}
                        <div x-show="voiceExtractResult" x-cloak class="mt-3 rounded-2xl border border-indigo-200 bg-indigo-50 p-4 text-sm">
                            <p class="font-semibold text-indigo-900">Voce estratta — rivedi e applica</p>
                            <div class="mt-2 grid grid-cols-2 gap-2 text-xs">
                                <div><span class="text-gray-500">Tono:</span> <span class="font-medium" x-text="toneLabel(voiceExtractResult?.tone)"></span></div>
                                <div><span class="text-gray-500">Stile frasi:</span> <span class="font-medium" x-text="sentenceStyleLabel(voiceExtractResult?.sentence_style)"></span></div>
                                <div><span class="text-gray-500">Vocabolario:</span> <span class="font-medium" x-text="vocabLabel(voiceExtractResult?.vocabulary_level)"></span></div>
                                <div><span class="text-gray-500">Pubblico:</span> <span class="font-medium" x-text="audienceRoleLabel(voiceExtractResult?.audience_role)"></span></div>
                            </div>
                            <template x-if="voiceExtractResult?.signature_phrases?.length">
                                <p class="mt-2 text-xs text-indigo-700"><strong>Frasi:</strong> <span x-text="voiceExtractResult.signature_phrases.join(' · ')"></span></p>
                            </template>
                            <template x-if="voiceExtractResult?.unique_perspective">
                                <p class="mt-1 text-xs text-indigo-700"><strong>Prospettiva:</strong> <span x-text="voiceExtractResult.unique_perspective"></span></p>
                            </template>
                            <button
                                type="button"
                                @click="applyVoiceExtract"
                                class="mt-3 rounded-xl bg-indigo-600 px-4 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700"
                            >
                                Applica ai campi del wizard
                            </button>
                        </div>
                        <div x-show="voiceExtractError" x-cloak class="mt-3 rounded-2xl border border-red-200 bg-red-50 px-4 py-2.5 text-xs text-red-700" x-text="voiceExtractError"></div>
                    </div>
                </div>

                <div class="mt-8 flex justify-between">
                    <button @click="prevStep" class="rounded-2xl border border-app px-5 py-2.5 text-sm font-semibold text-gray-600 transition hover:bg-gray-50">
                        Indietro
                    </button>
                    <button @click="nextStep" class="rounded-2xl bg-slate-900 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">
                        Vedi anteprima
                    </button>
                </div>
            </div>

            {{-- STEP 5: Riepilogo e attivazione --}}
            <div x-show="step === 5" x-cloak>
                <h2 class="text-xl font-semibold text-gray-950">Attiva l'alter ego</h2>
                <p class="mt-1 text-sm text-gray-500">Rivedi e salva. Da questo momento l'AI genererà contenuti con questa voce.</p>

                <div class="mt-5 space-y-3">
                    {{-- Riepilogo --}}
                    <div class="rounded-2xl border border-app bg-app/40 p-4 space-y-3 text-sm">
                        <div class="flex items-start gap-3">
                            <span class="w-28 shrink-0 text-xs text-gray-400 font-medium pt-0.5">Nome</span>
                            <span class="font-semibold text-gray-900" x-text="form.name || '—'"></span>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="w-28 shrink-0 text-xs text-gray-400 font-medium pt-0.5">Archetipo</span>
                            <span class="text-gray-800" x-text="archetypeLabel(form.archetype)"></span>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="w-28 shrink-0 text-xs text-gray-400 font-medium pt-0.5">Voce</span>
                            <span class="text-gray-800" x-text="`${toneLabel(form.tone)} · ${sentenceStyleLabel(form.sentence_style)} · ${vocabLabel(form.vocabulary_level)}`"></span>
                        </div>
                        <template x-if="form.topics_owned.length > 0">
                            <div class="flex items-start gap-3">
                                <span class="w-28 shrink-0 text-xs text-gray-400 font-medium pt-0.5">Temi</span>
                                <span class="text-gray-800" x-text="form.topics_owned.slice(0, 5).join(', ')"></span>
                            </div>
                        </template>
                        <template x-if="form.unique_perspective">
                            <div class="flex items-start gap-3">
                                <span class="w-28 shrink-0 text-xs text-gray-400 font-medium pt-0.5">Prospettiva</span>
                                <span class="text-gray-700 line-clamp-2" x-text="form.unique_perspective"></span>
                            </div>
                        </template>
                        <template x-if="form.audience_role">
                            <div class="flex items-start gap-3">
                                <span class="w-28 shrink-0 text-xs text-gray-400 font-medium pt-0.5">Pubblico</span>
                                <span class="text-gray-800" x-text="audienceRoleLabel(form.audience_role)"></span>
                            </div>
                        </template>
                        <div class="flex items-start gap-3">
                            <span class="w-28 shrink-0 text-xs text-gray-400 font-medium pt-0.5">Campioni</span>
                            <span class="text-gray-600" x-text="`${form.training_samples.filter(s => s.trim()).length} testo/i`"></span>
                        </div>
                    </div>

                    {{-- Default toggle --}}
                    <label class="flex cursor-pointer items-center gap-3 rounded-2xl border border-app bg-app/30 px-4 py-3">
                        <input type="checkbox" x-model="form.is_default" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        <div>
                            <p class="text-sm font-semibold text-gray-900">Imposta come alter ego default</p>
                            <p class="text-xs text-gray-500">Verrà applicato automaticamente a ogni nuovo contenuto.</p>
                        </div>
                    </label>

                    {{-- Error --}}
                    <div x-show="errorMsg" x-cloak class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" x-text="errorMsg"></div>
                </div>

                <div class="mt-8 flex justify-between">
                    <button @click="prevStep" class="rounded-2xl border border-app px-5 py-2.5 text-sm font-semibold text-gray-600 transition hover:bg-gray-50">
                        Indietro
                    </button>
                    <button
                        @click="submit"
                        :disabled="saving"
                        class="inline-flex items-center gap-2 rounded-2xl bg-slate-900 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-50"
                    >
                        <svg x-show="saving" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/></svg>
                        <span x-text="saving ? 'Salvataggio...' : 'Attiva alter ego'"></span>
                    </button>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
const _alterEgoPrefill = @json($prefill ?? []);

function alterEgoWizard() {
    const _pf = _alterEgoPrefill;
    return {
        step: 1,
        saving: false,
        errorMsg: '',
        stepLabels: ['Identità', 'Voce', 'Temi', 'Campioni', 'Attiva'],

        form: {
            name:                 _pf.name                || '',
            archetype:            _pf.archetype           || '',
            tone:                 _pf.tone                || '',
            sentence_style:       _pf.sentence_style      || '',
            vocabulary_level:     _pf.vocabulary_level    || 'misto',
            signature_phrases:    Array.isArray(_pf.signature_phrases) ? _pf.signature_phrases : [],
            topics_owned:         Array.isArray(_pf.topics_owned)      ? _pf.topics_owned      : [],
            topics_avoided:       Array.isArray(_pf.topics_avoided)    ? _pf.topics_avoided    : [],
            unique_perspective:   _pf.unique_perspective  || '',
            audience_role:        _pf.audience_role       || '',
            cta_style:            _pf.cta_style           || 'soft',
            training_samples:     Array.isArray(_pf.training_samples) && _pf.training_samples.length ? _pf.training_samples : [''],
            platform_adaptations: (_pf.platform_adaptations && typeof _pf.platform_adaptations === 'object') ? _pf.platform_adaptations : {},
            is_default:           !!_pf.is_default,
        },

        analyzingVoice: false,
        voiceExtractResult: null,
        voiceExtractError: '',

        platformAdaptPlatforms: [
            { key: 'linkedin',  label: 'LinkedIn',  placeholder: 'Es. Più formale, linguaggio professionale, niente emoji' },
            { key: 'instagram', label: 'Instagram', placeholder: 'Es. Tono narrativo, emoji misurati, call to action soft' },
            { key: 'tiktok',    label: 'TikTok',    placeholder: 'Es. Energico, frasi corte, hook immediato' },
            { key: 'facebook',  label: 'Facebook',  placeholder: 'Es. Narrativo, domanda finale per coinvolgere' },
        ],

        archetypes: [
            { key: 'thought_leader', icon: '🎯', label: 'Thought Leader',  description: 'Guida e sfida il settore' },
            { key: 'educator',       icon: '📚', label: 'Educatore',        description: 'Insegna e semplifica' },
            { key: 'storyteller',    icon: '📖', label: 'Storyteller',      description: 'Racconta e connette' },
            { key: 'entertainer',    icon: '🎭', label: 'Entertainer',      description: 'Diverte e sorprende' },
            { key: 'provocateur',    icon: '🔥', label: 'Provocatore',      description: 'Sfida il pensiero comune' },
            { key: 'connector',      icon: '🤝', label: 'Connector',        description: 'Unisce la comunità' },
        ],

        tones: [
            { key: 'autorevole',  label: 'Autorevole'  },
            { key: 'diretto',     label: 'Diretto'     },
            { key: 'empatico',    label: 'Empatico'    },
            { key: 'ironico',     label: 'Ironico'     },
            { key: 'formale',     label: 'Formale'     },
            { key: 'colloquiale', label: 'Colloquiale' },
        ],

        sentenceStyles: [
            { key: 'brevi_incisive',    label: 'Brevi e incisive'   },
            { key: 'narrative',         label: 'Narrative'          },
            { key: 'domande_retoriche', label: 'Domande retoriche'  },
        ],

        vocabLevels: [
            { key: 'tecnico',     label: 'Tecnico'     },
            { key: 'accessibile', label: 'Accessibile' },
            { key: 'misto',       label: 'Misto'       },
        ],

        audienceRoles: [
            { key: 'teacher',     label: 'Teacher'     },
            { key: 'peer',        label: 'Peer'        },
            { key: 'mentor',      label: 'Mentor'      },
            { key: 'challenger',  label: 'Challenger'  },
            { key: 'entertainer', label: 'Entertainer' },
        ],

        ctaStyles: [
            { key: 'soft',    label: 'Soft'    },
            { key: 'direct',  label: 'Diretto' },
            { key: 'domanda', label: 'Domanda' },
            { key: 'nessuna', label: 'Nessuna' },
        ],

        nextStep() { this.step = Math.min(5, this.step + 1); },
        prevStep() { this.step = Math.max(1, this.step - 1); },

        addPhrase() {
            const val = this.$refs.phraseInput.value.trim().replace(/,$/, '');
            if (val && !this.form.signature_phrases.includes(val)) {
                this.form.signature_phrases.push(val);
            }
            this.$refs.phraseInput.value = '';
        },

        addTopicOwned() {
            const val = this.$refs.topicOwnedInput.value.trim().replace(/,$/, '');
            if (val && !this.form.topics_owned.includes(val)) {
                this.form.topics_owned.push(val);
            }
            this.$refs.topicOwnedInput.value = '';
        },

        addTopicAvoided() {
            const val = this.$refs.topicAvoidedInput.value.trim().replace(/,$/, '');
            if (val && !this.form.topics_avoided.includes(val)) {
                this.form.topics_avoided.push(val);
            }
            this.$refs.topicAvoidedInput.value = '';
        },

        archetypeLabel(key) {
            return (this.archetypes.find(a => a.key === key) || {}).label || key;
        },
        toneLabel(key) {
            return (this.tones.find(t => t.key === key) || {}).label || key;
        },
        sentenceStyleLabel(key) {
            return (this.sentenceStyles.find(s => s.key === key) || {}).label || key;
        },
        vocabLabel(key) {
            return (this.vocabLevels.find(v => v.key === key) || {}).label || key;
        },
        audienceRoleLabel(key) {
            return (this.audienceRoles.find(r => r.key === key) || {}).label || key;
        },

        async analyzeVoice() {
            const samples = this.form.training_samples.filter(s => s.trim().length > 29);
            if (samples.length === 0) return;

            this.analyzingVoice    = true;
            this.voiceExtractResult = null;
            this.voiceExtractError  = '';

            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            try {
                const res = await fetch('{{ route('alter-ego.extract-voice') }}', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ samples }),
                });
                const json = await res.json().catch(() => ({}));
                if (!res.ok) {
                    this.voiceExtractError = json.error || `Errore ${res.status}`;
                } else {
                    this.voiceExtractResult = json.profile || null;
                }
            } catch {
                this.voiceExtractError = 'Connessione non riuscita.';
            } finally {
                this.analyzingVoice = false;
            }
        },

        applyVoiceExtract() {
            if (!this.voiceExtractResult) return;
            const p = this.voiceExtractResult;
            if (p.tone)               this.form.tone               = p.tone;
            if (p.sentence_style)     this.form.sentence_style     = p.sentence_style;
            if (p.vocabulary_level)   this.form.vocabulary_level   = p.vocabulary_level;
            if (p.audience_role)      this.form.audience_role      = p.audience_role;
            if (p.signature_phrases?.length)  this.form.signature_phrases  = p.signature_phrases;
            if (p.topics_owned?.length)       this.form.topics_owned       = p.topics_owned;
            if (p.unique_perspective) this.form.unique_perspective = p.unique_perspective;
            this.voiceExtractResult = null;
        },

        async submit() {
            this.saving = true;
            this.errorMsg = '';

            const payload = {
                ...this.form,
                training_samples: this.form.training_samples.filter(s => s.trim() !== ''),
                platform_adaptations: Object.fromEntries(
                    Object.entries(this.form.platform_adaptations)
                        .filter(([, v]) => v?.modifier?.trim())
                ),
            };

            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            try {
                const res = await fetch('{{ route('alter-ego.store') }}', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify(payload),
                });

                const json = await res.json().catch(() => ({}));

                if (!res.ok) {
                    const errors = json.errors || {};
                    const first = Object.values(errors).flat()[0];
                    this.errorMsg = first || json.message || `Errore ${res.status}`;
                    return;
                }

                window.location.assign(json.redirect_url || '{{ route('alter-ego.index') }}');
            } catch (e) {
                this.errorMsg = 'Connessione non riuscita. Riprova.';
            } finally {
                this.saving = false;
            }
        },
    };
}
</script>
@endsection
