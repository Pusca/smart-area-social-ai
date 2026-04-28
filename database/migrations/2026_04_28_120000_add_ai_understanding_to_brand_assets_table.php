<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_assets', function (Blueprint $table) {
            $table->text('ai_description')->nullable()->after('mime');
            $table->string('ai_context', 120)->nullable()->after('ai_description');
            $table->json('ai_tags')->nullable()->after('ai_context');
            $table->timestamp('ai_analyzed_at')->nullable()->after('ai_tags');
        });
    }

    public function down(): void
    {
        Schema::table('brand_assets', function (Blueprint $table) {
            $table->dropColumn(['ai_description', 'ai_context', 'ai_tags', 'ai_analyzed_at']);
        });
    }
};
