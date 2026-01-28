<x-app-layout>
    <div class="py-6">
        <div class="ui-container max-w-3xl">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="ui-h1">Wizard piano editoriale</h1>
                    <p class="ui-sub">Step 1: setup frequenza, piattaforme e settimana di partenza.</p>
                </div>
                <a href="{{ route('calendar') }}" class="ui-btn ui-btn-ghost">← Calendario</a>
            </div>

            <div class="mt-6 ui-card">
                <div class="ui-card-h">
                    <div class="ui-step">
                        <div class="ui-step-dot ui-step-dot-on">1</div>
                        <div class="ui-step-dot ui-step-dot-off">2</div>
                        <div class="ml-2">
                            <div class="text-sm font-semibold text-gray-900">Setup</div>
                            <div class="text-xs text-gray-500">Preferenze piano</div>
                        </div>
                    </div>
                </div>

                <div class="ui-card-b">
                    <form method="POST" action="{{ route('wizard.store') }}" class="space-y-4">
                        @csrf

                        <div>
                            <label class="ui-label">Nome piano</label>
                            <input class="ui-input" name="name" value="{{ old('name', $defaults['name']) }}" />
                            @error('name') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="ui-label">Obiettivo</label>
                                @php $goal = old('goal', $defaults['goal']); @endphp
                                <select class="ui-input" name="goal">
                                    <option value="lead" @selected($goal==='lead')>Lead / contatti</option>
                                    <option value="brand" @selected($goal==='brand')>Brand awareness</option>
                                    <option value="sales" @selected($goal==='sales')>Vendite</option>
                                </select>
                            </div>

                            <div>
                                <label class="ui-label">Tono</label>
                                @php $tone = old('tone', $defaults['tone']); @endphp
                                <select class="ui-input" name="tone">
                                    <option value="professionale" @selected($tone==='professionale')>Professionale</option>
                                    <option value="amichevole" @selected($tone==='amichevole')>Amichevole</option>
                                    <option value="ironico" @selected($tone==='ironico')>Ironico</option>
                                    <option value="premium" @selected($tone==='premium')>Premium</option>
                                </select>
                            </div>

                            <div>
                                <label class="ui-label">Piattaforme</label>
                                @php $plats = old('platforms', $defaults['platforms']); @endphp
                                <div class="mt-2 space-y-2">
                                    <label class="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" name="platforms[]" value="instagram" class="rounded border-gray-300"
                                               @checked(in_array('instagram', $plats))>
                                        Instagram
                                    </label>
                                    <label class="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" name="platforms[]" value="facebook" class="rounded border-gray-300"
                                               @checked(in_array('facebook', $plats))>
                                        Facebook
                                    </label>
                                    <label class="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" name="platforms[]" value="tiktok" class="rounded border-gray-300"
                                               @checked(in_array('tiktok', $plats))>
                                        TikTok
                                    </label>
                                </div>
                                @error('platforms') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                            </div>

                            <div>
                                <label class="ui-label">Formati</label>
                                @php $fmts = old('formats', $defaults['formats']); @endphp
                                <div class="mt-2 space-y-2">
                                    <label class="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" name="formats[]" value="post" class="rounded border-gray-300"
                                               @checked(in_array('post', $fmts))>
                                        Post
                                    </label>
                                    <label class="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" name="formats[]" value="reel" class="rounded border-gray-300"
                                               @checked(in_array('reel', $fmts))>
                                        Reel
                                    </label>
                                    <label class="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" name="formats[]" value="story" class="rounded border-gray-300"
                                               @checked(in_array('story', $fmts))>
                                        Story
                                    </label>
                                </div>
                                @error('formats') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                            </div>

                            <div>
                                <label class="ui-label">Post a settimana</label>
                                <input class="ui-input" type="number" min="1" max="14" name="posts_per_week"
                                       value="{{ old('posts_per_week', $defaults['posts_per_week']) }}" />
                            </div>

                            <div>
                                <label class="ui-label">Settimana di partenza</label>
                                <input class="ui-input" type="date" name="start_date"
                                       value="{{ old('start_date', $defaults['start_date']) }}" />
                            </div>
                        </div>

                        <div class="flex flex-col sm:flex-row gap-2 pt-2">
                            <button type="submit" class="ui-btn ui-btn-primary">Avanti</button>
                            <a href="{{ route('calendar') }}" class="ui-btn ui-btn-ghost">Annulla</a>
                        </div>

                        <div class="pt-2 text-xs text-gray-500">
                            Nel prossimo step inserisci i dati del Brand Kit (servono poi per l’AI).
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
