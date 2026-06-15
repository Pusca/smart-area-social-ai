<?php

namespace App\Http\Controllers;

use App\Models\TenantProfile;
use App\Services\BrandParsingService;
use App\Services\Onboarding\QuickstartOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class BrandAiController extends Controller
{
    public function __construct(
        private readonly BrandParsingService $parser,
        private readonly QuickstartOnboardingService $quickstart,
    ) {}

    /**
     * POST /ai/brand/chat
     * Conversazione AI per raccogliere dati brand.
     */
    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'messages'              => ['required', 'array', 'min:1'],
            'messages.*.role'       => ['required', 'in:user,assistant'],
            'messages.*.content'    => ['required', 'string', 'max:4000'],
            'existing_profile'      => ['sometimes', 'array'],
        ]);

        try {
            $result = $this->parser->chat(
                $request->input('messages'),
                $request->input('existing_profile', [])
            );

            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('BrandAiController::chat', ['error' => $e->getMessage()]);
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /ai/brand/transcribe-chat
     * Trascrive audio con Whisper, estrae campi brand, salva subito in DB.
     */
    public function transcribeAndChat(Request $request): JsonResponse
    {
        $request->validate([
            'audio'            => ['required', 'file', 'max:25600'],
            'messages'         => ['sometimes', 'array'],
            'messages.*.role'  => ['required_with:messages', 'in:user,assistant'],
            'messages.*.content' => ['required_with:messages', 'string', 'max:4000'],
            'existing_profile' => ['sometimes', 'array'],
        ]);

        try {
            $file       = $request->file('audio');
            $ext        = $file->getClientOriginalExtension() ?: 'webm';
            $transcript = $this->parser->transcribe($file->getPathname(), 'audio.' . $ext);

            if (empty($transcript)) {
                return response()->json(['error' => 'Nessun parlato rilevato. Riprova.'], 422);
            }

            $messages   = $request->input('messages', []);
            $messages[] = ['role' => 'user', 'content' => $transcript];

            $result = $this->parser->chat($messages, $request->input('existing_profile', []));

            /* Salva immediatamente in DB */
            $user    = Auth::user();
            $profile = TenantProfile::query()->firstOrNew(['tenant_id' => $user->tenant_id]);
            $this->parser->mergeIntoProfile($profile, $result['extracted']);

            return response()->json(array_merge($result, ['transcript' => $transcript]));

        } catch (\Throwable $e) {
            Log::error('BrandAiController::transcribeAndChat', ['error' => $e->getMessage()]);
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /ai/voice/transcribe
     * Trascrive audio (Whisper) e restituisce solo il testo — nessun salvataggio.
     * Usato dalla dettatura vocale nel form di creazione contenuto.
     */
    public function transcribeBrief(Request $request): JsonResponse
    {
        $request->validate([
            'audio' => ['required', 'file', 'max:25600'],
        ]);

        try {
            $file       = $request->file('audio');
            $ext        = strtolower($file->getClientOriginalExtension() ?: 'webm');
            $transcript = $this->parser->transcribe($file->getPathname(), 'audio.' . $ext);

            if (empty($transcript)) {
                return response()->json(['error' => 'Nessun parlato rilevato. Riprova.'], 422);
            }

            return response()->json(['transcript' => $transcript]);

        } catch (\Throwable $e) {
            Log::error('BrandAiController::transcribeBrief', ['error' => $e->getMessage()]);
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /ai/brand/apply
     * Salva i dati estratti nel TenantProfile (uso dal brand center).
     */
    public function apply(Request $request): JsonResponse
    {
        $request->validate([
            'extracted' => ['required', 'array'],
        ]);

        $user    = Auth::user();
        $profile = TenantProfile::query()->firstOrNew(['tenant_id' => $user->tenant_id]);

        try {
            $this->parser->mergeIntoProfile($profile, $request->input('extracted'));
            return response()->json(['ok' => true, 'message' => 'Profilo aggiornato.']);
        } catch (\Throwable $e) {
            Log::error('BrandAiController::apply', ['error' => $e->getMessage()]);
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /ai/brand/onboarding-complete
     * Salva dati brand + avvia generazione quickstart.
     */
    public function onboardingComplete(Request $request): JsonResponse
    {
        $request->validate([
            'extracted' => ['sometimes', 'array'],
        ]);

        $user    = Auth::user();
        $profile = TenantProfile::query()->firstOrNew(['tenant_id' => $user->tenant_id]);

        // Applica eventuali dati estratti dall'AI
        if ($request->has('extracted') && is_array($request->input('extracted'))) {
            try {
                $this->parser->mergeIntoProfile($profile, $request->input('extracted'));
                $profile->refresh();
            } catch (\Throwable $e) {
                Log::warning('BrandAiController::onboardingComplete merge failed', ['error' => $e->getMessage()]);
            }
        }

        try {
            $result = $this->quickstart->regenerateQuickstart($user);
            $redirectUrl = route('plans.generating', [
                'contentPlan' => $result['plan']->id,
                'context'     => 'quickstart',
            ]);
            return response()->json(['redirect_url' => $redirectUrl]);
        } catch (\Throwable $e) {
            Log::error('BrandAiController::onboardingComplete quickstart failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
