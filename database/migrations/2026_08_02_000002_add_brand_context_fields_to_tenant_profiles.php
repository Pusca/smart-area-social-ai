<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_profiles', function (Blueprint $table) {
            // Contesto ricco per la generazione AI
            $table->text('brand_voice')->nullable()->after('cta');
            $table->text('example_posts')->nullable()->after('brand_voice');
            $table->text('visual_style')->nullable()->after('example_posts');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_profiles', function (Blueprint $table) {
            $table->dropColumn(['brand_voice', 'example_posts', 'visual_style']);
        });
    }
};
