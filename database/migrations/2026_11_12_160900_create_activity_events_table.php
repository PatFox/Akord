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
            // The auto-incrementing id is the cursor for activity?since=.
            $table->id();
            $table->foreignId('organisation_id')->constrained();
            $table->foreignId('contract_id')->nullable()->constrained();
            $table->foreignId('actor_party_id')->nullable()->constrained('parties');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 64);
            $table->nullableMorphs('subject');
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['contract_id', 'id']);
            $table->index(['organisation_id', 'id']);
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
