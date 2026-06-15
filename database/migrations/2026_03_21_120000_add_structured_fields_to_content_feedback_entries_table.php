<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_feedback_entries', function (Blueprint $table): void {
            $table->string('normalized_category', 80)->nullable()->after('category');
            $table->string('severity', 20)->nullable()->after('scope');
            $table->json('scores')->nullable()->after('action');

            $table->index(['tenant_id', 'normalized_category', 'created_at'], 'content_feedback_tenant_normcat_created_idx');
            $table->index(['tenant_id', 'severity', 'created_at'], 'content_feedback_tenant_severity_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('content_feedback_entries', function (Blueprint $table): void {
            $table->dropIndex('content_feedback_tenant_normcat_created_idx');
            $table->dropIndex('content_feedback_tenant_severity_created_idx');
            $table->dropColumn(['normalized_category', 'severity', 'scores']);
        });
    }
};
