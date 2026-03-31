<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canva_template_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('channel_format', 80);
            $table->string('canva_template_id')->nullable();
            $table->string('canva_template_name')->nullable();
            $table->json('dataset_schema_json')->nullable();
            $table->json('mapping_rules_json')->nullable();
            $table->string('status', 40)->default('inactive');
            $table->text('canva_view_url')->nullable();
            $table->text('canva_create_url')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'channel_format']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canva_template_mappings');
    }
};
