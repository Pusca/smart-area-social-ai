<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generation_runs', function (Blueprint $table): void {
            $table->json('overlay_meta')->nullable()->after('quality_scorecard');
        });
    }

    public function down(): void
    {
        Schema::table('generation_runs', function (Blueprint $table): void {
            $table->dropColumn('overlay_meta');
        });
    }
};
