<?php

namespace Database\Seeders;

use App\Actions\Approvals\ApproveStep;
use App\Actions\Comments\AddComment;
use App\Actions\Contracts\AttachParty;
use App\Actions\Contracts\ContractReadiness;
use App\Actions\Contracts\SetRequiredApprover;
use App\Actions\Contracts\SignOffContract;
use App\Actions\Organisations\CreateContract;
use App\Actions\Organisations\CreateOrganisation;
use App\Actions\Organisations\CreateParty;
use App\Actions\Organisations\CreateProject;
use App\Actions\Phases\CreatePhase;
use App\Actions\Steps\CreateStep;
use App\Actions\Steps\ReviseStep;
use App\Enums\CommentKind;
use App\Enums\ContractRole;
use App\Enums\PartyType;
use App\Models\Contract;
use App\Models\Organisation;
use App\Models\Party;
use App\Models\Step;
use App\Models\User;
use App\Support\Actor;
use Illuminate\Database\Seeder;

/**
 * Demo data built through the real actions: `php artisan db:seed --class=DemoSeeder`.
 * Logins (password "password"): test@example.com (owner), alice@example.com (API team),
 * bob@example.com (App team). Party membership is set directly because invitations
 * arrive in Phase 6.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Organisation::query()->where('slug', 'acme-platform')->exists()) {
            $this->command->warn('Demo organisation already exists; skipping.');

            return;
        }

        $owner = $this->user('Test User', 'test@example.com');
        $alice = $this->user('Alice Archer', 'alice@example.com');
        $bob = $this->user('Bob Builder', 'bob@example.com');

        $organisation = app(CreateOrganisation::class)->handle($owner, 'Acme Platform');
        $ownerParty = Party::query()->where('organisation_id', $organisation->id)->firstOrFail();
        $apiTeam = app(CreateParty::class)->handle($owner, $organisation, 'API team', PartyType::Team);
        $appTeam = app(CreateParty::class)->handle($owner, $organisation, 'App team', PartyType::Team);
        $bot = app(CreateParty::class)->handle($owner, $organisation, 'Review bot', PartyType::Agent);
        $apiTeam->users()->attach($alice);
        $appTeam->users()->attach($bob);

        $project = app(CreateProject::class)->handle($owner, $organisation, 'Mobile banking');
        $ownerActor = Actor::user($owner);
        $aliceActor = Actor::user($alice, $apiTeam);
        $bobActor = Actor::user($bob, $appTeam);

        // A contract in progress.
        $payments = app(CreateContract::class)->handle($owner, $project, 'Payments API');
        $this->attach($ownerActor, $payments, [
            [$ownerParty, ContractRole::Editor, false],
            [$apiTeam, ContractRole::Editor, true],
            [$appTeam, ContractRole::Commenter, true],
            [$bot, ContractRole::Commenter, false],
        ]);

        $design = app(CreatePhase::class)->handle($aliceActor, $payments, 'Design');
        $build = app(CreatePhase::class)->handle($aliceActor, $payments, 'Build');
        $endpoints = app(CreateStep::class)->handle($aliceActor, $design, 'Define payment endpoints',
            "Expose these endpoints:\n\n- `POST /payments` creates a payment\n- `GET /payments/{id}` returns its status\n\nAll amounts are **integers in minor units**.");
        $errors = app(CreateStep::class)->handle($aliceActor, $design, 'Agree error format',
            'Errors use [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) problem details.');
        $idempotency = app(CreateStep::class)->handle($aliceActor, $build, 'Idempotency keys',
            'Clients send an `Idempotency-Key` header; repeats within 24 hours return the first response.');
        app(CreateStep::class)->handle($aliceActor, $build, 'Webhooks for status changes',
            'Send `payment.succeeded` and `payment.failed` events, signed with HMAC-SHA256.');

        foreach ([$endpoints, $errors, $idempotency] as $step) {
            $this->approve($aliceActor, $step);
        }
        $this->approve($bobActor, $errors);

        app(AddComment::class)->handle($bobActor, $endpoints, CommentKind::Question, 'Do we need pagination on a list endpoint for the app?');
        $question = $endpoints->comments()->firstOrFail();
        app(AddComment::class)->handle($aliceActor, $endpoints, CommentKind::General, 'Good point — adding `GET /payments` with cursor pagination.', $question);
        app(ReviseStep::class)->handle($aliceActor, $endpoints, 1, 'Define payment endpoints',
            "Expose these endpoints:\n\n- `POST /payments` creates a payment\n- `GET /payments/{id}` returns its status\n- `GET /payments` lists payments, newest first, with cursor pagination\n\nAll amounts are **integers in minor units**.",
            'Added the list endpoint (from the App team question).');
        $this->approve($aliceActor, $endpoints);

        // An agreed contract.
        $auth = app(CreateContract::class)->handle($owner, $project, 'Auth integration');
        $this->attach($ownerActor, $auth, [
            [$apiTeam, ContractRole::Editor, true],
            [$appTeam, ContractRole::Commenter, true],
        ]);
        $phase = app(CreatePhase::class)->handle($aliceActor, $auth, 'Scope');
        $step = app(CreateStep::class)->handle($aliceActor, $phase, 'Use OAuth 2.1 with PKCE', 'The app uses the authorization code flow with PKCE; tokens live 15 minutes.');
        $this->approve($aliceActor, $step);
        $this->approve($bobActor, $step);
        $fingerprint = ContractReadiness::report($auth)->fingerprint;
        app(SignOffContract::class)->handle($aliceActor, $auth, $fingerprint);
        app(SignOffContract::class)->handle($bobActor, $auth, $fingerprint);

        $this->command->info('Demo data ready. Log in as test@example.com, alice@example.com or bob@example.com (password: password).');
    }

    private function user(string $name, string $email): User
    {
        return User::query()->where('email', $email)->first()
            ?? User::factory()->create(['name' => $name, 'email' => $email]);
    }

    /**
     * @param  list<array{0: Party, 1: ContractRole, 2: bool}>  $parties
     */
    private function attach(Actor $owner, Contract $contract, array $parties): void
    {
        foreach ($parties as [$party, $role, $required]) {
            app(AttachParty::class)->handle($owner, $contract, $party, $role);
            if ($required) {
                app(SetRequiredApprover::class)->handle($owner, $contract, $party, true);
            }
        }
    }

    private function approve(Actor $actor, Step $step): void
    {
        app(ApproveStep::class)->handle($actor, $step, $step->fresh()?->currentRevision->number ?? 1);
    }
}
