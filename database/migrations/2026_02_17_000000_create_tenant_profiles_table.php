<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->unique();

            $table->string('business_name', 120);
            $table->string('industry', 120)->nullable();
            $table->string('website', 255)->nullable();
            $table->text('notes')->nullable();

            $table->text('services')->nullable();
            $table->text('target')->nullable();
            $table->string('cta', 255)->nullable();

            $table->string('default_goal', 120)->nullable();
            $table->string('default_tone', 80)->nullable();
            $table->unsignedSmallInteger('default_posts_per_week')->nullable();
            $table->json('default_platforms')->nullable();
            $table->json('default_formats')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_profiles');
    }
};
