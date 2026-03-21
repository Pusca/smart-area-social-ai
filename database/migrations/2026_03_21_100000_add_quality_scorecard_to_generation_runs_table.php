<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generation_runs', function (Blueprint $table): void {
            $table->json('quality_scorecard')->nullable()->after('result_summary');
        });
    }

    public function down(): void
    {
        Schema::table('generation_runs', function (Blueprint $table): void {
            $table->dropColumn('quality_scorecard');
        });
    }
};
