<?php

namespace App\Http\Middleware;

use App\Models\TenantProfile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reindirizza i nuovi utenti al wizard di onboarding se non hanno
 * ancora completato il setup iniziale.
 *
 * Non intercetta:
 * - La route /onboarding stessa (evita loop)
 * - Le route di logout e profilo account
 * - Gli admin in modalità impersonation
 */
class RedirectIfOnboardingIncomplete
{
    /** Route che possono essere visitate anche durante l'onboarding. */
    private const ALLOWED_ROUTES = [
        'onboarding.*',
        'logout',
        'profile.edit',
        'profile.update',
        'profile.destroy',
        'password.*',
        'verification.*',
        'settings.social.*',  // OAuth callback social
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Non autenticato: lascia passare (gestirà il middleware auth)
        if (! $user) {
            return $next($request);
        }

        // Admin in impersonazione: non bloccare
        if ($request->session()->has('admin_impersonation.original_admin_id')) {
            return $next($request);
        }

        // Route consentite durante onboarding
        foreach (self::ALLOWED_ROUTES as $pattern) {
            if ($request->routeIs($pattern)) {
                return $next($request);
            }
        }

        // Controlla se l'onboarding è completato
        $profile = TenantProfile::query()
            ->where('tenant_id', $user->tenant_id)
            ->select('onboarding_completed_at')
            ->first();

        if (! $profile?->onboarding_completed_at) {
            return redirect()->route('onboarding');
        }

        return $next($request);
    }
}
