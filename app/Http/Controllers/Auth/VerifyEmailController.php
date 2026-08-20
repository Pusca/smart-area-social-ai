<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\TenantProfile;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended($this->destination($request).'?verified=1');
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        return redirect()->intended($this->destination($request).'?verified=1');
    }

    /**
     * Un utente senza profilo attività deve atterrare sull'onboarding.
     */
    protected function destination(EmailVerificationRequest $request): string
    {
        $hasProfile = TenantProfile::where('tenant_id', $request->user()->tenant_id)->exists();

        return $hasProfile
            ? route('dashboard', absolute: false)
            : route('profile.brand', absolute: false);
    }
}
