<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aggiunge `platform_adaptations` a `alter_egos`.
 *
 * Struttura JSON:
 * {
 *   "linkedin":  { "modifier": "Più professionale, niente emoji" },
 *   "instagram": { "modifier": null },
 *   "tiktok":    { "modifier": "Energico, ritmo veloce" },
 *   "facebook":  { "modifier": "Narrativo, con domanda finale" }
 * }
 *
 * Se modifier è null/vuoto, nessun adattamento applicato per quella piattaforma.
 * Il persona_prompt_cache non include le adaptations (compilate on-the-fly per piattaforma).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alter_egos', function (Blueprint $table): void {
            $table->json('platform_adaptations')->nullable()->after('training_samples');
        });
    }

    public function down(): void
    {
        Schema::table('alter_egos', function (Blueprint $table): void {
            $table->dropColumn('platform_adaptations');
        });
    }
};
