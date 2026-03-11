@extends('layouts.admin')

@section('content')
@php
    $stats = is_array($stats ?? null) ? $stats : [];
@endphp

<section class="space-y-6">
    <div class="overflow-hidden rounded-3xl border border-gray-200 bg-gradient-to-br from-white via-indigo-50/40 to-cyan-50/40 p-6 shadow-sm lg:p-8">
        <div class="grid gap-4 lg:grid-cols-[1.2fr_0.8fr] lg:items-center">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Platform Admin</div>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">Controllo account e tenant</h1>
                <p class="mt-2 max-w-2xl text-sm text-gray-600">
                    Vista globale della piattaforma: utenti registrati, tenant, avanzamento contenuti e assegnazione tenant.
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
                        <div class="text-[11px] text-gray-500">Contenuti</div>
                        <div class="text-lg font-semibold text-gray-900">{{ (int) ($stats['contents_total'] ?? 0) }}</div>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                        <div class="text-[11px] text-gray-500">AI done</div>
                        <div class="text-lg font-semibold text-gray-900">{{ (int) ($stats['ai_done'] ?? 0) }}</div>
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

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Completamento AI globale</div>
            <div class="mt-2 text-2xl font-semibold text-gray-900">{{ (int) ($stats['ai_completion'] ?? 0) }}%</div>
            <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                <div class="h-full rounded-full bg-indigo-600" style="width: {{ (int) ($stats['ai_completion'] ?? 0) }}%"></div>
            </div>
        </article>
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Tenant attivi (7 giorni)</div>
            <div class="mt-2 text-2xl font-semibold text-gray-900">{{ (int) ($stats['tenants_active_recently'] ?? 0) }}</div>
            <p class="mt-1 text-xs text-gray-600">Con almeno attivita recente su contenuti</p>
        </article>
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Tenant brand configurato</div>
            <div class="mt-2 text-2xl font-semibold text-gray-900">{{ (int) ($stats['tenants_brand_ready'] ?? 0) }}</div>
            <p class="mt-1 text-xs text-gray-600">Profilo brand completato</p>
        </article>
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Utenti senza tenant</div>
            <div class="mt-2 text-2xl font-semibold {{ ((int) ($stats['users_without_tenant'] ?? 0)) > 0 ? 'text-amber-700' : 'text-gray-900' }}">
                {{ (int) ($stats['users_without_tenant'] ?? 0) }}
            </div>
            <p class="mt-1 text-xs text-gray-600">Da assegnare manualmente</p>
        </article>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Utenti registrati</h2>
                        <p class="mt-1 text-sm text-gray-600">Assegna tenant esistente, crea nuovo tenant o scollega tenant.</p>
                    </div>
                    <span class="rounded-full border border-gray-200 bg-gray-50 px-3 py-1 text-xs font-semibold text-gray-700">
                        {{ $managedUsers->count() }} utenti
                    </span>
                </div>

                <div class="mt-4 space-y-3">
                    @forelse($managedUsers as $user)
                        <article class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-gray-900">{{ $user->name ?: 'Utente senza nome' }}</p>
                                    <p class="truncate text-xs text-gray-600">{{ $user->email }}</p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full border border-gray-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-gray-700">
                                        role: {{ $user->role ?: 'editor' }}
                                    </span>
                                    <span class="rounded-full border {{ $user->tenant_id ? 'border-indigo-200 bg-indigo-50 text-indigo-700' : 'border-amber-200 bg-amber-50 text-amber-700' }} px-2.5 py-1 text-[11px] font-semibold">
                                        tenant: {{ $user->tenant?->name ?: 'non assegnato' }}
                                    </span>
                                </div>
                            </div>

                            <form method="POST" action="{{ route('admin.users.tenant.update', $user) }}" class="mt-3 grid gap-2 lg:grid-cols-4">
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
                                        <option value="{{ $tenant->id }}" @selected((int) $user->tenant_id === (int) $tenant->id)>
                                            {{ $tenant->name }} (#{{ $tenant->id }})
                                        </option>
                                    @endforeach
                                </select>

                                <input
                                    type="text"
                                    name="tenant_name"
                                    class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                                    placeholder="Nome nuovo tenant (se azione = nuovo)"
                                />

                                <button type="submit" class="ui-btn-primary w-full justify-center">
                                    Salva assegnazione
                                </button>
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
                <h2 class="text-lg font-semibold text-gray-900">Stato tenant e utilizzo</h2>
                <p class="mt-1 text-sm text-gray-600">Flusso di utilizzo per tenant (utenti, piani, contenuti, AI).</p>

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left font-semibold text-gray-600">Tenant</th>
                                <th class="px-3 py-2 text-left font-semibold text-gray-600">Utenti</th>
                                <th class="px-3 py-2 text-left font-semibold text-gray-600">Piani</th>
                                <th class="px-3 py-2 text-left font-semibold text-gray-600">Contenuti</th>
                                <th class="px-3 py-2 text-left font-semibold text-gray-600">AI done/queued/error</th>
                                <th class="px-3 py-2 text-left font-semibold text-gray-600">Brand</th>
                                <th class="px-3 py-2 text-left font-semibold text-gray-600">Ultima attivita</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @forelse($tenantStats as $row)
                                @php $tenant = $row['tenant']; @endphp
                                <tr>
                                    <td class="px-3 py-2 align-top">
                                        <p class="font-semibold text-gray-900">{{ $tenant->name }}</p>
                                        <p class="text-xs text-gray-500">#{{ $tenant->id }} - {{ $tenant->slug }}</p>
                                    </td>
                                    <td class="px-3 py-2 align-top text-gray-700">{{ (int) $row['users_total'] }}</td>
                                    <td class="px-3 py-2 align-top text-gray-700">{{ (int) $row['plans_total'] }}</td>
                                    <td class="px-3 py-2 align-top text-gray-700">{{ (int) $row['contents_total'] }}</td>
                                    <td class="px-3 py-2 align-top text-gray-700">
                                        <span class="text-emerald-700">{{ (int) $row['ai_done'] }}</span>
                                        /
                                        <span class="text-amber-700">{{ (int) $row['ai_queued'] }}</span>
                                        /
                                        <span class="text-red-700">{{ (int) $row['ai_error'] }}</span>
                                    </td>
                                    <td class="px-3 py-2 align-top">
                                        @if($row['brand_completed_at'])
                                            <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700">
                                                pronto
                                            </span>
                                        @else
                                            <span class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700">
                                                non completo
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 align-top text-gray-700">
                                        {{ $row['last_activity_at'] ? $row['last_activity_at']->format('d/m/Y H:i') : '-' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-3 py-6 text-center text-sm text-gray-600">
                                        Nessun tenant disponibile.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
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
                <h2 class="text-lg font-semibold text-gray-900">Azioni utili</h2>
                <div class="mt-3 space-y-2 text-sm text-gray-700">
                    <p>1. Crea utente da `/register`: riceve tenant dedicato automatico.</p>
                    <p>2. Da qui puoi riassegnare tenant o crearne uno nuovo.</p>
                    <p>3. Se un utente e senza tenant, non puo usare dashboard operativa.</p>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
