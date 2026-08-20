<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Il form/validazione del profilo consente 500 caratteri ma la colonna
     * era string(120): su MySQL i default_goal lunghi andrebbero in errore.
     */
    public function up(): void
    {
        Schema::table('tenant_profiles', function (Blueprint $table) {
            $table->string('default_goal', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_profiles', function (Blueprint $table) {
            $table->string('default_goal', 120)->nullable()->change();
        });
    }
};
