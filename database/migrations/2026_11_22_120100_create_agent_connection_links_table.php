<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A one-time link an owner gives an agent: the agent fetches its party's MCP
     * configuration with it and saves it in the repo. Only the link's hash is stored.
     */
    public function up(): void
    {
        Schema::create('agent_connection_links', function (Blueprint $table) {
            $table->randomId();
            $table->foreignRandomId('party_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->foreignRandomId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_connection_links');
    }
};
