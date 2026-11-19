<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A contract version's working checklist, written by an agent from the plan: stages of items that are ticked off
     * as the work is done. Not part of the fingerprint. Replacing a checklist deletes the old one, stages and items.
     */
    public function up(): void
    {
        Schema::create('checklists', function (Blueprint $table) {
            $table->randomId();
            $table->foreignRandomId('contract_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignRandomId('created_by_party_id')->constrained('parties');
            $table->foreignRandomId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('checklist_stages', function (Blueprint $table) {
            $table->randomId();
            $table->foreignRandomId('checklist_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->index(['checklist_id', 'position']);
        });

        Schema::create('checklist_items', function (Blueprint $table) {
            $table->randomId();
            $table->foreignRandomId('checklist_stage_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('title', 300);
            $table->text('notes')->nullable();
            // The contract step this item came from, if any.
            $table->foreignRandomId('step_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->foreignRandomId('checked_by_party_id')->nullable()->constrained('parties');
            $table->foreignRandomId('checked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['checklist_stage_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checklist_items');
        Schema::dropIfExists('checklist_stages');
        Schema::dropIfExists('checklists');
    }
};
