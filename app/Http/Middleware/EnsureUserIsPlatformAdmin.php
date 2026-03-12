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

        if (!$user || !$user->isPlatformAdmin()) {
            abort(403, 'Accesso riservato agli amministratori piattaforma.');
        }

        return $next($request);
    }
}
