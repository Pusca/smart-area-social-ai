<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_feedback_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_item_id')->constrained('content_items')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sentiment', 20);
            $table->string('category', 80)->nullable();
            $table->string('scope', 40)->nullable();
            $table->text('reason')->nullable();
            $table->string('action', 30)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'content_item_id', 'created_at'], 'content_feedback_tenant_item_created_idx');
            $table->index(['tenant_id', 'sentiment', 'created_at'], 'content_feedback_tenant_sentiment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_feedback_entries');
    }
};
