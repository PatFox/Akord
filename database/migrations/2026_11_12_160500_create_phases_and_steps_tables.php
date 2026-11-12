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
        Schema::create('phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained();
            $table->string('title');
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->index(['contract_id', 'position']);
        });

        Schema::create('steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('phase_id')->constrained();
            $table->unsignedInteger('position');
            $table->unsignedBigInteger('current_revision_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('origin_step_id')->nullable()->constrained('steps');
            $table->timestamps();

            $table->index(['phase_id', 'position']);
        });

        Schema::create('step_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('step_id')->constrained();
            $table->unsignedInteger('number');
            $table->string('title');
            $table->longText('description_md');
            $table->string('content_hash', 64);
            $table->foreignId('author_party_id')->constrained('parties');
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('change_note')->nullable();
            $table->timestamps();

            $table->unique(['step_id', 'number']);
            // Target for composite foreign keys that pin a revision to its step.
            $table->unique(['id', 'step_id']);
        });

        // A step's current revision must be one of its own revisions.
        Schema::table('steps', function (Blueprint $table) {
            $table->foreign(['current_revision_id', 'id'])
                ->references(['id', 'step_id'])
                ->on('step_revisions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::withoutForeignKeyConstraints(function () {
            Schema::dropIfExists('step_revisions');
            Schema::dropIfExists('steps');
            Schema::dropIfExists('phases');
        });
    }
};
