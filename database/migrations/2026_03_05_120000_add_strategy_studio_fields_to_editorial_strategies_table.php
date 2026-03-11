<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('editorial_strategies', function (Blueprint $table) {
            $table->json('analysis_framework')->nullable()->after('constraints');
            $table->json('visual_system')->nullable()->after('analysis_framework');
            $table->json('publishing_system')->nullable()->after('visual_system');
            $table->text('strategy_notes')->nullable()->after('publishing_system');
            $table->boolean('is_locked')->default(false)->after('strategy_notes');
            $table->timestamp('manual_updated_at')->nullable()->after('is_locked');
        });
    }

    public function down(): void
    {
        Schema::table('editorial_strategies', function (Blueprint $table) {
            $table->dropColumn([
                'analysis_framework',
                'visual_system',
                'publishing_system',
                'strategy_notes',
                'is_locked',
                'manual_updated_at',
            ]);
        });
    }
};
