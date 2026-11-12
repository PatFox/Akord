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
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('step_id')->constrained();
            $table->unsignedBigInteger('step_revision_id');
            $table->foreignId('party_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            // true while active, NULL once withdrawn. NULLs never collide in a unique index on
            // MySQL, PostgreSQL or SQLite, so this enforces one active approval per
            // (step, revision, party) without a partial index.
            $table->boolean('active')->nullable()->storedAs('case when withdrawn_at is null then true end');
            $table->timestamps();

            $table->foreign(['step_revision_id', 'step_id'])->references(['id', 'step_id'])->on('step_revisions');
            $table->unique(['step_id', 'step_revision_id', 'party_id', 'active'], 'approvals_active_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};
