<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * An MCP client (an OAuth client, e.g. one omp installation) that an owner approved
     * to act as an agent party, with the abilities they chose. OAuth tokens belong to
     * the approving owner; this row turns them into the agent party.
     */
    public function up(): void
    {
        Schema::create('agent_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->uuid('oauth_client_id')->index();
            $table->foreignId('approved_by')->constrained('users')->cascadeOnDelete();
            $table->json('abilities');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['oauth_client_id', 'approved_by']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_connections');
    }
};
