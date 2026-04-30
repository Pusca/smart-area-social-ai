@extends('layouts.app')

@section('content')
<div
    x-data="identityEdit()"
    class="mx-auto w-full max-w-3xl px-4 py-8 sm:px-6"
>
    <div class="mb-6">
        <a href="{{ route('identity-assets.show', $identityAsset) }}" class="inline-flex items-center gap-1 text-xs" style="color:#64748b;">
            <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            {{ $identityAsset->name }}
        </a>
        <h1 class="ui-h1 mt-2">Modifica identità</h1>
    </div>

    <form method="POST" action="{{ route('identity-assets.update', $identityAsset) }}" class="space-y-5">
        @csrf @method('PUT')

        <div class="ui-card space-y-5">
            <h2 class="text-sm font-semibold" style="color:#0A2D6F;">Dati base</h2>

            <div>
                <label class="ui-label">Tipo</label>
                <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                    @foreach(['person' => ['👤','Persona'], 'product' => ['📦','Prodotto'], 'location' => ['📍','Location'], 'brand_object' => ['✦','Brand Object']] as $typeKey => [$icon, $label])
                    <label class="flex cursor-pointer flex-col items-start rounded-2xl border p-3 transition" :style="form.type === '{{ $typeKey }}' ? 'border-color:#0A2D6F;background:rgba(239,245,255,0.98);' : 'border-color:rgba(10,45,111,0.12);'">
                        <input type="radio" name="type" value="{{ $typeKey }}" x-model="form.type" class="sr-only">
                        <span class="text-xl">{{ $icon }}</span>
                        <span class="mt-1 text-sm font-semibold" style="color:#0A2D6F;">{{ $label }}</span>
                    </label>
                    @endforeach
                </div>
            </div>

            <div>
                <label class="ui-label">Nome <span class="text-red-500">*</span></label>
                <input type="text" name="name" x-model="form.name" required class="ui-input" value="{{ old('name', $identityAsset->name) }}">
                @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="ui-label">Descrizione</label>
                <textarea name="description" rows="3" class="ui-input resize-none">{{ old('description', $identityAsset->description) }}</textarea>
            </div>

            <label class="flex cursor-pointer items-start gap-3 rounded-2xl border p-4 transition" style="border-color:rgba(10,45,111,0.12);" :style="form.locked ? 'background:rgba(239,245,255,0.98);border-color:#0A2D6F;' : ''">
                <input type="hidden" name="locked_visual_identity" value="0">
                <input type="checkbox" name="locked_visual_identity" value="1" x-model="form.locked" {{ old('locked_visual_identity', $identityAsset->locked_visual_identity) ? 'checked' : '' }} class="mt-0.5 h-4 w-4 accent-[#0A2D6F]">
                <div>
                    <p class="text-sm font-semibold" style="color:#0A2D6F;">Identità visiva lockata</p>
                    <p class="mt-0.5 text-xs" style="color:#64748b;">L'IdentityGuard bloccherà le generazioni che non rispettano le regole visive.</p>
                </div>
            </label>
        </div>

        {{-- Caratteristiche visive --}}
        <div class="ui-card space-y-4">
            <h2 class="text-sm font-semibold" style="color:#0A2D6F;">Caratteristiche visive invarianti</h2>
            <p class="text-xs" style="color:#64748b;">I tratti che devono rimanere costanti in ogni generazione.</p>

            <div class="grid gap-3 sm:grid-cols-2">
                <template x-for="field in featureFields" :key="field.key">
                    <div>
                        <label class="ui-label text-xs" x-text="field.label"></label>
                        <input
                            type="text"
                            :name="`visual_features_json[${field.key}]`"
                            :placeholder="field.placeholder"
                            :value="form.visual_features[field.key] ?? ''"
                            @input="form.visual_features[field.key] = $event.target.value"
                            class="ui-input"
                        />
                    </div>
                </template>
            </div>
        </div>

        {{-- Regole di utilizzo --}}
        <div class="ui-card space-y-4">
            <h2 class="text-sm font-semibold" style="color:#0A2D6F;">Regole di composizione</h2>

            <div class="grid gap-3 sm:grid-cols-2">
                @foreach(['preferred_angle' => 'Angolo preferito', 'avoid' => 'Evita sempre', 'always_include' => 'Includi sempre', 'background_style' => 'Stile sfondo', 'lighting' => 'Illuminazione'] as $ruleKey => $ruleLabel)
                <div>
                    <label class="ui-label text-xs">{{ $ruleLabel }}</label>
                    <input
                        type="text"
                        name="usage_rules_json[{{ $ruleKey }}]"
                        value="{{ old('usage_rules_json.'.$ruleKey, data_get($identityAsset->usage_rules_json, $ruleKey, '')) }}"
                        class="ui-input"
                    />
                </div>
                @endforeach
            </div>

            <template x-if="form.type === 'person'">
                <label class="flex cursor-pointer items-center gap-2 text-sm" style="color:#0A2D6F;">
                    <input type="hidden" name="usage_rules_json[always_show_face]" value="0">
                    <input
                        type="checkbox"
                        name="usage_rules_json[always_show_face]"
                        value="1"
                        {{ data_get($identityAsset->usage_rules_json, 'always_show_face') ? 'checked' : '' }}
                        class="h-4 w-4 accent-[#0A2D6F]"
                    >
                    Mostra sempre il viso chiaramente riconoscibile
                </label>
            </template>
        </div>

        @if($errors->any())
            <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <ul class="list-inside list-disc space-y-1">
                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <div class="flex items-center justify-between">
            <a href="{{ route('identity-assets.show', $identityAsset) }}" class="ui-btn-secondary">Annulla</a>
            <button type="submit" class="ui-btn-primary">Salva modifiche</button>
        </div>
    </form>
