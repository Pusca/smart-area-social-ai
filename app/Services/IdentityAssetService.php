<?php

namespace App\Services;

use App\Models\BrandAsset;
use App\Models\IdentityAsset;
use App\Services\AI\IdentityPromptCompiler;
use Illuminate\Support\Collection;

/**
 * Gestisce il ciclo di vita degli IdentityAsset:
 * - Creazione e aggiornamento (con invalidazione cache prompt)
 * - Gestione media pivot (canonical/reference/negative)
 * - Linking a AssetVariable e AlterEgo
 * - Compilazione prompt on-demand
 */
class IdentityAssetService
{
    public function __construct(
        private readonly IdentityPromptCompiler $compiler
    ) {
    }

    // ── Creazione ─────────────────────────────────────────────────────────────

    /**
     * Crea un nuovo IdentityAsset e (opzionalmente) attacca i media iniziali.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array{brand_asset_id: int, role: string, sort_order?: int, provider_hints?: array}>  $media
     */
    public function create(int $tenantId, array $data, array $media = []): IdentityAsset
    {
        $identity = IdentityAsset::create(array_merge(
            $this->sanitizeData($data),
            ['tenant_id' => $tenantId]
        ));

        if (! empty($media)) {
            $this->syncMedia($identity, $media);
        }

        // Genera prompt immediatamente se ci sono dati visivi
        if (! empty($identity->visual_features_json) || ! empty($identity->usage_rules_json)) {
            $this->compiler->recompile($identity);
        }

        return $identity->fresh();
    }

    // ── Aggiornamento ─────────────────────────────────────────────────────────

    /**
     * Aggiorna un IdentityAsset e invalida la cache se cambiano i dati visivi.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(IdentityAsset $identity, array $data): IdentityAsset
    {
        $visualFields = ['visual_features_json', 'usage_rules_json', 'consistency_rules_json', 'description', 'locked_visual_identity'];
        $visualChanged = collect($visualFields)
            ->some(fn (string $field) => array_key_exists($field, $data) && $data[$field] !== $identity->$field);

        $identity->fill($this->sanitizeData($data));
        $identity->save();

        if ($visualChanged) {
            $this->compiler->recompile($identity);
        }

        return $identity->fresh();
    }

    // ── Gestione media pivot ───────────────────────────────────────────────────

    /**
     * Aggiunge un BrandAsset all'IdentityAsset con un ruolo specifico.
     *
     * @param  array<string, mixed>  $pivotData  Campi aggiuntivi (provider_hints, sort_order)
     */
    public function attachMedia(
        IdentityAsset $identity,
        BrandAsset $asset,
        string $role = IdentityAsset::MEDIA_ROLE_REFERENCE,
        array $pivotData = []
    ): void {
        $this->assertValidRole($role);

        // Se il ruolo è canonical, rimuove eventuali canonical esistenti
        // (massimo 1 per IdentityAsset — politica opinionata ma sicura)
        if ($role === IdentityAsset::MEDIA_ROLE_CANONICAL) {
            $identity->canonicalMedia()->detach();
        }

        $identity->media()->attach($asset->id, array_merge([
            'role'          => $role,
            'sort_order'    => $pivotData['sort_order'] ?? 0,
            'provider_hints' => isset($pivotData['provider_hints'])
                ? json_encode($pivotData['provider_hints'])
                : null,
        ]));

        // Il canonical cambia → il prompt potrebbe cambiare
        if ($role === IdentityAsset::MEDIA_ROLE_CANONICAL) {
            $this->compiler->invalidate($identity);
        }
    }

    /**
     * Rimuove un BrandAsset dall'IdentityAsset (qualsiasi ruolo).
     */
    public function detachMedia(IdentityAsset $identity, BrandAsset $asset): void
    {
        $identity->media()->detach($asset->id);
        $this->compiler->invalidate($identity);
    }

    /**
     * Rimpiazza completamente i media per un dato ruolo.
     *
     * @param  array<int, array{brand_asset_id: int, sort_order?: int, provider_hints?: array}>  $items
     */
    public function replaceMediaForRole(IdentityAsset $identity, string $role, array $items): void
    {
        $this->assertValidRole($role);

        // Rimuove tutti i media con questo ruolo
        $identity->media()
            ->wherePivot('role', $role)
            ->detach();

        // Riattacca i nuovi
        foreach ($items as $item) {
            $assetId = (int) ($item['brand_asset_id'] ?? 0);
            if ($assetId < 1) {
                continue;
            }
            $identity->media()->attach($assetId, [
                'role'          => $role,
                'sort_order'    => (int) ($item['sort_order'] ?? 0),
                'provider_hints' => isset($item['provider_hints'])
                    ? json_encode($item['provider_hints'])
                    : null,
            ]);
        }

        $this->compiler->invalidate($identity);
    }

