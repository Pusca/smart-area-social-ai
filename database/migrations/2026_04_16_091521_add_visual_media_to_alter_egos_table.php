<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alter_egos', function (Blueprint $table): void {
            // Foto di riferimento della persona (array di path su storage::public)
            $table->json('visual_references')->nullable()->after('platform_adaptations');
            // Campioni audio (array di path)
            $table->json('audio_references')->nullable()->after('visual_references');
            // Campioni video (array di path)
            $table->json('video_references')->nullable()->after('audio_references');
        });
    }

    public function down(): void
    {
        Schema::table('alter_egos', function (Blueprint $table): void {
            $table->dropColumn(['visual_references', 'audio_references', 'video_references']);
        });
    }
};
