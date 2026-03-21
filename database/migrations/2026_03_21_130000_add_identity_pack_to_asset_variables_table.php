<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_variables', function (Blueprint $table): void {
            $table->json('identity_pack')->nullable()->after('profile');
        });
    }

    public function down(): void
    {
        Schema::table('asset_variables', function (Blueprint $table): void {
            $table->dropColumn('identity_pack');
        });
    }
};
