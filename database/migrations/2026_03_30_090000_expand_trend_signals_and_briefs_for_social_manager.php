<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trend_signals', function (Blueprint $table): void {
            $table->string('source_ref', 180)->nullable()->after('source_type');
            $table->string('title', 180)->nullable()->after('topic');
            $table->text('summary')->nullable()->after('title');
            $table->decimal('confidence_score', 5, 4)->nullable()->after('freshness_score');
            $table->json('niche_tags')->nullable()->after('confidence_score');
            $table->json('format_tags')->nullable()->after('niche_tags');
            $table->json('platform_tags')->nullable()->after('format_tags');
            $table->timestamp('expires_at')->nullable()->after('observed_at')->index();
            $table->json('evidence_payload')->nullable()->after('meta');
        });

        Schema::table('trend_briefs', function (Blueprint $table): void {
            $table->string('brief_key', 120)->nullable()->after('tenant_id');
            $table->unsignedBigInteger('source_snapshot_id')->nullable()->after('brief_key')->index();
            $table->json('summary')->nullable()->after('snapshot');
            $table->decimal('freshness_score', 5, 4)->nullable()->after('summary');
            $table->decimal('confidence_score', 5, 4)->nullable()->after('freshness_score');
            $table->timestamp('expires_at')->nullable()->after('confidence_score')->index();
        });
    }

    public function down(): void
    {
        Schema::table('trend_briefs', function (Blueprint $table): void {
            $table->dropColumn([
                'brief_key',
                'source_snapshot_id',
                'summary',
                'freshness_score',
                'confidence_score',
                'expires_at',
            ]);
        });

        Schema::table('trend_signals', function (Blueprint $table): void {
            $table->dropColumn([
                'source_ref',
                'title',
                'summary',
                'confidence_score',
                'niche_tags',
                'format_tags',
                'platform_tags',
                'expires_at',
                'evidence_payload',
            ]);
        });
    }
};
