<?php

use App\Support\RandomId;
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
            $table->randomId();
            $table->foreignRandomId('step_id')->constrained();
            $table->char('step_revision_id', RandomId::LENGTH);
            $table->char('parent_id', RandomId::LENGTH)->nullable();
            $table->foreignRandomId('author_party_id')->constrained('parties');
            $table->foreignRandomId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
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
