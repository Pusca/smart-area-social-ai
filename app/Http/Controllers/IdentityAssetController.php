<?php

namespace App\Http\Controllers;

use App\Models\AlterEgo;
use App\Models\AssetVariable;
use App\Models\BrandAsset;
use App\Models\IdentityAsset;
use App\Services\IdentityAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IdentityAssetController extends Controller
{
    public function __construct(
        private readonly IdentityAssetService $service
    ) {
    }

    public function index(Request $request): View
    {
        $tenantId  = (int) $request->user()->tenant_id;
        $identities = IdentityAsset::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->withCount(['assetVariables', 'alterEgos'])
            ->with('canonicalMedia')
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        return view('identity-assets.index', compact('identities'));
    }

    public function create(): View
    {
        return view('identity-assets.create');
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $data = $request->validate([
            'name'                   => 'required|string|max:150',
            'type'                   => 'required|string|in:person,product,location,brand_object',
            'description'            => 'nullable|string|max:1000',
            'locked_visual_identity' => 'boolean',
            'visual_features_json'   => 'nullable|array',
            'usage_rules_json'       => 'nullable|array',
            'consistency_rules_json' => 'nullable|array',
        ]);

        $identity = $this->service->create($tenantId, $data);

        if ($request->wantsJson()) {
            return response()->json([
                'ok'           => true,
                'id'           => $identity->id,
                'redirect_url' => route('identity-assets.show', $identity),
            ]);
        }

        return redirect()->route('identity-assets.show', $identity)
            ->with('success', "Identità \"{$identity->name}\" creata. Ora aggiungi i media di riferimento.");
    }

    public function show(Request $request, IdentityAsset $identityAsset): View
    {
        $this->authorizeIdentity($request, $identityAsset);
        $tenantId = (int) $request->user()->tenant_id;

        $identityAsset->load(['canonicalMedia', 'referenceMedia', 'negativeMedia', 'assetVariables', 'alterEgos']);

        // AssetVariable del tenant non ancora collegate
        $unlinkable = AssetVariable::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('identity_asset_id')->orWhere('identity_asset_id', '!=', $identityAsset->id))
            ->orderBy('name')
            ->get(['id', 'name', 'kind', 'identity_asset_id']);

        // AlterEgo del tenant non ancora collegati
        $unlinkableAlterEgos = AlterEgo::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('identity_asset_id')->orWhere('identity_asset_id', '!=', $identityAsset->id))
            ->orderBy('name')
            ->get(['id', 'name', 'identity_asset_id']);

        // BrandAsset disponibili per aggiungere come media
        $brandAssets = BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('content_plan_id')
            ->whereIn('kind', ['image', 'logo'])
            ->orderByDesc('created_at')
            ->get(['id', 'path', 'original_name', 'kind', 'ai_description']);

        return view('identity-assets.show', compact('identityAsset', 'unlinkable', 'unlinkableAlterEgos', 'brandAssets'));
    }

    public function edit(Request $request, IdentityAsset $identityAsset): View
    {
        $this->authorizeIdentity($request, $identityAsset);
        return view('identity-assets.edit', compact('identityAsset'));
    }

    public function update(Request $request, IdentityAsset $identityAsset): RedirectResponse
    {
        $this->authorizeIdentity($request, $identityAsset);

        $data = $request->validate([
            'name'                   => 'required|string|max:150',
            'type'                   => 'required|string|in:person,product,location,brand_object',
            'description'            => 'nullable|string|max:1000',
            'locked_visual_identity' => 'boolean',
            'visual_features_json'   => 'nullable|array',
            'usage_rules_json'       => 'nullable|array',
            'consistency_rules_json' => 'nullable|array',
        ]);

        $this->service->update($identityAsset, $data);

        return redirect()->route('identity-assets.show', $identityAsset)
            ->with('success', "Identità \"{$identityAsset->name}\" aggiornata.");
    }

    public function destroy(Request $request, IdentityAsset $identityAsset): RedirectResponse
    {
        $this->authorizeIdentity($request, $identityAsset);
        $name = $identityAsset->name;

        // Scollega AssetVariable e AlterEgo prima di eliminare (la FK fa set null, ma è esplicito)
        AssetVariable::query()->where('identity_asset_id', $identityAsset->id)->update(['identity_asset_id' => null]);
        AlterEgo::query()->where('identity_asset_id', $identityAsset->id)->update(['identity_asset_id' => null]);

        $identityAsset->delete();

        return redirect()->route('identity-assets.index')
            ->with('success', "Identità \"{$name}\" eliminata.");
    }

    // ── Media ─────────────────────────────────────────────────────────────────

    public function attachMedia(Request $request, IdentityAsset $identityAsset): JsonResponse
    {
        $this->authorizeIdentity($request, $identityAsset);

        $data = $request->validate([
            'brand_asset_id' => 'required|integer|exists:brand_assets,id',
            'role'           => 'required|string|in:canonical,reference,negative',
            'sort_order'     => 'nullable|integer|min:0',
            'provider_hints' => 'nullable|array',
        ]);

        // Verifica che il BrandAsset appartenga al tenant
        $asset = BrandAsset::where('id', $data['brand_asset_id'])
            ->where('tenant_id', (int) $request->user()->tenant_id)
            ->firstOrFail();

        $this->service->attachMedia(
            $identityAsset,
            $asset,
            $data['role'],
            ['sort_order' => $data['sort_order'] ?? 0, 'provider_hints' => $data['provider_hints'] ?? null]
        );

        return response()->json(['ok' => true]);
    }

    public function detachMedia(Request $request, IdentityAsset $identityAsset): JsonResponse
    {
        $this->authorizeIdentity($request, $identityAsset);

        $data = $request->validate([
            'brand_asset_id' => 'required|integer|exists:brand_assets,id',
        ]);

        $asset = BrandAsset::where('id', $data['brand_asset_id'])
            ->where('tenant_id', (int) $request->user()->tenant_id)
            ->firstOrFail();

        $this->service->detachMedia($identityAsset, $asset);

        return response()->json(['ok' => true]);
    }

    // ── Link / Unlink ─────────────────────────────────────────────────────────

    public function linkVariable(Request $request, IdentityAsset $identityAsset): JsonResponse
    {
        $this->authorizeIdentity($request, $identityAsset);
        $tenantId = (int) $request->user()->tenant_id;

        $data = $request->validate([
            'asset_variable_id' => 'required|integer|exists:asset_variables,id',
        ]);

        $variable = AssetVariable::where('id', $data['asset_variable_id'])
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $this->service->linkToAssetVariable($variable, $identityAsset);

        return response()->json(['ok' => true, 'variable_name' => $variable->name]);
    }

    public function unlinkVariable(Request $request, IdentityAsset $identityAsset): JsonResponse
    {
        $this->authorizeIdentity($request, $identityAsset);
        $tenantId = (int) $request->user()->tenant_id;

        $data = $request->validate([
            'asset_variable_id' => 'required|integer|exists:asset_variables,id',
        ]);

        $variable = AssetVariable::where('id', $data['asset_variable_id'])
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $this->service->unlinkFromAssetVariable($variable);

        return response()->json(['ok' => true]);
    }

    public function linkAlterEgo(Request $request, IdentityAsset $identityAsset): JsonResponse
    {
        $this->authorizeIdentity($request, $identityAsset);
        $tenantId = (int) $request->user()->tenant_id;

        $data = $request->validate([
            'alter_ego_id' => 'required|integer|exists:alter_egos,id',
        ]);

        $alterEgo = AlterEgo::where('id', $data['alter_ego_id'])
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $this->service->linkToAlterEgo($alterEgo, $identityAsset);

        return response()->json(['ok' => true, 'alter_ego_name' => $alterEgo->name]);
    }

    public function unlinkAlterEgo(Request $request, IdentityAsset $identityAsset): JsonResponse
    {
        $this->authorizeIdentity($request, $identityAsset);
        $tenantId = (int) $request->user()->tenant_id;

        $data = $request->validate([
            'alter_ego_id' => 'required|integer|exists:alter_egos,id',
        ]);

        $alterEgo = AlterEgo::where('id', $data['alter_ego_id'])
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $alterEgo->identity_asset_id = null;
        $alterEgo->saveQuietly();

        return response()->json(['ok' => true]);
    }

    // ── Recompile prompt ──────────────────────────────────────────────────────

    public function recompilePrompt(Request $request, IdentityAsset $identityAsset): JsonResponse
    {
        $this->authorizeIdentity($request, $identityAsset);
        $this->service->recompilePrompt($identityAsset);
        $identityAsset->refresh();

        return response()->json([
            'ok'      => true,
            'version' => $identityAsset->identity_prompt_version,
            'preview' => \Illuminate\Support\Str::limit($identityAsset->identity_prompt ?? '', 300),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function authorizeIdentity(Request $request, IdentityAsset $identity): void
    {
        abort_if((int) $identity->tenant_id !== (int) $request->user()->tenant_id, 403);
    }
}
