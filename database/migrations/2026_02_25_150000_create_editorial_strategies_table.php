<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editorial_strategies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->unique();
            $table->json('brand_voice')->nullable();
            $table->json('pillars')->nullable();
            $table->json('rubrics')->nullable();
            $table->json('cta_rules')->nullable();
            $table->json('constraints')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'last_refreshed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editorial_strategies');
    }
};

