<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trend_ingestion_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('run_key', 120)->unique();
            $table->string('source_type', 80)->default('mixed')->index();
            $table->string('status', 40)->default('running')->index();
            $table->unsignedInteger('signals_count')->default(0);
            $table->decimal('freshness_score', 5, 4)->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->json('context')->nullable();
            $table->json('result_summary')->nullable();
            $table->json('meta')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trend_ingestion_runs');
    }
};
