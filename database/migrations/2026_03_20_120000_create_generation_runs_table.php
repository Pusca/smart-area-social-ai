<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generation_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('content_item_id')->index();
            $table->unsignedBigInteger('content_plan_id')->nullable()->index();
            $table->string('run_key', 80)->unique();
            $table->string('scope', 40)->default('content_item');
            $table->string('trigger_source', 40)->default('job');
            $table->string('status', 40)->default('running')->index();
            $table->string('format', 40)->nullable();
            $table->string('platform', 120)->nullable();
            $table->json('requested_provider_matrix')->nullable();
            $table->json('resolved_provider_matrix')->nullable();
            $table->json('requested_output')->nullable();
            $table->json('effective_output')->nullable();
            $table->json('version_meta')->nullable();
            $table->json('result_summary')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('retry_count')->default(0);
            $table->decimal('estimated_cost_usd', 10, 4)->nullable();
            $table->decimal('actual_cost_usd', 10, 4)->nullable();
            $table->json('token_usage')->nullable();
            $table->boolean('fallback_used')->default(false)->index();
            $table->boolean('downgrade_used')->default(false)->index();
            $table->unsignedSmallInteger('segment_count')->default(0);
            $table->string('final_provider', 40)->nullable()->index();
            $table->string('failure_mode', 120)->nullable()->index();
            $table->unsignedInteger('runtime_ms')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_runs');
    }
};
