<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ALL = ['read', 'comment', 'edit', 'approve', 'create'];

    /**
     * Run the migrations.
     *
     * What an agent may do is set on the agent party in Akord, no longer per credential
     * (OAuth connection or claim-code token). Each agent party gets every ability any of its
     * live credentials had, so no connected agent loses one; a party without credentials
     * gets read and comment, the default for new agent parties.
     */
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->json('agent_abilities')->nullable()->after('type');
        });

        DB::table('parties')->where('type', 'agent')->orderBy('id')->each(function (object $party): void {
            $granted = DB::table('agent_connections')->where('party_id', $party->id)->whereNull('revoked_at')->pluck('abilities')
                ->merge(DB::table('personal_access_tokens')->where('tokenable_type', 'party')->where('tokenable_id', $party->id)->pluck('abilities'))
                ->flatMap(fn (?string $json): array => (array) json_decode((string) $json, true));

            $abilities = match (true) {
                $granted->isEmpty() => ['read', 'comment'],
                $granted->contains('*') => self::ALL,
                default => array_values(array_intersect(self::ALL, ['read', ...$granted->all()])),
            };

            DB::table('parties')->where('id', $party->id)->update(['agent_abilities' => json_encode($abilities)]);
        });

        Schema::table('agent_connections', function (Blueprint $table) {
            $table->dropColumn('abilities');
        });

        Schema::table('agent_claim_codes', function (Blueprint $table) {
            $table->dropColumn('abilities');
        });
    }

    /**
     * Reverse the migrations: every credential gets its party's abilities back.
     */
    public function down(): void
    {
        Schema::table('agent_connections', function (Blueprint $table) {
            $table->json('abilities')->nullable();
        });

        Schema::table('agent_claim_codes', function (Blueprint $table) {
            $table->json('abilities')->nullable()->after('code_hash');
        });

        foreach (DB::table('parties')->whereNotNull('agent_abilities')->get(['id', 'agent_abilities']) as $party) {
            DB::table('agent_connections')->where('party_id', $party->id)->update(['abilities' => $party->agent_abilities]);
            DB::table('agent_claim_codes')->where('party_id', $party->id)->update(['abilities' => $party->agent_abilities]);
        }

        Schema::table('parties', function (Blueprint $table) {
            $table->dropColumn('agent_abilities');
        });
    }
};
