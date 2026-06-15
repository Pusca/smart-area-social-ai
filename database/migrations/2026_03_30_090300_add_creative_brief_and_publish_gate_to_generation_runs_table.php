<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generation_runs', function (Blueprint $table): void {
            $table->json('creative_brief')->nullable()->after('quality_scorecard');
            $table->json('identity_validation')->nullable()->after('creative_brief');
            $table->json('publish_gate')->nullable()->after('identity_validation');
        });
    }

    public function down(): void
    {
        Schema::table('generation_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'creative_brief',
                'identity_validation',
                'publish_gate',
            ]);
        });
    }
};
