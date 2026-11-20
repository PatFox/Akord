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
     * A contract version can have several checklists (one per team, say), each with a name unique within the version.
     * Existing checklists are named after the party that created them.
     */
    public function up(): void
    {
        Schema::table('checklists', function (Blueprint $table) {
            $table->string('name', 60)->nullable()->after('contract_id');
        });

        DB::table('checklists')->orderBy('id')->each(function (object $checklist): void {
            /** @var object{id: string, created_by_party_id: string} $checklist */
            $party = DB::table('parties')->where('id', $checklist->created_by_party_id)->value('name');
            DB::table('checklists')->where('id', $checklist->id)->update(['name' => mb_substr(is_string($party) ? $party : 'Checklist', 0, 60)]);
        });

        Schema::table('checklists', function (Blueprint $table) {
            $table->string('name', 60)->nullable(false)->change();
            // Added before the old index goes: MySQL needs an index on contract_id for its foreign key.
            $table->unique(['contract_id', 'name']);
        });

        Schema::table('checklists', function (Blueprint $table) {
            $table->dropUnique(['contract_id']);
        });
    }

    /**
     * Reverse the migrations. Fails while a version has more than one checklist.
     */
    public function down(): void
    {
        Schema::table('checklists', function (Blueprint $table) {
            $table->unique(['contract_id']);
        });

        Schema::table('checklists', function (Blueprint $table) {
            $table->dropUnique(['contract_id', 'name']);
            $table->dropColumn('name');
        });
    }
};
