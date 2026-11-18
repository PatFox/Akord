<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The web form, REST API and MCP tools accept change notes up to 500 characters,
     * but the column was varchar(255): MySQL refused longer notes and the request failed with 500.
     */
    public function up(): void
    {
        Schema::table('step_revisions', function (Blueprint $table) {
            $table->string('change_note', 500)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations. Fails on MySQL while a note is longer than 255 characters.
     */
    public function down(): void
    {
        Schema::table('step_revisions', function (Blueprint $table) {
            $table->string('change_note')->nullable()->change();
        });
    }
};
