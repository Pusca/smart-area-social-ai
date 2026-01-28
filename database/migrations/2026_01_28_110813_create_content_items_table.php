<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_items', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('content_plan_id')->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();

            $table->string('platform'); // instagram, facebook, tiktok
            $table->string('format')->default('post'); // post, reel, story
            $table->timestamp('scheduled_at')->nullable()->index();

            $table->string('status')->default('draft'); 
            // draft, review, approved, scheduled, published, failed

            $table->string('title')->nullable();
            $table->text('caption')->nullable();
            $table->json('hashtags')->nullable();
            $table->json('assets')->nullable(); // immagini/video path, ecc.
            $table->json('ai_meta')->nullable(); // prompt, model, ecc.
            $table->text('error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_items');
    }
};
