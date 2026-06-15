<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canva_export_jobs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('canva_design_id');
            $table->string('external_job_id')->nullable();
            $table->string('export_type', 20);
            $table->string('status', 40)->default('pending');
            $table->text('download_url')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('stored_path')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['canva_design_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canva_export_jobs');
    }
};
