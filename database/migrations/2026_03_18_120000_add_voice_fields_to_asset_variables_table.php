<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_variables', function (Blueprint $table): void {
            $table->unsignedBigInteger('voice_asset_id')->nullable()->after('canonical_asset_id');
            $table->string('voice_provider', 40)->nullable()->after('voice_asset_id');
            $table->string('voice_provider_voice_id', 160)->nullable()->after('voice_provider');
            $table->string('voice_status', 40)->nullable()->after('voice_provider_voice_id');

            $table->index(['voice_asset_id']);
            $table->index(['voice_provider']);
        });
    }

    public function down(): void
    {
        Schema::table('asset_variables', function (Blueprint $table): void {
            $table->dropIndex(['voice_asset_id']);
            $table->dropIndex(['voice_provider']);

            $table->dropColumn([
                'voice_asset_id',
                'voice_provider',
                'voice_provider_voice_id',
                'voice_status',
            ]);
        });
    }
};