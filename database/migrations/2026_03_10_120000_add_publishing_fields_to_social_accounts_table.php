<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('platform', 50)->nullable()->after('provider');
            $table->string('status', 30)->default('active')->after('platform');
            $table->boolean('is_primary')->default(false)->after('status');
            $table->timestamp('connected_at')->nullable()->after('token_expires_at');
            $table->timestamp('last_synced_at')->nullable()->after('connected_at');
            $table->text('last_error')->nullable()->after('last_synced_at');

            $table->index(['tenant_id', 'provider', 'platform']);
            $table->index(['tenant_id', 'provider', 'platform', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'provider', 'platform']);
            $table->dropIndex(['tenant_id', 'provider', 'platform', 'status']);
            $table->dropColumn([
                'platform',
                'status',
                'is_primary',
                'connected_at',
                'last_synced_at',
                'last_error',
            ]);
        });
    }
};
