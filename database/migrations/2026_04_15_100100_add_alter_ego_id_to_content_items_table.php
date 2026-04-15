<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aggiunge `alter_ego_id` a `content_items`.
 *
 * Nullable: la maggior parte dei contenuti esistenti non ha un alter ego.
 * onDelete('set null'): se l'alter ego viene eliminato, i contenuti non vengono
 * eliminati — si genera semplicemente senza voce personalizzata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('alter_ego_id')->nullable()->after('tenant_id');
            $table->foreign('alter_ego_id')->references('id')->on('alter_egos')->onDelete('set null');
            $table->index('alter_ego_id');
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropForeign(['alter_ego_id']);
            $table->dropIndex(['alter_ego_id']);
            $table->dropColumn('alter_ego_id');
        });
    }
};
