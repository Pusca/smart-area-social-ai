<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_plans', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();

            $table->string('name'); // es. "Piano Febbraio"
            $table->date('start_date');
            $table->date('end_date');

            $table->string('status')->default('active'); // active, archived
            $table->json('settings')->nullable(); // tone, goals, frequency ecc.

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_plans');
    }
};
