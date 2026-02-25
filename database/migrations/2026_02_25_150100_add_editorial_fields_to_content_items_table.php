<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->string('rubric', 80)->nullable()->after('format');
            $table->string('series_key', 120)->nullable()->after('rubric');
            $table->unsignedInteger('episode_number')->nullable()->after('series_key');
            $table->string('pillar', 120)->nullable()->after('episode_number');
            $table->string('content_angle', 180)->nullable()->after('pillar');
            $table->string('fingerprint', 80)->nullable()->after('content_angle');
            $table->string('similarity_group', 80)->nullable()->after('fingerprint');
            $table->json('source_refs')->nullable()->after('similarity_group');

            $table->index(['tenant_id', 'fingerprint']);
            $table->index(['tenant_id', 'scheduled_at']);
            $table->index(['tenant_id', 'rubric', 'scheduled_at']);
        });

        DB::table('content_items')
            ->whereNull('rubric')
            ->update([
                'rubric' => 'Educational',
                'content_angle' => DB::raw("COALESCE(content_angle, title)"),
            ]);
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'fingerprint']);
            $table->dropIndex(['tenant_id', 'scheduled_at']);
            $table->dropIndex(['tenant_id', 'rubric', 'scheduled_at']);

            $table->dropColumn([
                'rubric',
                'series_key',
                'episode_number',
                'pillar',
                'content_angle',
                'fingerprint',
                'similarity_group',
                'source_refs',
            ]);
        });
    }
};
