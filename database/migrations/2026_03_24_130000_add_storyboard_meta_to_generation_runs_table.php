<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generation_runs', function (Blueprint $table): void {
            $table->json('storyboard_meta')->nullable()->after('overlay_meta');
        });
    }

    public function down(): void
    {
        Schema::table('generation_runs', function (Blueprint $table): void {
            $table->dropColumn('storyboard_meta');
        });
    }
};
