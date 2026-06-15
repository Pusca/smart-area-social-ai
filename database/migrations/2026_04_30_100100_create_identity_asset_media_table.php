<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot tra `identity_assets` e `brand_assets`.
 *
 * Ogni IdentityAsset può avere più media associati con ruoli distinti:
 *
 * canonical  → immagine di riferimento primaria (usata come reference da Veo/Imagen)
 * reference  → immagini di supporto per scoring e selezione
 * negative   → esempi da NON replicare (IdentityGuard: esclusione attiva)
 *
 * sort_order: ordine di preferenza all'interno dello stesso ruolo.
 * provider_hints: provider-specific overrides, es. { "veo": false, "imagen": true }
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_asset_media', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('identity_asset_id');
            $table->unsignedBigInteger('brand_asset_id');

            $table->string('role', 20)->default('reference');
            // canonical | reference | negative

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->json('provider_hints')->nullable();
            // Es. { "veo": false, "imagen": true, "openai": true }

            $table->timestamps();

            $table->foreign('identity_asset_id')
                ->references('id')->on('identity_assets')->onDelete('cascade');
            $table->foreign('brand_asset_id')
                ->references('id')->on('brand_assets')->onDelete('cascade');

            $table->unique(['identity_asset_id', 'brand_asset_id', 'role'], 'identity_media_unique');
            $table->index('identity_asset_id');
            $table->index('brand_asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_asset_media');
    }
};