    /**
     * Sincronizza i media dell'IdentityAsset da un array completo.
     * Sostituisce interamente tutti i media (tutti i ruoli).
     *
     * @param  array<int, array{brand_asset_id: int, role: string, sort_order?: int, provider_hints?: array}>  $items
     */
    public function syncMedia(IdentityAsset $identity, array $items): void
    {
        $identity->media()->detach();

        foreach ($items as $item) {
            $assetId = (int) ($item['brand_asset_id'] ?? 0);
            $role = (string) ($item['role'] ?? IdentityAsset::MEDIA_ROLE_REFERENCE);
            if ($assetId < 1) {
                continue;
            }
            $this->assertValidRole($role);
            $identity->media()->attach($assetId, [
                'role'          => $role,
                'sort_order'    => (int) ($item['sort_order'] ?? 0),
                'provider_hints' => isset($item['provider_hints'])
                    ? json_encode($item['provider_hints'])
                    : null,
            ]);
        }

        $this->compiler->invalidate($identity);
    }

    // ── Linking ───────────────────────────────────────────────────────────────

    /**
     * Collega un AssetVariable a questo IdentityAsset.
     * Sostituisce il link precedente se presente.
     */
    public function linkToAssetVariable(\App\Models\AssetVariable $variable, IdentityAsset $identity): void
    {
        $variable->identity_asset_id = $identity->id;
        $variable->saveQuietly();
    }

    /**
     * Scollega un AssetVariable dall'IdentityAsset.
     */
    public function unlinkFromAssetVariable(\App\Models\AssetVariable $variable): void
    {
        $variable->identity_asset_id = null;
        $variable->saveQuietly();
    }

    /**
     * Collega un AlterEgo a questo IdentityAsset.
     */
    public function linkToAlterEgo(\App\Models\AlterEgo $alterEgo, IdentityAsset $identity): void
    {
        $alterEgo->identity_asset_id = $identity->id;
        $alterEgo->saveQuietly();
    }

    // ── Compilazione prompt ───────────────────────────────────────────────────

    /**
     * Restituisce il prompt compilato (usa cache se valida, altrimenti rigenera).
     */
    public function compiledPrompt(IdentityAsset $identity): string
    {
        return $this->compiler->compiledPrompt($identity);
    }

    /**
     * Forza la rigenerazione del prompt.
     */
    public function recompilePrompt(IdentityAsset $identity): void
    {
        $this->compiler->recompile($identity);
    }

    // ── Query helpers ─────────────────────────────────────────────────────────

    /**
     * Restituisce tutti gli IdentityAsset attivi di un tenant, ordinati per tipo e nome.
     *
     * @return Collection<int, IdentityAsset>
     */
    public function forTenant(int $tenantId): Collection
    {
        return IdentityAsset::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('type')
            ->orderBy('name')
            ->get();
    }

    /**
     * Restituisce gli IdentityAsset di un tipo specifico per un tenant.
     *
     * @return Collection<int, IdentityAsset>
     */
    public function forTenantOfType(int $tenantId, string $type): Collection
    {
        return IdentityAsset::query()
            ->where('tenant_id', $tenantId)
            ->where('type', $type)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * Pulisce e valida i dati in input, rimuovendo campi non fillable o pericolosi.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitizeData(array $data): array
    {
        // Non permettere di impostare tenant_id dall'esterno del metodo create
        unset($data['tenant_id'], $data['identity_prompt'], $data['identity_prompt_version']);

        // Normalizza il tipo
        if (isset($data['type'])) {
            $validTypes = [
                IdentityAsset::TYPE_PERSON,
                IdentityAsset::TYPE_PRODUCT,
                IdentityAsset::TYPE_LOCATION,
                IdentityAsset::TYPE_BRAND_OBJECT,
            ];
            if (! in_array($data['type'], $validTypes, true)) {
                $data['type'] = IdentityAsset::TYPE_PERSON;
            }
        }

        // Normalizza json fields: se arrivano come stringa, decodifica
        foreach (['visual_features_json', 'usage_rules_json', 'consistency_rules_json'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $decoded = json_decode($data[$field], true);
                $data[$field] = is_array($decoded) ? $decoded : null;
            }
        }

        return $data;
    }

    private function assertValidRole(string $role): void
    {
        $valid = [
            IdentityAsset::MEDIA_ROLE_CANONICAL,
            IdentityAsset::MEDIA_ROLE_REFERENCE,
            IdentityAsset::MEDIA_ROLE_NEGATIVE,
        ];
        if (! in_array($role, $valid, true)) {
            throw new \InvalidArgumentException("Ruolo media non valido: {$role}. Valori ammessi: " . implode(', ', $valid));
        }
    }
}
