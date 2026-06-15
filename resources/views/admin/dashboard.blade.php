@extends('layouts.admin')

@section('content')
@php
    $stats = is_array($stats ?? null) ? $stats : [];
@endphp

<datalist id="common-plan-options">
    @foreach(($commonPlans ?? []) as $planOption)
        <option value="{{ $planOption }}"></option>
    @endforeach
</datalist>

<section class="space-y-6">
    <div class="overflow-hidden rounded-3xl border border-gray-200 bg-gradient-to-br from-white via-indigo-50/50 to-cyan-50/50 p-6 shadow-sm lg:p-8">
        <div class="grid gap-4 lg:grid-cols-[1.2fr_0.8fr] lg:items-center">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Platform Admin</div>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">Controllo account e tenant</h1>
                <p class="mt-2 max-w-2xl text-sm text-gray-600">
                    Da qui puoi entrare nei workspace, staccare tenant dagli utenti, disattivare account tenant e imporre limiti reali su utenti e contenuti.
                </p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Snapshot</div>
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                        <div class="text-[11px] text-gray-500">Utenti</div>
                        <div class="text-lg font-semibold text-gray-900">{{ (int) ($stats['users_total'] ?? 0) }}</div>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                        <div class="text-[11px] text-gray-500">Tenant</div>
                        <div class="text-lg font-semibold text-gray-900">{{ (int) ($stats['tenants_total'] ?? 0) }}</div>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                        <div class="text-[11px] text-gray-500">Tenant over user limit</div>
                        <div class="text-lg font-semibold {{ ((int) ($stats['tenants_users_over_limit'] ?? 0)) > 0 ? 'text-amber-700' : 'text-gray-900' }}">{{ (int) ($stats['tenants_users_over_limit'] ?? 0) }}</div>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                        <div class="text-[11px] text-gray-500">Tenant over content limit</div>
                        <div class="text-lg font-semibold {{ ((int) ($stats['tenants_content_over_limit'] ?? 0)) > 0 ? 'text-amber-700' : 'text-gray-900' }}">{{ (int) ($stats['tenants_content_over_limit'] ?? 0) }}</div>
                    </div>
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
            <ul class="mt-2 list-disc pl-5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-6">
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm xl:col-span-2">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Completamento AI globale</div>
            <div class="mt-2 text-2xl font-semibold text-gray-900">{{ (int) ($stats['ai_completion'] ?? 0) }}%</div>
            <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                <div class="h-full rounded-full bg-indigo-600" style="width: {{ (int) ($stats['ai_completion'] ?? 0) }}%"></div>
            </div>
        </article>
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Tenant attivi</div>
            <div class="mt-2 text-2xl font-semibold text-gray-900">{{ (int) ($stats['tenants_active_recently'] ?? 0) }}</div>
            <p class="mt-1 text-xs text-gray-600">Con uso recente</p>
        </article>
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Brand pronti</div>
            <div class="mt-2 text-2xl font-semibold text-gray-900">{{ (int) ($stats['tenants_brand_ready'] ?? 0) }}</div>
            <p class="mt-1 text-xs text-gray-600">Profilo completato</p>
        </article>
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Trend brief freschi</div>
            <div class="mt-2 text-2xl font-semibold text-gray-900">{{ (int) ($stats['tenants_with_fresh_trend_brief'] ?? 0) }}</div>
            <p class="mt-1 text-xs text-gray-600">Tenant con brief trend aggiornato</p>
        </article>
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Utenti senza tenant</div>
            <div class="mt-2 text-2xl font-semibold {{ ((int) ($stats['users_without_tenant'] ?? 0)) > 0 ? 'text-amber-700' : 'text-gray-900' }}">{{ (int) ($stats['users_without_tenant'] ?? 0) }}</div>
            <p class="mt-1 text-xs text-gray-600">Bloccati fuori dal workspace</p>
        </article>
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Piani</div>
            <div class="mt-2 text-2xl font-semibold text-gray-900">{{ (int) ($stats['plans_total'] ?? 0) }}</div>
            <p class="mt-1 text-xs text-gray-600">Workspace globali</p>
        </article>
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Contenuti</div>
            <div class="mt-2 text-2xl font-semibold text-gray-900">{{ (int) ($stats['contents_total'] ?? 0) }}</div>
            <p class="mt-1 text-xs text-gray-600">Archivio totale</p>
        </article>
    </div>

    <div class="grid gap-6 xl:grid-cols-[1.4fr_1fr]">
        <div class="space-y-6">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Utenti registrati</h2>
                        <p class="mt-1 text-sm text-gray-600">Azioni rapide per entrare nel workspace, cambiare ruolo o staccare il tenant.</p>
                    </div>
                    <span class="rounded-full border border-gray-200 bg-gray-50 px-3 py-1 text-xs font-semibold text-gray-700">
                        {{ $managedUsers->count() }} utenti
                    </span>
                </div>

                <div class="mt-4 space-y-3">
                    @forelse($managedUsers as $user)
                        <article class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-gray-900">{{ $user->name ?: 'Utente senza nome' }}</p>
                                    <p class="truncate text-xs text-gray-600">{{ $user->email }}</p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <span class="rounded-full border border-gray-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-gray-700">role: {{ $user->role ?: 'editor' }}</span>
                                    <span class="rounded-full border {{ $user->tenant_id ? 'border-indigo-200 bg-indigo-50 text-indigo-700' : 'border-amber-200 bg-amber-50 text-amber-700' }} px-2.5 py-1 text-[11px] font-semibold">
                                        tenant: {{ $user->tenant?->name ?: 'non assegnato' }}
                                    </span>
                                </div>
                            </div>

                            <div class="mt-3 flex flex-wrap gap-2">
                                @if($user->tenant_id)
                                    <form method="POST" action="{{ route('admin.users.impersonate', $user) }}">
                                        @csrf
                                        <button type="submit" class="ui-btn-primary">Entra nel workspace</button>
                                    </form>

                                    <form method="POST" action="{{ route('admin.users.tenant.update', $user) }}">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="tenant_action" value="detach" />
                                        <input type="hidden" name="role" value="{{ in_array($user->role, ['owner', 'editor'], true) ? $user->role : 'editor' }}" />
                                        <button type="submit" class="ui-btn-secondary">Scollega tenant</button>
                                    </form>
                                @endif
                            </div>

                            <form method="POST" action="{{ route('admin.users.tenant.update', $user) }}" class="mt-4 grid gap-3 lg:grid-cols-4">
                                @csrf
                                @method('PUT')

                                <select name="tenant_action" class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                    <option value="existing">Assegna tenant esistente</option>
                                    <option value="new">Crea nuovo tenant</option>
                                    <option value="detach">Scollega tenant</option>
                                </select>

                                <select name="tenant_id" class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                    <option value="">Seleziona tenant</option>
                                    @foreach($tenants as $tenant)
                                        <option value="{{ $tenant->id }}" @selected((int) $user->tenant_id === (int) $tenant->id)>{{ $tenant->name }} (#{{ $tenant->id }})</option>
                                    @endforeach
                                </select>

                                <input
                                    type="text"
                                    name="tenant_name"
                                    class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                                    placeholder="Nome nuovo tenant"
                                />

                                <div class="grid gap-3 sm:grid-cols-[1fr_auto]">
                                    <select name="role" class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                        <option value="owner" @selected(($user->role ?? '') === 'owner')>owner</option>
                                        <option value="editor" @selected(($user->role ?? '') === 'editor')>editor</option>
                                    </select>

                                    <button type="submit" class="ui-btn-primary w-full justify-center sm:w-auto">Salva</button>
                                </div>
                            </form>
                        </article>
                    @empty
                        <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-6 text-center text-sm text-gray-600">
                            Nessun utente da gestire.
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Tenant e workspace</h2>
                        <p class="mt-1 text-sm text-gray-600">Ogni tenant espone accesso rapido, limiti operativi e stato workspace.</p>
                    </div>
                    <span class="rounded-full border border-gray-200 bg-gray-50 px-3 py-1 text-xs font-semibold text-gray-700">{{ $tenants->count() }} tenant</span>
                </div>

                <div class="mt-4 space-y-4">
                    @forelse($tenantStats as $row)
                        @php
                            $tenant = $row['tenant'];
                            $quota = is_array($row['quota'] ?? null) ? $row['quota'] : [];
                            $entryUser = $row['entry_user'] ?? null;
                            $trendBrief = is_array($row['trend_brief'] ?? null) ? $row['trend_brief'] : [];
                        @endphp
                        <article class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-base font-semibold text-gray-900">{{ $tenant->name }}</h3>
                                        <span class="rounded-full border {{ $tenant->is_active ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-red-200 bg-red-50 text-red-700' }} px-2.5 py-1 text-[11px] font-semibold">
                                            {{ $tenant->is_active ? 'attivo' : 'disattivato' }}
                                        </span>
                                        @if(!empty($quota['users_over_limit']) || !empty($quota['content_items_over_limit']))
                                            <span class="rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-700">
                                                limiti superati
                                            </span>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-xs text-gray-600">#{{ $tenant->id }} - {{ $tenant->slug }} - piano: {{ $tenant->plan }}</p>
                                    <div class="mt-2 flex flex-wrap gap-2 text-[11px] font-semibold">
                                        <span class="rounded-full border border-gray-200 bg-white px-2.5 py-1 text-gray-700">
                                            trend freshness: {{ is_numeric($trendBrief['freshness_score'] ?? null) ? number_format(((float) $trendBrief['freshness_score']) * 100, 0) . '%' : '-' }}
                                        </span>
                                        <span class="rounded-full border border-gray-200 bg-white px-2.5 py-1 text-gray-700">
                                            signals: {{ (int) ($trendBrief['signals_count'] ?? 0) }}
                                        </span>
                                    </div>
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    @if($entryUser)
                                        <form method="POST" action="{{ route('admin.tenants.impersonate', $tenant) }}">
                                            @csrf
                                            <button type="submit" class="ui-btn-primary">Entra come {{ $entryUser['role'] }}</button>
                                        </form>
                                    @else
                                        <span class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700">Nessun utente per accesso rapido</span>
                                    @endif
                                </div>
                            </div>

                            <div class="mt-4 grid gap-3 md:grid-cols-4">
                                <div class="rounded-xl border border-gray-200 bg-white px-3 py-3">
                                    <div class="text-[11px] uppercase tracking-wide text-gray-500">Utenti</div>
                                    <div class="mt-1 text-lg font-semibold text-gray-900">{{ (int) $row['users_total'] }}</div>
                                    <div class="mt-1 text-xs text-gray-600">
                                        limite: {{ $quota['max_users'] ?? 'illimitato' }}
                                        @if(!is_null($quota['users_remaining'] ?? null))
                                            - residui {{ $quota['users_remaining'] }}
                                        @endif
                                    </div>
                                </div>
                                <div class="rounded-xl border border-gray-200 bg-white px-3 py-3">
                                    <div class="text-[11px] uppercase tracking-wide text-gray-500">Contenuti</div>
                                    <div class="mt-1 text-lg font-semibold text-gray-900">{{ (int) $row['contents_total'] }}</div>
                                    <div class="mt-1 text-xs text-gray-600">
                                        limite: {{ $quota['max_content_items'] ?? 'illimitato' }}
                                        @if(!is_null($quota['content_items_remaining'] ?? null))
                                            - residui {{ $quota['content_items_remaining'] }}
                                        @endif
                                    </div>
                                </div>
                                <div class="rounded-xl border border-gray-200 bg-white px-3 py-3">
                                    <div class="text-[11px] uppercase tracking-wide text-gray-500">AI</div>
                                    <div class="mt-1 text-sm font-semibold text-gray-900">
                                        <span class="text-emerald-700">{{ (int) $row['ai_done'] }}</span>
                                        /
                                        <span class="text-amber-700">{{ (int) $row['ai_queued'] }}</span>
                                        /
                                        <span class="text-red-700">{{ (int) $row['ai_error'] }}</span>
                                    </div>
                                    <div class="mt-1 text-xs text-gray-600">done / queued / error</div>
                                </div>
                                <div class="rounded-xl border border-gray-200 bg-white px-3 py-3">
                                    <div class="text-[11px] uppercase tracking-wide text-gray-500">Ultima attivita</div>
                                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $row['last_activity_at'] ? $row['last_activity_at']->format('d/m/Y H:i') : '-' }}</div>
                                    <div class="mt-1 text-xs text-gray-600">brand: {{ $row['brand_completed_at'] ? 'pronto' : 'incompleto' }}</div>
                                </div>
                            </div>

                            <div class="mt-4 flex flex-wrap gap-1.5">
                                @forelse($row['users'] as $tenantUser)
                                    <span class="rounded-full border border-gray-200 bg-white px-2 py-1 text-[11px] font-semibold text-gray-700">
                                        {{ $tenantUser['email'] }} - {{ $tenantUser['role'] }}
                                    </span>
                                @empty
                                    <span class="text-xs text-gray-500">Nessuna utenza collegata</span>
                                @endforelse
                            </div>

                            <form method="POST" action="{{ route('admin.tenants.update', $tenant) }}" class="mt-4 grid gap-3 lg:grid-cols-5">
                                @csrf
                                @method('PUT')

                                <input
                                    type="text"
                                    name="plan"
                                    list="common-plan-options"
                                    value="{{ old('plan', $tenant->plan) }}"
                                    class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                                    placeholder="Piano"
                                />

                                <input
                                    type="number"
                                    name="max_users"
                                    min="1"
                                    value="{{ old('max_users', $quota['max_users']) }}"
                                    class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                                    placeholder="Max utenti"
                                />

                                <input
                                    type="number"
                                    name="max_content_items"
                                    min="1"
                                    value="{{ old('max_content_items', $quota['max_content_items']) }}"
                                    class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                                    placeholder="Max contenuti"
                                />

                                <label class="inline-flex items-center gap-2 rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700">
                                    <input type="checkbox" name="is_active" value="1" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" @checked($tenant->is_active) />
                                    Tenant attivo
                                </label>

                                <button type="submit" class="ui-btn-primary w-full justify-center">Aggiorna tenant</button>
                            </form>
                        </article>
                    @empty
                        <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-6 text-center text-sm text-gray-600">
                            Nessun tenant disponibile.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Admin accounts</h2>
                <div class="mt-3 space-y-2">
                    @forelse($adminUsers as $admin)
                        <div class="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                            <p class="truncate text-sm font-semibold text-gray-900">{{ $admin->email }}</p>
                            <p class="text-xs text-gray-600">role: {{ $admin->role }}</p>
                        </div>
                    @empty
                        <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-3 py-4 text-sm text-gray-600">
                            Nessun admin trovato.
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Logica operativa</h2>
                <div class="mt-3 space-y-3 text-sm text-gray-700">
                    <p>1. Ogni registrazione crea gia un tenant dedicato.</p>
                    <p>2. Da qui puoi riassegnare o scollegare il tenant all utente.</p>
                    <p>3. Il bottone entra apre il workspace del tenant con impersonazione admin reversibile.</p>
                    <p>4. Se disattivi un tenant, l utente non entra piu nel workspace; tu invece puoi ancora accedere via impersonazione admin.</p>
                    <p>5. I limiti utenti e contenuti vengono usati per bloccare nuove assegnazioni e nuove creazioni.</p>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Azioni consigliate</h2>
                <div class="mt-3 space-y-3 text-sm text-gray-700">
                    <p>Se un cliente va fermato: disattiva tenant e lascia traccia dei limiti.</p>
                    <p>Se un utente deve essere spostato: usa assegna tenant esistente e aggiorna il ruolo nello stesso form.</p>
                    <p>Se vuoi vedere il loro ambiente reale: entra dal tenant o dall utente senza toccare il database manualmente.</p>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
