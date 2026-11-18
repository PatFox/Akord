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
        Schema::create('activity_events', function (Blueprint $table) {
            $table->randomId();
            $table->foreignRandomId('organisation_id')->constrained();
            $table->foreignRandomId('contract_id')->nullable()->constrained();
            // Order of the contract's events: 1, 2, 3... assigned by App\Support\ActivityLog while
            // the contract row is locked. Random ids carry no order; ActivitySequencer uses this.
            $table->unsignedInteger('position');
            $table->foreignRandomId('actor_party_id')->nullable()->constrained('parties');
            $table->foreignRandomId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 64);
            $table->randomIdMorphs('subject', nullable: true);
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['contract_id', 'position']);
            $table->index('organisation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_events');
    }
};
