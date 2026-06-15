<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trend_briefs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->json('snapshot')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique('tenant_id');
            $table->index(['tenant_id', 'fetched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trend_briefs');
    }
};

