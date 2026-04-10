<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\UserTenantMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Gestisce il feature multi-brand per agenzie e freelance.
 *
 * Permette a un utente membro di più tenant di listare i brand
 * accessibili e di cambiare il brand "attivo" nella sessione corrente,
 * senza modificare il tenant primario sul DB.
 *
 * Il middleware `ResolveActiveTenant` legge `session('active_tenant_id')`
 * e sovrascrive `$user->tenant_id` in memoria per tutta la request.
 *
 * Routes:
 *   GET  /agency/brands           → index()   lista brand accessibili
 *   POST /agency/brands/{tenant}/switch → switch()  imposta brand attivo
 *   POST /agency/brands/reset     → reset()   torna al brand primario
 */
class AgencyBrandSwitchController extends Controller
{
    /**
     * Lista tutti i brand (tenant) accessibili dall'utente corrente.
     *
     * Include il tenant primario + le membership aggiuntive.
     * Usato dal pannello di selezione brand nell'header o nel menu.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // ── Tenant primario ───────────────────────────────────────────────────
        $primaryTenant = $user->tenant;
        $brands = [];

        if ($primaryTenant) {
            $brands[] = [
                'tenant_id'  => (int) $primaryTenant->id,
                'name'       => $primaryTenant->name,
                'slug'       => $primaryTenant->slug,
                'role'       => 'owner',
                'is_primary' => true,
                'is_active'  => (bool) $primaryTenant->is_active,
                'is_current' => (int) $user->tenant_id === (int) $primaryTenant->id,
            ];
        }

        // ── Membership aggiuntive ─────────────────────────────────────────────
        $memberships = UserTenantMembership::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', '!=', $primaryTenant?->id ?? 0)
            ->with('tenant')
            ->get();

        foreach ($memberships as $m) {
            $tenant = $m->tenant;
            if (!$tenant || !$tenant->is_active) {
                continue;
            }

            $brands[] = [
                'tenant_id'  => (int) $tenant->id,
                'name'       => $tenant->name,
                'slug'       => $tenant->slug,
                'role'       => $m->role,
                'is_primary' => false,
                'is_active'  => true,
                'is_current' => (int) $user->tenant_id === (int) $tenant->id,
            ];
        }

        return response()->json([
            'brands'          => $brands,
            'active_tenant_id' => (int) $user->tenant_id,
        ]);
    }

    /**
     * Imposta il brand attivo nella sessione.
     *
     * Verifica che l'utente abbia accesso al tenant richiesto prima
     * di scrivere in sessione, per evitare privilege escalation.
     *
     * POST /agency/brands/{tenant}/switch
     */
    public function switch(Request $request, Tenant $tenant): RedirectResponse
    {
        $user = $request->user();

        // Il tenant primario dell'utente è sempre accessibile
        $isPrimary = (int) $user->getOriginal('tenant_id') === (int) $tenant->id
            || (int) $user->tenant_id === (int) $tenant->id;

        if (!$isPrimary) {
            // Verifica membership per tenant non primari
            $hasMembership = UserTenantMembership::query()
                ->where('user_id', $user->id)
                ->where('tenant_id', $tenant->id)
                ->exists();

            if (!$hasMembership) {
                abort(403, 'Accesso al brand non autorizzato.');
            }
        }

        if (!$tenant->is_active) {
            return back()->with('status', 'Il brand selezionato non è attivo.');
        }

        // Imposta il brand attivo in sessione
        $request->session()->put('active_tenant_id', (int) $tenant->id);

        return redirect()->route('dashboard')
            ->with('status', 'Brand attivo cambiato: ' . $tenant->name);
    }

    /**
     * Reimposta il brand attivo al tenant primario dell'utente,
     * rimuovendo l'override di sessione.
     *
     * POST /agency/brands/reset
     */
    public function reset(Request $request): RedirectResponse
    {
        $request->session()->forget('active_tenant_id');

        return redirect()->route('dashboard')
            ->with('status', 'Tornato al brand principale.');
    }
}
