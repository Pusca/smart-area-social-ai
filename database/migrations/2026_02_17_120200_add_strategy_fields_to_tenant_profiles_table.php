<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_profiles', function (Blueprint $table) {
            $table->text('vision')->nullable()->after('notes');
            $table->text('values')->nullable()->after('vision');
            $table->text('business_hours')->nullable()->after('values');
            $table->text('seasonal_offers')->nullable()->after('business_hours');
            $table->string('brand_palette', 255)->nullable()->after('seasonal_offers');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'vision',
                'values',
                'business_hours',
                'seasonal_offers',
                'brand_palette',
            ]);
        });
    }
};
