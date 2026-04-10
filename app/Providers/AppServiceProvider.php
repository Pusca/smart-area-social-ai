<?php

namespace App\Providers;

use App\Models\BrandAsset;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Policies\BrandAssetPolicy;
use App\Policies\ContentItemPolicy;
use App\Policies\ContentPlanPolicy;
use App\Services\AI\TenantContentIntelligenceService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Su Windows a volte OpenSSL non vede la conf se non la forziamo nel processo PHP
        $conf = env('OPENSSL_CONF');
        if (!empty($conf)) {
            putenv("OPENSSL_CONF={$conf}");
        }

        $this->registerPolicies();
        $this->registerCacheInvalidationHooks();
    }

    /**
     * Registra le policies per tenant isolation.
     *
     * Queste policies garantiscono che ogni utente acceda solo ai dati del proprio tenant.
     * Laravel 12 supporta auto-discovery, ma la registrazione esplicita è più robusta
     * e rende immediatamente visibile la mappa model→policy.
     */
    private function registerPolicies(): void
    {
        Gate::policy(ContentItem::class, ContentItemPolicy::class);
        Gate::policy(ContentPlan::class, ContentPlanPolicy::class);
        Gate::policy(BrandAsset::class, BrandAssetPolicy::class);
    }

    /**
     * Invalida la cache del knowledge pack base quando cambiano dati tenant-wide.
     *
     * Triggered da:
     *   - Nuovo asset brand caricato/eliminato
     *
     * Questo garantisce che la cache non serva dati stantii dopo modifiche significative.
     * Il TTL da solo sarebbe sufficiente per dati poco critici, ma per gli asset
     * (che influenzano direttamente la qualità visuale) preferiamo invalidazione immediata.
     */
    private function registerCacheInvalidationHooks(): void
    {
        // Invalida la cache del knowledge pack quando viene caricato o eliminato un asset brand.
        BrandAsset::created(static function (BrandAsset $asset): void {
            if ($asset->tenant_id) {
                app(TenantContentIntelligenceService::class)
                    ->invalidateTenantBaseCache((int) $asset->tenant_id);
            }
        });

        BrandAsset::deleted(static function (BrandAsset $asset): void {
            if ($asset->tenant_id) {
                app(TenantContentIntelligenceService::class)
                    ->invalidateTenantBaseCache((int) $asset->tenant_id);
            }
        });
    }
}
