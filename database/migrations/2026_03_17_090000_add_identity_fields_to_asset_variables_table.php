<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_variables', function (Blueprint $table): void {
            // Questi campi rendono la variabile un'identita persistente e non solo un tag.
            $table->string('asset_role', 60)->nullable()->after('kind');
            $table->unsignedBigInteger('canonical_asset_id')->nullable()->after('asset_ids');
            $table->string('identity_mode', 20)->default('balanced')->after('canonical_asset_id');
            $table->unsignedTinyInteger('consistency_threshold')->nullable()->after('identity_mode');

            $table->index(['asset_role']);
            $table->index(['canonical_asset_id']);
            $table->index(['identity_mode']);
        });
    }

    public function down(): void
    {
        Schema::table('asset_variables', function (Blueprint $table): void {
            $table->dropIndex(['asset_role']);
            $table->dropIndex(['canonical_asset_id']);
            $table->dropIndex(['identity_mode']);

            $table->dropColumn([
                'asset_role',
                'canonical_asset_id',
                'identity_mode',
                'consistency_threshold',
            ]);
        });
    }
};
