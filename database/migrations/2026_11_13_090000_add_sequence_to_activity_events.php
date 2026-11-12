<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `seq` is the cursor for activity?since=. Auto-increment ids can commit out of
     * order under concurrent writers, so events are numbered after commit, in commit
     * order, by App\Support\ActivitySequencer. The single activity_sequencer row is
     * both its lock and its counter.
     */
    public function up(): void
    {
        Schema::table('activity_events', function (Blueprint $table) {
            $table->unsignedBigInteger('seq')->nullable()->unique();
        });

        Schema::create('activity_sequencer', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('last_seq');
        });

        DB::table('activity_sequencer')->insert(['id' => 1, 'last_seq' => 0]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_sequencer');

        Schema::table('activity_events', function (Blueprint $table) {
            $table->dropUnique(['seq']);
            $table->dropColumn('seq');
        });
    }
};
