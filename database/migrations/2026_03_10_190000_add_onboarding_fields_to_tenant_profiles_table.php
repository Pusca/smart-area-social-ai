<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_profiles', function (Blueprint $table) {
            $table->timestamp('onboarding_started_at')->nullable()->after('completed_at');
            $table->timestamp('onboarding_completed_at')->nullable()->after('onboarding_started_at');
            $table->timestamp('quickstart_generated_at')->nullable()->after('onboarding_completed_at');
            $table->unsignedBigInteger('quickstart_last_plan_id')->nullable()->after('quickstart_generated_at');

            $table->index('quickstart_last_plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_profiles', function (Blueprint $table) {
            $table->dropIndex(['quickstart_last_plan_id']);
            $table->dropColumn([
                'onboarding_started_at',
                'onboarding_completed_at',
                'quickstart_generated_at',
                'quickstart_last_plan_id',
            ]);
        });
    }
};
