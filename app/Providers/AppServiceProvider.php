<?php

namespace App\Providers;

use App\Models\ActivityEvent;
use App\Models\AgentClaimCode;
use App\Models\Approval;
use App\Models\Comment;
use App\Models\Contract;
use App\Models\ContractSignoff;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\Party;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Step;
use App\Models\StepRevision;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimits();
    }

    /**
     * Agents are limited per party (not per IP), across the REST API and MCP server.
     * The public claim endpoint is limited per IP.
     */
    protected function configureRateLimits(): void
    {
        RateLimiter::for('agent', fn (Request $request) => Limit::perMinute((int) config('akord.agents.requests_per_minute'))
            ->by('agent-party:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('agent-claim', fn (Request $request) => Limit::perMinute(10)->by('agent-claim:'.$request->ip()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Stable names instead of class names in polymorphic columns
        // (activity subjects, Sanctum's tokenable).
        Relation::enforceMorphMap([
            'user' => User::class,
            'organisation' => Organisation::class,
            'project' => Project::class,
            'party' => Party::class,
            'contract' => Contract::class,
            'phase' => Phase::class,
            'step' => Step::class,
            'step_revision' => StepRevision::class,
            'comment' => Comment::class,
            'approval' => Approval::class,
            'contract_signoff' => ContractSignoff::class,
            'invitation' => Invitation::class,
            'agent_claim_code' => AgentClaimCode::class,
            'activity_event' => ActivityEvent::class,
        ]);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
