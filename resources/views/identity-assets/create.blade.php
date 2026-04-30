@extends('layouts.app')

@section('content')
<div
    x-data="identityWizard()"
    class="mx-auto w-full max-w-3xl px-4 py-8 sm:px-6"
>
    {{-- Progress --}}
    <div class="mb-6">
        <div class="mb-2 flex items-center justify-between text-xs" style="color:#64748b;">
            <span x-text="`Passo ${step} di 3`"></span>
            <span class="font-semibold" style="color:#0A2D6F;" x-text="stepLabels[step - 1]"></span>
        </div>
        <div class="h-1.5 overflow-hidden rounded-full" style="background:rgba(10,45,111,0.08);">
            <div
                class="h-full rounded-full transition-[width] duration-300"
                style="background:linear-gradient(90deg,#0A2D6F,#3BC8FF);"
                :style="`width: ${(step / 3) * 100}%`"
            ></div>
        </div>
    </div>

    <div class="overflow-hidden rounded-[2rem] border shadow-[0_24px_80px_rgba(15,23,42,0.08)]" style="border-color:rgba(10,45,111,0.12);background:#fff;">
        <div class="h-1.5" style="background:linear-gradient(90deg,#0A2D6F,#3BC8FF);"></div>

        <div class="p-6 sm:p-8">

            {{-- STEP 1: Tipo e dati base --}}
            <div x-show="step === 1" x-cloak>
                <h2 class="text-xl font-semibold" style="color:#0A2D6F;">Che tipo di identità è?</h2>
                <p class="mt-1 text-sm" style="color:#64748b;">Scegli il tipo di soggetto e dagli un nome.</p>

                <div class="mt-6 space-y-5">
                    {{-- Tipo --}}
                    <div>
                        <label class="block text-sm font-semibold" style="color:#0A2D6F;">Tipo <span class="text-red-500">*</span></label>
                        <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <template x-for="t in types" :key="t.key">
                                <button
                                    type="button"
                                    @click="form.type = t.key"
                                    :class="form.type === t.key ? 'ring-2' : 'hover:border-gray-300'"
                                    :style="form.type === t.key ? 'border-color:#0A2D6F;background:rgba(239,245,255,0.98);ring-color:#3BC8FF;' : 'border-color:rgba(10,45,111,0.12);background:#fff;'"
                                    class="flex flex-col items-start rounded-2xl border p-3.5 text-left transition"
                                >
                                    <span class="text-xl" x-text="t.icon"></span>
                                    <span class="mt-1.5 text-sm font-semibold" style="color:#0A2D6F;" x-text="t.label"></span>
                                    <span class="mt-0.5 text-xs" style="color:#64748b;" x-text="t.desc"></span>
                                </button>
                            </template>
                        </div>
                    </div>

                    {{-- Nome --}}
                    <div>
                        <label class="ui-label">Nome <span class="text-red-500">*</span></label>
                        <input
                            type="text"
                            x-model="form.name"
                            placeholder="Es. Marco Rossi, iPhone 15 Pro, Villa Rosa, Logo Principale"
                            class="ui-input"
                        />
                    </div>

                    {{-- Descrizione --}}
                    <div>
                        <label class="ui-label">Descrizione <span class="text-xs font-normal" style="color:#94a3b8;">(opzionale)</span></label>
                        <textarea
                            x-model="form.description"
                            rows="3"
                            placeholder="Breve descrizione di chi/cosa è questo soggetto e come viene usato..."
                            class="ui-input resize-none"
                        ></textarea>
                    </div>

                    {{-- Lock --}}
                    <label class="flex cursor-pointer items-start gap-3 rounded-2xl border p-4 transition" style="border-color:rgba(10,45,111,0.12);" :style="form.locked ? 'background:rgba(239,245,255,0.98);border-color:#0A2D6F;' : ''">
                        <input type="checkbox" x-model="form.locked" class="mt-0.5 h-4 w-4 accent-[#0A2D6F]" />
                        <div>
                            <p class="text-sm font-semibold" style="color:#0A2D6F;">
                                <svg class="mb-0.5 mr-1 inline h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                Identità visiva lockata
                            </p>
                            <p class="mt-0.5 text-xs" style="color:#64748b;">L'IdentityGuard bloccherà le generazioni che non rispettano le regole visive definite. Consigliato per persone reali e prodotti di punta.</p>
                        </div>
                    </label>
                </div>

                <div class="mt-8 flex justify-end">
                    <button
                        @click="nextStep"
                        :disabled="!form.name || !form.type"
                        class="ui-btn-primary disabled:opacity-40"
                    >
                        Continua
                    </button>
                </div>
            </div>

            {{-- STEP 2: Caratteristiche visive --}}
            <div x-show="step === 2" x-cloak>
                <h2 class="text-xl font-semibold" style="color:#0A2D6F;">Caratteristiche visive invarianti</h2>
                <p class="mt-1 text-sm" style="color:#64748b;">Definisci i tratti visivi che devono rimanere costanti in ogni generazione.</p>

                {{-- Features dinamiche per tipo --}}
                <div class="mt-6 space-y-4">
                    <template x-for="field in featureFields" :key="field.key">
                        <div>
                            <label class="ui-label" x-text="field.label"></label>
                            <input
                                type="text"
                                :placeholder="field.placeholder"
                                :value="form.visual_features[field.key] ?? ''"
                                @input="form.visual_features[field.key] = $event.target.value"
                                class="ui-input"
                            />
                        </div>
                    </template>

                    {{-- Usage rules --}}
                    <div class="rounded-2xl border p-4" style="border-color:rgba(10,45,111,0.12);background:rgba(239,245,255,0.4);">
                        <h3 class="mb-3 text-sm font-semibold" style="color:#0A2D6F;">Regole di composizione</h3>
                        <div class="space-y-3">
                            <div>
                                <label class="ui-label text-xs">Angolo preferito</label>
                                <input type="text" x-model="form.usage_rules.preferred_angle" placeholder="Es. three_quarter, frontale, profilo 3/4" class="ui-input" />
                            </div>
                            <div>
                                <label class="ui-label text-xs">Evita sempre</label>
                                <input type="text" x-model="form.usage_rules.avoid" placeholder="Es. profile view, occhi chiusi, sfondo caotico" class="ui-input" />
                            </div>
                            <div>
                                <label class="ui-label text-xs">Includi sempre</label>
                                <input type="text" x-model="form.usage_rules.always_include" placeholder="Es. prodotto visibile, logo in vista, mani a fuoco" class="ui-input" />
                            </div>
                            <template x-if="form.type === 'person'">
                                <label class="flex cursor-pointer items-center gap-2 text-sm" style="color:#0A2D6F;">
                                    <input type="checkbox" x-model="form.usage_rules.always_show_face" class="h-4 w-4 accent-[#0A2D6F]" />
                                    Mostra sempre il viso chiaramente riconoscibile
                                </label>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="mt-8 flex justify-between">
                    <button @click="step = 1" class="ui-btn-secondary">
                        Indietro
                    </button>
                    <button @click="nextStep" class="ui-btn-primary">
                        Continua
                    </button>
                </div>
            </div>

            {{-- STEP 3: Riepilogo e salvataggio --}}
            <div x-show="step === 3" x-cloak>
                <h2 class="text-xl font-semibold" style="color:#0A2D6F;">Riepilogo</h2>
                <p class="mt-1 text-sm" style="color:#64748b;">Controlla i dati e crea l'identità. Potrai aggiungere i media di riferimento subito dopo.</p>

                <div class="mt-6 space-y-3">
                    <div class="rounded-2xl border p-4" style="border-color:rgba(10,45,111,0.12);background:rgba(239,245,255,0.4);">
                        <div class="flex items-start gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl text-xl" style="background:linear-gradient(135deg,#0A2D6F,#1498F3);">
                                <span x-text="types.find(t => t.key === form.type)?.icon ?? '🔷'"></span>
                            </div>
                            <div class="flex-1">
                                <p class="font-semibold" style="color:#0A2D6F;" x-text="form.name"></p>
                                <p class="text-xs" style="color:#64748b;" x-text="types.find(t => t.key === form.type)?.label ?? ''"></p>
                                <p class="mt-1 text-xs" style="color:#64748b;" x-show="form.description" x-text="form.description"></p>
                            </div>
                            <template x-if="form.locked">
                                <span class="ui-badge" style="border-color:rgba(10,45,111,0.14);background:rgba(10,45,111,0.06);color:#0A2D6F;font-size:11px;">
                                    🔒 Locked
                                </span>
                            </template>
                        </div>
                    </div>

                    <div class="rounded-2xl border p-4" style="border-color:rgba(10,45,111,0.12);">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide" style="color:#0A2D6F;letter-spacing:.1em;">Caratteristiche visive</p>
                        <template x-if="Object.values(form.visual_features).filter(v => v !== '').length > 0">
                            <ul class="space-y-1">
                                <template x-for="field in featureFields.filter(f => form.visual_features[f.key])" :key="field.key">
                                    <li class="flex items-center gap-2 text-xs" style="color:#475569;">
                                        <span class="font-medium" x-text="field.label + ':'"></span>
                                        <span x-text="form.visual_features[field.key]"></span>
                                    </li>
                                </template>
                            </ul>
                        </template>
                        <template x-if="Object.values(form.visual_features).filter(v => v !== '').length === 0">
                            <p class="text-xs" style="color:#94a3b8;">Nessuna caratteristica definita — potrai aggiungerle in modifica.</p>
                        </template>
                    </div>
                </div>

                <template x-if="error !== ''">
                    <div class="mt-4 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" x-text="error"></div>
                </template>

                <div class="mt-8 flex justify-between">
                    <button @click="step = 2" class="ui-btn-secondary">
                        Indietro
                    </button>
                    <button @click="submit" :disabled="saving" class="ui-btn-primary gap-2 disabled:opacity-50">
                        <template x-if="saving">
                            <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                        </template>
                        <span x-text="saving ? 'Creazione...' : 'Crea identità'"></span>
                    </button>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
