@extends('layouts.admin')

@section('content')
@php
    $summary = is_array($summary ?? null) ? $summary : [];
    $failureRate = is_array($summary['failure_rate'] ?? null) ? $summary['failure_rate'] : [];
    $retryRate = is_array($summary['retry_rate'] ?? null) ? $summary['retry_rate'] : [];
    $downgradeRate = is_array($summary['downgrade_rate'] ?? null) ? $summary['downgrade_rate'] : [];
    $fallbackRate = is_array($summary['fallback_rate'] ?? null) ? $summary['fallback_rate'] : [];
@endphp

<section class="space-y-6">
    <div class="rounded-3xl border border-gray-200 bg-white p-6 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">AI Observability</div>
                <h1 class="mt-2 text-2xl font-semibold text-gray-900">Metriche generazione AI</h1>
                <p class="mt-2 text-sm text-gray-600">Costi, latenza, retry, fallback, downgrade e failure mode aggregati per tenant e provider.</p>
            </div>
            <a href="{{ route('admin.dashboard') }}" class="ui-btn-secondary">Torna alla dashboard</a>
        </div>
    </div>

    <form method="GET" action="{{ route('admin.ai.metrics') }}" class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <div class="grid gap-3 md:grid-cols-[1.2fr_0.8fr_auto]">
            <select name="tenant_id" class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900">
                <option value="">Tutti i tenant</option>
                @foreach($tenants as $tenant)
                    <option value="{{ $tenant->id }}" @selected((int) ($selectedTenantId ?? 0) === (int) $tenant->id)>{{ $tenant->name }} (#{{ $tenant->id }})</option>
                @endforeach
            </select>
            <input type="number" min="1" max="365" name="days" value="{{ (int) ($selectedDays ?? 30) }}" class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900" />
            <button type="submit" class="ui-btn-primary w-full justify-center">Aggiorna</button>
        </div>
    </form>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Run osservate</div>
            <div class="mt-2 text-2xl font-semibold text-gray-900">{{ number_format((int) ($summary['runs_count'] ?? 0), 0, ',', '.') }}</div>
        </article>
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Costo effettivo</div>
            <div class="mt-2 text-2xl font-semibold text-gray-900">${{ number_format((float) ($summary['effective_cost_usd'] ?? 0), 4, '.', ',') }}</div>
            <p class="mt-1 text-xs text-gray-600">stimato ${{ number_format((float) ($summary['estimated_cost_usd'] ?? 0), 4, '.', ',') }} / reale ${{ number_format((float) ($summary['actual_cost_usd'] ?? 0), 4, '.', ',') }}</p>
        </article>
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Failure rate</div>
            <div class="mt-2 text-2xl font-semibold text-red-700">{{ number_format(((float) ($failureRate['rate'] ?? 0)) * 100, 1, ',', '.') }}%</div>
        </article>
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Retry / fallback / downgrade</div>
            <div class="mt-2 text-sm font-semibold text-gray-900">
                retry {{ number_format(((float) ($retryRate['rate'] ?? 0)) * 100, 1, ',', '.') }}%<br />
                fallback {{ number_format(((float) ($fallbackRate['rate'] ?? 0)) * 100, 1, ',', '.') }}%<br />
                downgrade {{ number_format(((float) ($downgradeRate['rate'] ?? 0)) * 100, 1, ',', '.') }}%
            </div>
        </article>
    </div>

    <div class="grid gap-6 xl:grid-cols-[1.2fr_1fr]">
        <div class="space-y-6">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Costo per provider</h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-3 py-2">Provider</th>
                                <th class="px-3 py-2">Attempt</th>
                                <th class="px-3 py-2">Stimato</th>
                                <th class="px-3 py-2">Reale</th>
                                <th class="px-3 py-2">Effettivo</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($costByProvider as $row)
                                <tr>
                                    <td class="px-3 py-2 font-semibold text-gray-900">{{ $row['provider'] }}</td>
                                    <td class="px-3 py-2 text-gray-700">{{ number_format((int) $row['attempts_count'], 0, ',', '.') }}</td>
                                    <td class="px-3 py-2 text-gray-700">${{ number_format((float) $row['estimated_cost_usd'], 4, '.', ',') }}</td>
                                    <td class="px-3 py-2 text-gray-700">${{ number_format((float) $row['actual_cost_usd'], 4, '.', ',') }}</td>
                                    <td class="px-3 py-2 font-semibold text-gray-900">${{ number_format((float) $row['effective_cost_usd'], 4, '.', ',') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-3 py-6 text-center text-sm text-gray-500">Nessun dato provider nel periodo selezionato.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Costo per tenant</h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-3 py-2">Tenant</th>
                                <th class="px-3 py-2">Run</th>
                                <th class="px-3 py-2">Stimato</th>
                                <th class="px-3 py-2">Reale</th>
                                <th class="px-3 py-2">Effettivo</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($costByTenant as $row)
                                <tr>
                                    <td class="px-3 py-2 font-semibold text-gray-900">{{ $row['tenant_name'] }}</td>
                                    <td class="px-3 py-2 text-gray-700">{{ number_format((int) $row['runs_count'], 0, ',', '.') }}</td>
                                    <td class="px-3 py-2 text-gray-700">${{ number_format((float) $row['estimated_cost_usd'], 4, '.', ',') }}</td>
                                    <td class="px-3 py-2 text-gray-700">${{ number_format((float) $row['actual_cost_usd'], 4, '.', ',') }}</td>
                                    <td class="px-3 py-2 font-semibold text-gray-900">${{ number_format((float) $row['effective_cost_usd'], 4, '.', ',') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-3 py-6 text-center text-sm text-gray-500">Nessun dato tenant nel periodo selezionato.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Latenza media per provider</h2>
                <div class="mt-4 space-y-3">
                    @forelse($latencyByProvider as $row)
                        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm font-semibold text-gray-900">{{ $row['provider'] }}</span>
                                <span class="text-sm text-gray-700">{{ number_format((int) $row['avg_runtime_ms'], 0, ',', '.') }} ms</span>
                            </div>
                            <div class="mt-1 text-xs text-gray-600">{{ number_format((int) $row['attempts_count'], 0, ',', '.') }} attempt osservati</div>
                        </div>
                    @empty
                        <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-6 text-center text-sm text-gray-600">Nessuna latenza disponibile nel periodo selezionato.</div>
                    @endforelse
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Failure mode</h2>
                <div class="mt-4 space-y-3">
                    @forelse($failureModes as $row)
                        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm font-semibold text-gray-900">{{ $row['failure_mode'] }}</span>
                                <span class="text-sm text-gray-700">{{ number_format((int) $row['runs_count'], 0, ',', '.') }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-6 text-center text-sm text-gray-600">Nessun failure mode persistito nel periodo selezionato.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
