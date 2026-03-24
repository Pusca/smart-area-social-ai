<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trend_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('status', 40)->default('ready')->index();
            $table->string('source_type', 60)->default('config_seed')->index();
            $table->string('version', 60)->default('trend_snapshot_v1');
            $table->json('summary')->nullable();
            $table->json('opportunity_summary')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('observed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trend_snapshots');
    }
};