</div>

<script>
function identityEdit() {
    const existing = @json($identityAsset->visual_features_json ?? []);
    const featureMap = {
        person:       [
            { key: 'hair',       label: 'Capelli',         placeholder: 'Es. castani lisci' },
            { key: 'skin',       label: 'Carnagione',      placeholder: 'Es. mediterranea' },
            { key: 'eyes',       label: 'Occhi',           placeholder: 'Es. verdi' },
            { key: 'build',      label: 'Corporatura',     placeholder: 'Es. atletica' },
            { key: 'age_range',  label: "Fascia d'età",    placeholder: 'Es. 30-40 anni' },
            { key: 'style',      label: 'Stile',           placeholder: 'Es. business casual' },
        ],
        product:      [
            { key: 'color',         label: 'Colore',           placeholder: 'Es. bianco lucido' },
            { key: 'shape',         label: 'Forma',            placeholder: 'Es. rettangolare' },
            { key: 'material',      label: 'Materiale',        placeholder: 'Es. alluminio' },
            { key: 'logo_position', label: 'Posizione logo',   placeholder: 'Es. centro' },
            { key: 'finish',        label: 'Finitura',         placeholder: 'Es. opaca' },
        ],
        location:     [
            { key: 'architecture',         label: 'Architettura',       placeholder: 'Es. moderna' },
            { key: 'color_palette',        label: 'Palette colore',     placeholder: 'Es. bianco e legno' },
            { key: 'distinctive_elements', label: 'Elementi distintivi', placeholder: 'Es. finestre alte' },
            { key: 'lighting',             label: 'Illuminazione',      placeholder: 'Es. naturale ampia' },
        ],
        brand_object: [
            { key: 'color',    label: 'Colore',    placeholder: 'Es. blu navy e oro' },
            { key: 'shape',    label: 'Forma',     placeholder: 'Es. circolare' },
            { key: 'material', label: 'Materiale', placeholder: 'Es. vettoriale' },
        ],
    };
    return {
        form: {
            type: '{{ $identityAsset->type }}',
            name: '{{ $identityAsset->name }}',
            locked: {{ $identityAsset->locked_visual_identity ? 'true' : 'false' }},
            visual_features: { ...existing },
        },
        get featureFields() {
            return featureMap[this.form.type] ?? [];
        },
    };
}
</script>
@endsection
