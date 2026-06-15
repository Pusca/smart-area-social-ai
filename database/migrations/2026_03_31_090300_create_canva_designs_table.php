<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canva_designs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('content_item_id')->nullable();
            $table->unsignedBigInteger('content_plan_id')->nullable();
            $table->unsignedBigInteger('canva_template_mapping_id')->nullable();
            $table->string('design_type', 80);
            $table->string('canva_design_id')->nullable();
            $table->text('canva_edit_url')->nullable();
            $table->text('canva_view_url')->nullable();
            $table->string('template_id')->nullable();
            $table->string('source_mode', 40)->default('fallback_manual');
            $table->json('generation_payload_json')->nullable();
            $table->string('status', 40)->default('pending');
            $table->text('thumbnail_url')->nullable();
            $table->string('exported_asset_path')->nullable();
            $table->string('exported_file_type', 20)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'design_type']);
            $table->index(['content_item_id']);
            $table->index(['content_plan_id']);
            $table->index(['canva_design_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canva_designs');
    }
};
