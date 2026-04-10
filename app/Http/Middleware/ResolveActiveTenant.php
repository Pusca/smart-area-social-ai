<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\UserTenantMembership;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Risolve il "tenant attivo" nella sessione corrente per il feature multi-brand.
 *
 * Funzionamento:
 *   1. Legge `session('active_tenant_id')` impostato da AgencyBrandSwitchController.
 *   2. Verifica che l'utente abbia una membership per quel tenant.
 *   3. Se valido, sovrascrive `$user->tenant_id` in memoria (NO DB write)
 *      e carica la relazione `tenant` aggiornata.
 *   4. Se non valido (tenant revocato, sessione stale), resetta la sessione
 *      e usa il tenant primario dell'utente.
 *
 * Questo middleware deve essere eseguito DOPO `auth` e PRIMA di `hasTenant`,
 * in modo che EnsureUserHasTenant legga il tenant già risolto.
 *
 * Registrazione in bootstrap/app.php o Kernel.php:
 *   'resolveActiveTenant' => \App\Http\Middleware\ResolveActiveTenant::class
 */
class ResolveActiveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Senza utente autenticato non c'è nulla da fare
        if (!$user) {
            return $next($request);
        }

        $activeTenantId = (int) $request->session()->get('active_tenant_id', 0);

        // Nessun override in sessione: usa il tenant primario
        if ($activeTenantId === 0) {
            return $next($request);
        }

        // Stesso tenant del profilo: nessuna sostituzione necessaria
        if ($activeTenantId === (int) $user->tenant_id) {
            return $next($request);
        }

        // Verifica che l'utente abbia una membership valida per il tenant richiesto
        $membership = UserTenantMembership::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', $activeTenantId)
            ->first();

        if (!$membership) {
            // Sessione stale o accesso revocato: reset silenzioso
            Log::info('ResolveActiveTenant: membership non trovata, reset a tenant primario.', [
                'user_id'          => $user->id,
                'active_tenant_id' => $activeTenantId,
            ]);
            $request->session()->forget('active_tenant_id');

            return $next($request);
        }

        // Carica il tenant per verificare che esista e sia attivo
        $tenant = Tenant::find($activeTenantId);
        if (!$tenant || !$tenant->is_active) {
            $request->session()->forget('active_tenant_id');

            return $next($request);
        }

        // ── Sovrascrittura in memoria (NO DB write) ───────────────────────────
        // Impostiamo il tenant_id e la relazione in memoria per questa request.
        // Tutti i controller che leggono $user->tenant_id o $user->tenant
        // riceveranno automaticamente il tenant corretto.
        $user->setAttribute('tenant_id', $activeTenantId);
        $user->setRelation('tenant', $tenant);

        return $next($request);
    }
}
