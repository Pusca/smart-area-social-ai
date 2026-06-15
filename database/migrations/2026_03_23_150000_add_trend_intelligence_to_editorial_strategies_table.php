<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('editorial_strategies', function (Blueprint $table): void {
            $table->json('trend_intelligence')->nullable()->after('creative_direction');
        });
    }

    public function down(): void
    {
        Schema::table('editorial_strategies', function (Blueprint $table): void {
            $table->dropColumn('trend_intelligence');
        });
    }
};