<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_assets', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('content_plan_id')->nullable()->index();

            $table->string('kind', 30)->default('image'); // logo | image
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->string('mime', 80)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_assets');
    }
};
