<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * D14: each version has a status (open, complete, cancelled), set by people, separate from the
     * derived state (draft, ready for sign-off, agreed). Existing versions become open.
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('status', 20)->default('open')->after('require_human_signoff');
            $table->index(['project_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'status']);
            $table->dropColumn('status');
        });
    }
};
