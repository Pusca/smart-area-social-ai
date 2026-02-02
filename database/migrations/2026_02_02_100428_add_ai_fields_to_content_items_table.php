<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->string('ai_status')->default('draft'); // draft|generating|ready|error
            $table->text('ai_caption')->nullable();
            $table->json('ai_hashtags')->nullable();
            $table->text('ai_cta')->nullable();
            $table->text('ai_image_prompt')->nullable();
            $table->string('ai_image_path')->nullable(); // storage path (public)
            $table->text('ai_error')->nullable();
            $table->timestamp('ai_generated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropColumn([
                'ai_status',
                'ai_caption',
                'ai_hashtags',
                'ai_cta',
                'ai_image_prompt',
                'ai_image_path',
                'ai_error',
                'ai_generated_at',
            ]);
        });
    }
};
