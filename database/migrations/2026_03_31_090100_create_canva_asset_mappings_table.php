<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canva_asset_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('brand_asset_id')->nullable();
            $table->unsignedBigInteger('content_item_id')->nullable();
            $table->string('canva_asset_id')->nullable();
            $table->string('asset_kind', 60);
            $table->string('source_path')->nullable();
            $table->string('sync_status', 40)->default('pending');
            $table->timestamp('synced_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'asset_kind']);
            $table->index(['brand_asset_id']);
            $table->index(['content_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canva_asset_mappings');
    }
};
