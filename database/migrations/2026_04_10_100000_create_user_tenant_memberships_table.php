<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabella `user_tenant_memberships` per il feature multi-brand.
 *
 * Permette a un singolo utente di essere membro di più tenant (brand),
 * abilitando agenzie e freelance a gestire più clienti dallo stesso account.
 *
 * Il tenant "primario" rimane registrato in `users.tenant_id` (legacy).
 * Il tenant "attivo" nella sessione corrente è gestito dal middleware
 * `ResolveActiveTenant` tramite `session('active_tenant_id')`.
 *
 * Relazione: un utente può essere membro di molti tenant con un ruolo specifico.
 * Ruoli disponibili: 'owner', 'editor', 'viewer'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_tenant_memberships', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('tenant_id');
            $table->string('role', 30)->default('editor');  // owner | editor | viewer
            $table->timestamps();

            // Chiave primaria composta — un utente ha al più un ruolo per tenant
            $table->primary(['user_id', 'tenant_id']);

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');

            // Indice per query "tutti i tenant di un utente"
            $table->index('user_id');
            // Indice per query "tutti i membri di un tenant" (admin panel)
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tenant_memberships');
    }
};
