<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabella `alter_egos` per il feature Alter Ego Digitale.
 *
 * Ogni alter ego rappresenta una voce/persona narrativa persistente
 * associata a un tenant. Può essere applicato a contenuti singoli
 * o usato come voce default dell'intero workspace.
 *
 * persona_prompt_cache: blocco di istruzioni compilato pronto per
 * essere iniettato nel context del pipeline di generazione AI.
 * Si invalida automaticamente (persona_prompt_version++) ad ogni modifica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alter_egos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');

            // Identità
            $table->string('name', 150);
            $table->string('archetype', 60)->default('thought_leader');
            // thought_leader | educator | storyteller | entertainer | provocateur | connector

            // Voce
            $table->string('tone', 80)->nullable();
            // autorevole | diretto | empatico | ironico | formale | colloquiale
            $table->string('sentence_style', 80)->nullable();
            // brevi_incisive | narrative | domande_retoriche
            $table->string('vocabulary_level', 60)->default('misto');
            // tecnico | accessibile | misto

            // Personalità contenuto
            $table->json('signature_phrases')->nullable();   // array di stringhe
            $table->json('topics_owned')->nullable();         // temi di proprietà
            $table->json('topics_avoided')->nullable();       // temi da evitare
            $table->json('content_pillars')->nullable();      // [{name, weight}]
            $table->text('unique_perspective')->nullable();   // cosa lo rende unico

            // Relazione col pubblico
            $table->string('audience_role', 80)->nullable();
            // teacher | peer | mentor | challenger | entertainer
            $table->string('cta_style', 60)->nullable();
            // soft | direct | domanda | nessuna

            // Training & cache AI
            $table->json('training_samples')->nullable();       // array di testi-campione
            $table->text('persona_prompt_cache')->nullable();   // system-prompt compilato
            $table->unsignedSmallInteger('persona_prompt_version')->default(0);

            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index('tenant_id');
            $table->index(['tenant_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alter_egos');
    }
};
