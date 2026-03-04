<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsPlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $role = strtolower(trim((string) ($user?->role ?? '')));
        $isAdmin = in_array($role, ['admin', 'super_admin'], true);

        if (!$user || !$isAdmin) {
            abort(403, 'Accesso riservato agli amministratori piattaforma.');
        }

        return $next($request);
    }
}

