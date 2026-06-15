<?php

namespace App\Http\Controllers;

use App\Models\AlterEgo;
use App\Services\AlterEgoAnalyticsService;
use App\Services\AlterEgoService;
use App\Services\OpenAiService;
use App\Support\AlterEgoMarketplace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class AlterEgoController extends Controller
{
    public function __construct(
        private readonly AlterEgoService $alterEgoService,
        private readonly OpenAiService $openAi,
        private readonly AlterEgoAnalyticsService $analytics
    ) {
    }

    public function index(Request $request): View
    {
        $tenantId  = (int) $request->user()->tenant_id;
        $alterEgos = AlterEgo::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();

        $stats     = $this->analytics->summaryForIds($alterEgos->pluck('id')->map(fn ($id) => (int) $id)->all());
        $templates = AlterEgoMarketplace::templates();

        return view('alter-ego.index', compact('alterEgos', 'stats', 'templates'));
    }

    public function create(Request $request): View
    {
        // Legge il draft da sessione (usato anche dal marketplace import)
        $prefill = (array) ($request->session()->get('alter_ego_wizard_draft', []));

        return view('alter-ego.create', compact('prefill'));
    }

    public function analytics(Request $request, AlterEgo $alterEgo): View
    {
        $this->authorizeAlterEgo($request, $alterEgo);
        $stats = $this->analytics->statsForAlterEgo($alterEgo);

        return view('alter-ego.analytics', compact('alterEgo', 'stats'));
    }

    /**
     * Importa un template dalla libreria: carica i dati in sessione
     * e reindirizza al wizard create già pre-compilato.
     */
    public function importTemplate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'template_id' => 'required|string|max:80',
        ]);

        $template = AlterEgoMarketplace::findById($data['template_id']);

        if ($template === null) {
            return redirect()->route('alter-ego.index')
                ->with('error', 'Template non trovato.');
        }

        $request->session()->put('alter_ego_wizard_draft', $template['data']);

        return redirect()->route('alter-ego.create')
            ->with('info', "Template \"{$template['label']}\" caricato. Personalizza i campi e salva.");
    }

    /**
     * Salva un singolo passo del wizard (JSON).
     * Usato dal wizard Alpine per la persistenza progressiva in sessione.
     * Ritorna sempre ok=true — la validazione reale è al momento del salvataggio finale.
     */
    public function storeStep(Request $request): JsonResponse
    {
        $step = (int) $request->input('step', 0);
        $data = (array) $request->input('data', []);

        $sessionKey = 'alter_ego_wizard_draft';
        $draft      = (array) ($request->session()->get($sessionKey, []));
        $draft      = array_merge($draft, $data);

        $request->session()->put($sessionKey, $draft);

        return response()->json(['ok' => true, 'step' => $step]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $data = $request->validate([
            'name'               => 'required|string|max:150',
            'archetype'          => 'required|string|in:thought_leader,educator,storyteller,entertainer,provocateur,connector',
            'tone'               => 'required|string|in:autorevole,diretto,empatico,ironico,formale,colloquiale',
            'sentence_style'     => 'required|string|in:brevi_incisive,narrative,domande_retoriche',
            'vocabulary_level'   => 'required|string|in:tecnico,accessibile,misto',
            'signature_phrases'  => 'nullable|array|max:8',
            'signature_phrases.*'=> 'string|max:200',
            'topics_owned'       => 'nullable|array|max:20',
            'topics_owned.*'     => 'string|max:100',
            'topics_avoided'     => 'nullable|array|max:20',
            'topics_avoided.*'   => 'string|max:100',
            'content_pillars'    => 'nullable|array|max:5',
            'unique_perspective' => 'nullable|string|max:800',
            'audience_role'      => 'nullable|string|in:teacher,peer,mentor,challenger,entertainer',
            'cta_style'          => 'nullable|string|in:soft,direct,domanda,nessuna',
            'training_samples'      => 'nullable|array|max:5',
            'training_samples.*'    => 'string|max:3000',
            'platform_adaptations'  => 'nullable|array',
            'is_default'            => 'boolean',
        ]);

        if (!empty($data['is_default'])) {
            AlterEgo::query()
                ->where('tenant_id', $tenantId)
                ->update(['is_default' => false]);
        }

        $alterEgo = AlterEgo::create(array_merge($data, [
            'tenant_id' => $tenantId,
            'is_active' => true,
        ]));

        $this->alterEgoService->recompile($alterEgo);

        // Pulisce il draft di sessione dopo salvataggio riuscito
        $request->session()->forget('alter_ego_wizard_draft');

        if ($request->wantsJson()) {
            return response()->json([
                'ok'           => true,
                'id'           => $alterEgo->id,
                'redirect_url' => route('alter-ego.index'),
            ]);
        }

        return redirect()->route('alter-ego.index')
            ->with('success', "Alter ego \"{$alterEgo->name}\" creato con successo.");
    }

    public function edit(Request $request, AlterEgo $alterEgo): View
    {
        $this->authorizeAlterEgo($request, $alterEgo);

        return view('alter-ego.edit', compact('alterEgo'));
    }

    public function update(Request $request, AlterEgo $alterEgo): RedirectResponse
    {
        $this->authorizeAlterEgo($request, $alterEgo);
        $tenantId = (int) $request->user()->tenant_id;

        $data = $request->validate([
            'name'               => 'required|string|max:150',
            'archetype'          => 'required|string|in:thought_leader,educator,storyteller,entertainer,provocateur,connector',
            'tone'               => 'required|string|in:autorevole,diretto,empatico,ironico,formale,colloquiale',
            'sentence_style'     => 'required|string|in:brevi_incisive,narrative,domande_retoriche',
            'vocabulary_level'   => 'required|string|in:tecnico,accessibile,misto',
            'signature_phrases'  => 'nullable|array|max:8',
            'signature_phrases.*'=> 'string|max:200',
            'topics_owned'       => 'nullable|array|max:20',
            'topics_owned.*'     => 'string|max:100',
            'topics_avoided'     => 'nullable|array|max:20',
            'topics_avoided.*'   => 'string|max:100',
            'content_pillars'    => 'nullable|array|max:5',
            'unique_perspective' => 'nullable|string|max:800',
            'audience_role'      => 'nullable|string|in:teacher,peer,mentor,challenger,entertainer',
            'cta_style'          => 'nullable|string|in:soft,direct,domanda,nessuna',
            'training_samples'     => 'nullable|array|max:5',
            'training_samples.*'   => 'string|max:3000',
            'platform_adaptations' => 'nullable|array',
            'is_default'           => 'boolean',
        ]);

        if (!empty($data['is_default'])) {
            AlterEgo::query()
                ->where('tenant_id', $tenantId)
                ->where('id', '!=', $alterEgo->id)
                ->update(['is_default' => false]);
        }

        $alterEgo->fill($data);
        $alterEgo->save();
        $this->alterEgoService->recompile($alterEgo);

        return redirect()->route('alter-ego.index')
            ->with('success', "Alter ego \"{$alterEgo->name}\" aggiornato.");
    }

    public function destroy(Request $request, AlterEgo $alterEgo): RedirectResponse
    {
        $this->authorizeAlterEgo($request, $alterEgo);
        $name = $alterEgo->name;
        $alterEgo->delete();

        return redirect()->route('alter-ego.index')
            ->with('success', "Alter ego \"{$name}\" eliminato.");
    }

    public function setDefault(Request $request, AlterEgo $alterEgo): JsonResponse
    {
        $this->authorizeAlterEgo($request, $alterEgo);
        $tenantId = (int) $request->user()->tenant_id;

        AlterEgo::query()->where('tenant_id', $tenantId)->update(['is_default' => false]);
        $alterEgo->update(['is_default' => true]);

        return response()->json(['ok' => true]);
    }

    /**
     * Analizza campioni di testo e restituisce un profilo di voce strutturato.
     * Endpoint chiamato dal wizard "Analizza la mia voce".
     */
    public function extractVoice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'samples'   => 'required|array|min:1|max:5',
            'samples.*' => 'required|string|min:30|max:3000',
        ]);

        try {
            $profile = $this->openAi->extractVoiceFromSamples($data['samples']);

            if (empty($profile)) {
                return response()->json(['error' => 'Impossibile estrarre un profilo di voce dai testi forniti.'], 422);
            }

            return response()->json(['ok' => true, 'profile' => $profile]);
        } catch (Throwable $e) {
            return response()->json(['error' => 'Analisi non riuscita: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Carica uno o più file media (foto, audio, video) e li aggiunge
     * all'alter ego. Accetta `type` = photos | audio | video.
     */
    public function uploadMedia(Request $request, AlterEgo $alterEgo): JsonResponse
    {
        $this->authorizeAlterEgo($request, $alterEgo);

        $request->validate([
            'type'    => 'required|string|in:photos,audio,video',
            'files'   => 'required|array|min:1|max:12',
            'files.*' => 'required|file|max:307200', // 300 MB per file
        ]);

        $type    = (string) $request->input('type');
        $baseDir = 'alter-ego/' . $alterEgo->id . '/' . $type;

        [$field, $mime] = match ($type) {
            'photos' => ['visual_references', 'image/*'],
            'audio'  => ['audio_references', 'audio/*'],
            'video'  => ['video_references', 'video/*'],
        };

        $existing = array_values(array_filter((array) ($alterEgo->$field ?? [])));
        $added    = [];

        foreach ((array) $request->file('files', []) as $file) {
            if (!$file instanceof \Illuminate\Http\UploadedFile) {
                continue;
            }
            $path      = $file->store($baseDir, 'public');
            $existing[] = (string) $path;
            $added[]    = (string) $path;
        }

        $alterEgo->$field = array_values(array_unique($existing));
        $alterEgo->save();

        $urls = array_map(
            fn ($p) => \Storage::disk('public')->url($p),
            $added
        );

        return response()->json(['ok' => true, 'paths' => $added, 'urls' => $urls]);
    }

    /**
     * Rimuove un singolo file media dall'alter ego.
     * Body JSON: { type, path }
     */
    public function destroyMedia(Request $request, AlterEgo $alterEgo): JsonResponse
    {
        $this->authorizeAlterEgo($request, $alterEgo);

        $data = $request->validate([
            'type' => 'required|string|in:photos,audio,video',
            'path' => 'required|string|max:500',
        ]);

        $field    = match ($data['type']) {
            'photos' => 'visual_references',
            'audio'  => 'audio_references',
            'video'  => 'video_references',
        };
        $path     = (string) $data['path'];
        $existing = array_values(array_filter((array) ($alterEgo->$field ?? [])));

        if (in_array($path, $existing, true)) {
            \Storage::disk('public')->delete($path);
            $alterEgo->$field = array_values(array_filter($existing, fn ($p) => $p !== $path));
            $alterEgo->save();
        }

        return response()->json(['ok' => true]);
    }

    private function authorizeAlterEgo(Request $request, AlterEgo $alterEgo): void
    {
        abort_if((int) $alterEgo->tenant_id !== (int) $request->user()->tenant_id, 403);
    }
}