function identityWizard() {
    return {
        step: 1,
        saving: false,
        error: '',
        stepLabels: ['Tipo e nome', 'Caratteristiche visive', 'Riepilogo'],
        form: {
            type: '',
            name: '',
            description: '',
            locked: false,
            visual_features: {},
            usage_rules: { preferred_angle: '', avoid: '', always_include: '', always_show_face: false },
        },
        types: [
            { key: 'person',       icon: '👤', label: 'Persona',       desc: 'Una persona reale' },
            { key: 'product',      icon: '📦', label: 'Prodotto',      desc: 'Prodotto o item' },
            { key: 'location',     icon: '📍', label: 'Location',      desc: 'Luogo o ambiente' },
            { key: 'brand_object', icon: '✦',  label: 'Brand Object',  desc: 'Logo, oggetto simbolo' },
        ],
        featureFieldsMap: {
            person:       [
                { key: 'hair',       label: 'Capelli',         placeholder: 'Es. castani lisci, corti' },
                { key: 'skin',       label: 'Carnagione',      placeholder: 'Es. chiara, mediterranea, scura' },
                { key: 'eyes',       label: 'Occhi',           placeholder: 'Es. verdi, marroni, azzurri' },
                { key: 'build',      label: 'Corporatura',     placeholder: 'Es. atletica, snella, media' },
                { key: 'age_range',  label: 'Fascia d\'età',   placeholder: 'Es. 30-40 anni' },
                { key: 'style',      label: 'Stile',           placeholder: 'Es. business casual, sportivo' },
            ],
            product:      [
                { key: 'color',         label: 'Colore principale', placeholder: 'Es. bianco lucido, space gray' },
                { key: 'shape',         label: 'Forma',             placeholder: 'Es. rettangolare, cilindrica' },
                { key: 'material',      label: 'Materiale',         placeholder: 'Es. alluminio, vetro, pelle' },
                { key: 'logo_position', label: 'Posizione logo',    placeholder: 'Es. centro, angolo in basso a destra' },
                { key: 'finish',        label: 'Finitura',          placeholder: 'Es. opaca, lucida, satinata' },
            ],
            location:     [
                { key: 'architecture',         label: 'Architettura',      placeholder: 'Es. industriale, moderna, rustica' },
                { key: 'color_palette',        label: 'Palette colore',    placeholder: 'Es. bianco e legno, grigio e verde' },
                { key: 'distinctive_elements', label: 'Elementi distintivi', placeholder: 'Es. finestre alte, insegna rossa' },
                { key: 'lighting',             label: 'Illuminazione',     placeholder: 'Es. naturale ampia, soffusa calda' },
            ],
            brand_object: [
                { key: 'color',    label: 'Colore',    placeholder: 'Es. blu navy e oro' },
                { key: 'shape',    label: 'Forma',     placeholder: 'Es. circolare, shield' },
                { key: 'material', label: 'Materiale', placeholder: 'Es. vettoriale, dorato' },
            ],
        },
        get featureFields() {
            return this.featureFieldsMap[this.form.type] ?? [];
        },
        nextStep() {
            if (this.step < 3) this.step++;
        },
        buildPayload() {
            // Filtra campi visivi vuoti
            const vf = {};
            for (const [k, v] of Object.entries(this.form.visual_features)) {
                if (v && v.trim() !== '') vf[k] = v.trim();
            }
            // Filtra usage rules vuote
            const ur = {};
            for (const [k, v] of Object.entries(this.form.usage_rules)) {
                if (v !== '' && v !== false && v !== null) ur[k] = v;
            }
            return {
                name:                   this.form.name,
                type:                   this.form.type,
                description:            this.form.description,
                locked_visual_identity: this.form.locked ? 1 : 0,
                visual_features_json:   Object.keys(vf).length > 0 ? vf : null,
                usage_rules_json:       Object.keys(ur).length > 0 ? ur : null,
                _token:                 document.querySelector('meta[name="csrf-token"]').content,
            };
        },
        async submit() {
            this.saving = true;
            this.error = '';
            try {
                const res = await fetch('{{ route("identity-assets.store") }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify(this.buildPayload()),
                });
                const json = await res.json();
                if (json.ok && json.redirect_url) {
                    window.location.href = json.redirect_url;
                } else {
                    this.error = json.message ?? 'Errore durante il salvataggio.';
                    this.saving = false;
                }
            } catch (e) {
                this.error = 'Errore di rete. Riprova.';
                this.saving = false;
            }
        },
    };
}
</script>
@endsection
