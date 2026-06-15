<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_assets', function (Blueprint $table): void {
            $table->json('meta')->nullable()->after('mime');
        });

        Schema::table('asset_variables', function (Blueprint $table): void {
            $table->json('profile')->nullable()->after('asset_ids');
        });
    }

    public function down(): void
    {
        Schema::table('asset_variables', function (Blueprint $table): void {
            $table->dropColumn('profile');
        });

        Schema::table('brand_assets', function (Blueprint $table): void {
            $table->dropColumn('meta');
        });
    }
};
