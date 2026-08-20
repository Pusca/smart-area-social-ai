<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Throwable $e) {
            Log::info('Google login fallito', ['error' => $e->getMessage()]);

            return redirect()->route('login')
                ->withErrors(['email' => 'Accesso con Google non riuscito. Riprova o usa email e password.']);
        }

        $user = User::where('google_id', $googleUser->getId())->first()
            ?? User::where('email', $googleUser->getEmail())->first();

        if ($user) {
            if (!$user->google_id) {
                $user->google_id = $googleUser->getId();
            }
            // Google garantisce la proprietà dell'email
            if (!$user->hasVerifiedEmail()) {
                $user->email_verified_at = now();
            }
            $user->save();
        } else {
            // Stessa semantica della registrazione: ogni nuovo utente = nuovo tenant
            $user = DB::transaction(function () use ($googleUser) {
                $name = $googleUser->getName() ?: ($googleUser->getNickname() ?: 'Utente Google');

                $tenant = Tenant::create([
                    'name' => $name,
                    'slug' => $this->uniqueTenantSlug($name),
                ]);

                $user = new User([
                    'name' => $name,
                    'email' => $googleUser->getEmail(),
                    'password' => Hash::make(Str::random(40)),
                ]);
                $user->tenant_id = $tenant->id;
                $user->role = 'owner';
                $user->google_id = $googleUser->getId();
                $user->email_verified_at = now();
                $user->save();

                return $user;
            });
        }

        Auth::login($user, remember: true);

        $hasProfile = TenantProfile::where('tenant_id', $user->tenant_id)->exists();

        return $hasProfile
            ? redirect()->intended(route('dashboard', absolute: false))
            : redirect()->route('profile.brand')
                ->with('status', 'Benvenuto! Inserisci il sito web della tua attività e lascia che l\'AI compili il profilo.');
    }

    private function uniqueTenantSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'tenant';
        $slug = $base;

        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $base . '-' . Str::lower(Str::random(4));
        }

        return $slug;
    }
}
