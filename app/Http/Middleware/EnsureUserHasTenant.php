<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && !$user->tenant_id) {
            abort(403, 'Utente senza tenant associato.');
        }

        if ($user && $user->tenant_id) {
            $tenant = $user->tenant;
            $isImpersonatingAsAdmin = (bool) $request->session()->get('admin_impersonation.original_admin_id');

            if (!$tenant) {
                abort(403, 'Tenant non trovato.');
            }

            if (!$tenant->is_active && !$isImpersonatingAsAdmin) {
                abort(403, 'Workspace tenant disattivato.');
            }
        }

        return $next($request);
    }
}
