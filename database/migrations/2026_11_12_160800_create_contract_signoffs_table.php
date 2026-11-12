<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contract_signoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained();
            $table->foreignId('party_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('fingerprint', 64);
            $table->timestamp('withdrawn_at')->nullable();
            // true while active, NULL once withdrawn; see the approvals migration.
            $table->boolean('active')->nullable()->storedAs('case when withdrawn_at is null then true end');
            $table->timestamps();

            $table->unique(['contract_id', 'party_id', 'fingerprint', 'active'], 'contract_signoffs_active_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_signoffs');
    }
};
