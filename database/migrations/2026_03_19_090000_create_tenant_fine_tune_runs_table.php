<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_fine_tune_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('provider', 40)->default('openai');
            $table->string('base_model', 120);
            $table->string('status', 40)->default('draft')->index();
            $table->boolean('is_active')->default(false)->index();
            $table->unsignedInteger('training_examples_count')->default(0);
            $table->unsignedInteger('validation_examples_count')->default(0);
            $table->string('training_dataset_path')->nullable();
            $table->string('validation_dataset_path')->nullable();
            $table->string('training_file_id', 120)->nullable();
            $table->string('validation_file_id', 120)->nullable();
            $table->string('remote_job_id', 120)->nullable()->index();
            $table->string('fine_tuned_model', 160)->nullable();
            $table->json('dataset_stats')->nullable();
            $table->json('request_meta')->nullable();
            $table->json('result_meta')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_fine_tune_runs');
    }
};