<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_profiles', function (Blueprint $table): void {
            $table->json('learning_preferences')->nullable()->after('overlay_preferences');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_profiles', function (Blueprint $table): void {
            $table->dropColumn('learning_preferences');
        });
    }
};
