<x-app-layout>
    <div class="py-6">
        <div class="ui-container max-w-3xl">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="ui-h1">Wizard piano editoriale</h1>
                    <p class="ui-sub">Step 2: Brand kit (coerenza di stile e contenuto).</p>
                </div>
                <a href="{{ route('wizard.start') }}" class="ui-btn ui-btn-ghost">← Indietro</a>
            </div>

            <div class="mt-6 ui-card">
                <div class="ui-card-h">
                    <div class="ui-step">
                        <div class="ui-step-dot ui-step-dot-off">1</div>
                        <div class="ui-step-dot ui-step-dot-on">2</div>
                        <div class="ml-2">
                            <div class="text-sm font-semibold text-gray-900">Brand Kit</div>
                            <div class="text-xs text-gray-500">Dati base + stile comunicativo</div>
                        </div>
                    </div>
                </div>

                <div class="ui-card-b">
                    <form method="POST" action="{{ route('wizard.brand.store') }}" class="space-y-4">
                        @csrf

                        <div>
                            <label class="ui-label">Nome attività</label>
                            <input class="ui-input" name="business_name" value="{{ old('business_name', $defaults['business_name']) }}" />
                            @error('business_name') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <label class="ui-label">Settore / nicchia</label>
                            <input class="ui-input" name="industry" value="{{ old('industry', $defaults['industry']) }}" />
                            @error('industry') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <label class="ui-label">Servizi / prodotti principali</label>
                            <textarea class="ui-input" name="services" rows="3">{{ old('services', $defaults['services']) }}</textarea>
                            @error('services') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="ui-label">Target</label>
                                <input class="ui-input" name="target" value="{{ old('target', $defaults['target']) }}" />
                                @error('target') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                            </div>
                            <div>
                                <label class="ui-label">Area geografica (opzionale)</label>
                                <input class="ui-input" name="geo" value="{{ old('geo', $defaults['geo']) }}" />
                            </div>
                        </div>

                        <div>
                            <label class="ui-label">CTA principale</label>
                            <input class="ui-input" name="cta" value="{{ old('cta', $defaults['cta']) }}" />
                            @error('cta') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="ui-label">Parole chiave (opzionale)</label>
                                <textarea class="ui-input" name="keywords" rows="2">{{ old('keywords', $defaults['keywords']) }}</textarea>
                            </div>
                            <div>
                                <label class="ui-label">Cosa evitare (opzionale)</label>
                                <textarea class="ui-input" name="avoid" rows="2">{{ old('avoid', $defaults['avoid']) }}</textarea>
                            </div>
                        </div>

                        <div class="flex flex-col sm:flex-row gap-2 pt-2">
                            <button type="submit" class="ui-btn ui-btn-primary">Genera bozze</button>
                            <a href="{{ route('calendar') }}" class="ui-btn ui-btn-ghost">Annulla</a>
                        </div>

                        <div class="pt-2 text-xs text-gray-500">
                            Prossimo step: integriamo OpenAI e generiamo caption+hashtag reali partendo da questi campi.
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
