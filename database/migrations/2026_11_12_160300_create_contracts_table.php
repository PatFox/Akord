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
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained();
            $table->string('title');
            $table->unsignedInteger('version')->default(1);
            // Unique: a version can be continued at most once.
            $table->foreignId('previous_version_id')->nullable()->unique()->constrained('contracts');
            $table->timestamp('agreed_at')->nullable();
            $table->string('agreed_fingerprint', 64)->nullable();
            $table->boolean('require_human_signoff')->default(false);
            $table->timestamps();
        });

        Schema::create('contract_party', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained();
            $table->string('role', 20);
            $table->boolean('is_required_approver')->default(false);
            $table->timestamps();

            $table->unique(['contract_id', 'party_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_party');
        Schema::dropIfExists('contracts');
    }
};
