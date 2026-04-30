<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabella `identity_assets` — entità centrale del sistema identità.
 *
 * Un IdentityAsset rappresenta un soggetto visivo persistente (persona,
 * prodotto, luogo, oggetto brand) con regole di consistenza visiva lockate.
 * Sostituisce il pattern duplicato AssetVariable+identity_pack come unica
 * fonte di verità per l'identità visiva usata dal pipeline AI.
 *
 * locked_visual_identity: se true, l'IdentityGuard blocca output che
 * non rispettano le consistency_rules. Se false, applica solo warning.
 *
 * identity_prompt: blocco precompilato pronto per l'injection nel pipeline.
 * Si invalida (identity_prompt_version++) ad ogni modifica dei campi
 * visual_features_json, consistency_rules_json, usage_rules_json.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_assets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');

            // Classificazione
            $table->string('type', 40)->default('person');
            // person | product | location | brand_object

            $table->string('name', 150);
            $table->text('description')->nullable();

            // Lock identità visiva
            $table->boolean('locked_visual_identity')->default(false);
            // Se true → IdentityGuard rigido (blocca), se false → solo warning

            // Prompt AI precompilato
            $table->text('identity_prompt')->nullable();
            $table->unsignedSmallInteger('identity_prompt_version')->default(0);

            // Caratteristiche visive invarianti (per IdentityGuard)
            // Es. persona: { "hair": "black", "skin": "medium", "eyes": "brown", "build": "athletic" }
            // Es. prodotto: { "color": "red", "shape": "cylindrical", "logo_position": "center" }
            $table->json('visual_features_json')->nullable();

            // Regole di utilizzo per il pipeline (iniettate nel prompt)
            // Es. { "always_show_face": true, "preferred_angle": "three_quarter", "avoid": ["profile"] }
            $table->json('usage_rules_json')->nullable();

            // Regole di validazione per IdentityGuard
            // Es. { "min_confidence": 0.78, "required_features": ["hair","face"], "blocking": true }
            $table->json('consistency_rules_json')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index('tenant_id');
            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_assets');
    }
};
