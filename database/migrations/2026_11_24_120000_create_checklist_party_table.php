<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Parties assigned to a checklist (D18): they tick its items whatever their role on the contract.
     */
    public function up(): void
    {
        Schema::create('checklist_party', function (Blueprint $table) {
            $table->id();
            $table->foreignRandomId('checklist_id')->constrained()->cascadeOnDelete();
            $table->foreignRandomId('party_id')->constrained();
            $table->timestamps();

            $table->unique(['checklist_id', 'party_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checklist_party');
    }
};
