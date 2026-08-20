<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            // Un post può esistere anche senza piano (creazione manuale)
            $table->unsignedBigInteger('content_plan_id')->nullable()->change();
            $table->string('ai_status')->default('idle')->change();
        });

        // Normalizza gli stati legacy sul nuovo enum AiStatus
        DB::table('content_items')->where('ai_status', 'draft')->update(['ai_status' => 'idle']);
        DB::table('content_items')->where('ai_status', 'pending')->update(['ai_status' => 'generating']);
        DB::table('content_items')->where('ai_status', 'ready')->update(['ai_status' => 'done']);
        DB::table('content_items')
            ->whereNotIn('ai_status', ['idle', 'queued', 'generating', 'done', 'error'])
            ->update(['ai_status' => 'idle']);
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->unsignedBigInteger('content_plan_id')->nullable(false)->change();
            $table->string('ai_status')->default('draft')->change();
        });
    }
};
