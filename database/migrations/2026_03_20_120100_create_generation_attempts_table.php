<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generation_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('generation_run_id')->index();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('content_item_id')->index();
            $table->unsignedBigInteger('parent_attempt_id')->nullable()->index();
            $table->unsignedInteger('sequence')->default(1);
            $table->string('type', 40)->nullable()->index();
            $table->string('stage', 60)->nullable()->index();
            $table->string('step', 60)->index();
            $table->string('status', 40)->default('running')->index();
            $table->string('provider_requested', 40)->nullable();
            $table->string('provider_effective', 40)->nullable();
            $table->string('model_requested', 120)->nullable();
            $table->string('model_effective', 120)->nullable();
            $table->boolean('provider_locked')->default(false);
            $table->string('request_mode', 40)->nullable();
            $table->json('input_summary')->nullable();
            $table->string('input_hash', 64)->nullable()->index();
            $table->json('output_summary')->nullable();
            $table->json('output_references')->nullable();
            $table->unsignedSmallInteger('requested_duration_seconds')->nullable();
            $table->unsignedSmallInteger('normalized_duration_seconds')->nullable();
            $table->unsignedInteger('retry_index')->default(0);
            $table->string('external_request_id', 160)->nullable()->index();
            $table->string('external_response_id', 160)->nullable()->index();
            $table->string('error_code', 120)->nullable();
            $table->text('error_message')->nullable();
            $table->decimal('estimated_cost_usd', 10, 4)->nullable();
            $table->decimal('actual_cost_usd', 10, 4)->nullable();
            $table->json('token_usage')->nullable();
            $table->boolean('fallback_used')->default(false)->index();
            $table->boolean('downgrade_used')->default(false)->index();
            $table->unsignedSmallInteger('segment_count')->default(0);
            $table->string('final_provider', 40)->nullable()->index();
            $table->string('failure_mode', 120)->nullable()->index();
            $table->unsignedInteger('runtime_ms')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['generation_run_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_attempts');
    }
};
