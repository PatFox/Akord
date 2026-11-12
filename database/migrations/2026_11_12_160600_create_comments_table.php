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
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('step_id')->constrained();
            $table->unsignedBigInteger('step_revision_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->foreignId('author_party_id')->constrained('parties');
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 20);
            $table->text('body_md');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'step_id']);

            // The revision discussed, and any parent comment, belong to the same step.
            $table->foreign(['step_revision_id', 'step_id'])->references(['id', 'step_id'])->on('step_revisions');
            $table->foreign(['parent_id', 'step_id'])->references(['id', 'step_id'])->on('comments');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
