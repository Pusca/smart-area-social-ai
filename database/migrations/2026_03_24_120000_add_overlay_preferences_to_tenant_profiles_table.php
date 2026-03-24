<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_profiles', function (Blueprint $table): void {
            $table->json('overlay_preferences')->nullable()->after('brand_palette');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_profiles', function (Blueprint $table): void {
            $table->dropColumn('overlay_preferences');
        });
    }
};
