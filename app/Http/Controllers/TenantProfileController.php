<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeBrandVisuals;
use App\Models\BrandAsset;
use App\Models\TenantProfile;
use App\Services\OpenAiService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TenantProfileController extends Controller
{
    public function show(Request $request)
    {
        $profile = TenantProfile::first();

        $assets = BrandAsset::whereNull('content_plan_id') // assets "di brand" (non legati a un piano)
            ->latest('id')
            ->get();

        return view('wizard.brand', compact('profile', 'assets'));
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'business_name' => 'required|string|max:120',
            'industry' => 'nullable|string|max:120',
            'website' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',

            'services' => 'nullable|string|max:2000',
            'target' => 'nullable|string|max:2000',
            'cta' => 'nullable|string|max:255',

            'brand_voice' => 'nullable|string|max:2000',
            'example_posts' => 'nullable|string|max:6000',

            'default_goal' => 'nullable|string|max:500',
            'default_tone' => 'nullable|string|max:80',
            'default_posts_per_week' => 'nullable|integer|min:1|max:21',
            'default_platforms' => 'nullable|array',
            'default_platforms.*' => 'string|max:50',
            'default_formats' => 'nullable|array',
            'default_formats.*' => 'string|max:50',

            // assets
            'logo' => 'nullable|file|mimes:png,jpg,jpeg,webp,svg|max:4096',
            'images' => 'nullable|array',
            'images.*' => 'file|mimes:png,jpg,jpeg,webp|max:4096',
        ]);

        DB::beginTransaction();
        try {
            TenantProfile::updateOrCreate(
                ['tenant_id' => $user->tenant_id],
                [
                    'business_name' => $data['business_name'],
                    'industry' => $data['industry'] ?? null,
                    'website' => $data['website'] ?? null,
                    'notes' => $data['notes'] ?? null,

                    'services' => $data['services'] ?? null,
                    'target' => $data['target'] ?? null,
                    'cta' => $data['cta'] ?? null,

                    'brand_voice' => $data['brand_voice'] ?? null,
                    'example_posts' => $data['example_posts'] ?? null,

                    'default_goal' => $data['default_goal'] ?? null,
                    'default_tone' => $data['default_tone'] ?? null,
                    'default_posts_per_week' => $data['default_posts_per_week'] ?? null,
                    'default_platforms' => $data['default_platforms'] ?? [],
                    'default_formats' => $data['default_formats'] ?? [],
                    'completed_at' => Carbon::now(),
                ]
            );

            $uploadedSomething = false;
            $baseDir = 'brand-assets/' . $user->tenant_id;

            if ($request->hasFile('logo')) {
                $file = $request->file('logo');
                $path = $file->store($baseDir . '/logo', 'public');

                BrandAsset::create([
                    'tenant_id' => $user->tenant_id,
                    'content_plan_id' => null,
                    'kind' => 'logo',
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime' => $file->getMimeType(),
                ]);

                $uploadedSomething = true;
            }

            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $img) {
                    $path = $img->store($baseDir . '/images', 'public');

                    BrandAsset::create([
                        'tenant_id' => $user->tenant_id,
                        'content_plan_id' => null,
                        'kind' => 'image',
                        'path' => $path,
                        'original_name' => $img->getClientOriginalName(),
                        'size' => $img->getSize(),
                        'mime' => $img->getMimeType(),
                    ]);

                    $uploadedSomething = true;
                }
            }

            DB::commit();

            // Nuove foto → aggiorna la guida di stile visivo usata dagli image prompt
            if ($uploadedSomething) {
                AnalyzeBrandVisuals::dispatch($user->tenant_id);
            }

            return redirect()->route('profile.brand')->with('status', 'Profilo attività salvato ✅');
        } catch (\Throwable $e) {
            DB::rollBack();
            return redirect()->route('profile.brand')->with('status', 'Errore salvataggio ❌: ' . $e->getMessage());
        }
    }

    /**
     * Compila il profilo con l'AI a partire dal sito web dell'attività.
     * Risponde in JSON: la pagina brand riempie i campi via fetch.
     */
    public function prefill(Request $request, OpenAiService $openAi)
    {
        $data = $request->validate([
            'website' => 'required|url:http,https|max:255',
        ]);

        try {
            $res = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; SocialAI/1.0)'])
                ->get($data['website']);

            if (!$res->successful()) {
                return response()->json([
                    'ok' => false,
                    'error' => "Il sito ha risposto con errore {$res->status()}.",
                ], 422);
            }

            $text = $this->extractReadableText($res->body());
            if (mb_strlen($text) < 200) {
                return response()->json([
                    'ok' => false,
                    'error' => 'Il sito contiene troppo poco testo per compilare il profilo.',
                ], 422);
            }

            $profile = $openAi->extractBrandProfile($data['website'], $text);

            return response()->json(['ok' => true, 'data' => $profile]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => 'Analisi non riuscita: ' . Str::limit($e->getMessage(), 200),
            ], 422);
        }
    }

    /**
     * Elimina asset (logo o immagine) del tenant.
     * Il global scope garantisce già che l'asset appartenga al tenant.
     */
    public function destroyAsset(Request $request, BrandAsset $asset)
    {
        // solo asset brand-level (non plan-level)
        if (!is_null($asset->content_plan_id)) {
            abort(403);
        }

        if ($asset->path) {
            Storage::disk('public')->delete($asset->path);
        }

        $asset->delete();

        return redirect()->route('profile.brand')->with('status', 'Asset eliminato ✅');
    }

    private function extractReadableText(string $html): string
    {
        // via script/style, poi tag → spazi, entità decodificate, spazi collassati
        $html = preg_replace('/<(script|style|noscript|svg)\b[^>]*>.*?<\/\1>/si', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return mb_substr(trim($text), 0, 14000);
    }
}
