<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trend_signals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('trend_snapshot_id')->index();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('platform', 40)->index();
            $table->string('topic', 180)->index();
            $table->string('format_type', 40)->nullable()->index();
            $table->json('hook_patterns')->nullable();
            $table->json('style_notes')->nullable();
            $table->decimal('freshness_score', 5, 4)->nullable();
            $table->decimal('saturation_score', 5, 4)->nullable();
            $table->decimal('estimated_relevance_score', 5, 4)->nullable()->index();
            $table->decimal('brand_fit_score', 5, 4)->nullable()->index();
            $table->decimal('novelty_score', 5, 4)->nullable();
            $table->decimal('execution_feasibility_score', 5, 4)->nullable();
            $table->decimal('viral_potential_score', 5, 4)->nullable();
            $table->json('risk_flags')->nullable();
            $table->string('source_type', 60)->default('config_seed')->index();
            $table->timestamp('observed_at')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'platform', 'estimated_relevance_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trend_signals');
    }
};