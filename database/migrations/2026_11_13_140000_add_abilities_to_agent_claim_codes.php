<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The abilities the token issued for this code will have, and who created the code.
     */
    public function up(): void
    {
        Schema::table('agent_claim_codes', function (Blueprint $table) {
            $table->json('abilities')->nullable()->after('code_hash');
            $table->foreignId('created_by')->nullable()->after('abilities')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_claim_codes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn('abilities');
        });
    }
};
