<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collega AssetVariable al nuovo IdentityAsset.
 *
 * identity_asset_id nullable: le variabili esistenti continuano a funzionare
 * senza IdentityAsset. Quando valorizzato, il pipeline usa IdentityAsset
 * come fonte autoritativa per l'identità visiva invece di identity_pack.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_variables', function (Blueprint $table): void {
            $table->unsignedBigInteger('identity_asset_id')->nullable()->after('canonical_asset_id');

            $table->foreign('identity_asset_id')
                ->references('id')->on('identity_assets')->onDelete('set null');

            $table->index('identity_asset_id');
        });
    }

    public function down(): void
    {
        Schema::table('asset_variables', function (Blueprint $table): void {
            $table->dropForeign(['identity_asset_id']);
            $table->dropIndex(['identity_asset_id']);
            $table->dropColumn('identity_asset_id');
        });
    }
};
