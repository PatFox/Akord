<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * D16: archiving a step needs every required party's approval. A revision can propose archiving the step; the
     * step leaves the contract once every required party approves that revision. Existing revisions propose nothing.
     */
    public function up(): void
    {
        Schema::table('step_revisions', function (Blueprint $table) {
            $table->boolean('proposes_archive')->default(false)->after('description_md');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('step_revisions', function (Blueprint $table) {
            $table->dropColumn('proposes_archive');
        });
    }
};
