<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collega AlterEgo a un IdentityAsset (opzionale).
 *
 * Usato quando l'alter ego rappresenta una persona reale con identità
 * visiva lockata (es. CEO, influencer, testimonial). Il pipeline può
 * così applicare le consistency_rules dell'IdentityAsset anche ai
 * contenuti generati con questo alter ego.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alter_egos', function (Blueprint $table): void {
            $table->unsignedBigInteger('identity_asset_id')->nullable()->after('tenant_id');

            $table->foreign('identity_asset_id')
                ->references('id')->on('identity_assets')->onDelete('set null');

            $table->index('identity_asset_id');
        });
    }

    public function down(): void
    {
        Schema::table('alter_egos', function (Blueprint $table): void {
            $table->dropForeign(['identity_asset_id']);
            $table->dropIndex(['identity_asset_id']);
            $table->dropColumn('identity_asset_id');
        });
    }
};
