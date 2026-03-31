<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canva_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->unique();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('canva_user_id')->nullable();
            $table->string('canva_team_id')->nullable();
            $table->string('canva_display_name')->nullable();
            $table->longText('access_token_encrypted')->nullable();
            $table->longText('refresh_token_encrypted')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->json('capabilities')->nullable();
            $table->string('status', 40)->default('disconnected');
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canva_connections');
    }
};
