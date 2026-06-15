<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_publications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('content_item_id')->index();
            $table->unsignedBigInteger('social_account_id')->nullable()->index();
            $table->string('provider', 50);
            $table->string('platform', 50);
            $table->string('status', 30)->default('scheduled');
            $table->string('media_type', 30)->default('image');
            $table->text('caption')->nullable();
            $table->string('media_url', 2048)->nullable();
            $table->timestamp('scheduled_for')->nullable()->index();
            $table->timestamp('published_at')->nullable();
            $table->string('remote_id', 255)->nullable();
            $table->string('remote_url', 2048)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('payload')->nullable();
            $table->json('response_meta')->nullable();
            $table->timestamps();

            $table->unique(['content_item_id', 'platform']);
            $table->index(['status', 'scheduled_for']);
            $table->index(['tenant_id', 'platform', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_publications');
    }
};
