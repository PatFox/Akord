<?php

namespace App\Providers;

use App\Http\Controllers\Agents\AuthorizationController;
use App\Models\ActivityEvent;
use App\Models\AgentClaimCode;
use App\Models\AgentConnection;
use App\Models\Approval;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\ChecklistStage;
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
use App\Support\Agents\AgentActor;
use App\Support\RandomId;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\ForeignIdColumnDefinition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Http\Controllers\AuthorizationController as PassportAuthorizationController;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Passport's authorize endpoint, with consent forced for a party not yet connected (see the class).
        $this->app->bind(PassportAuthorizationController::class, AuthorizationController::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureSchema();
        $this->configureRateLimits();
        $this->configureOAuth();
    }

    /**
     * Column helpers for RandomId keys, used by the migrations:
     * randomId() for a primary key, foreignRandomId() for a foreign key, and
     * randomIdMorphs() for a polymorphic relation to one of Akord's models.
     */
    protected function configureSchema(): void
    {
        Blueprint::macro('randomId', function (string $column = 'id'): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->char($column, RandomId::LENGTH)->primary();
        });

        Blueprint::macro('foreignRandomId', function (string $column): ForeignIdColumnDefinition {
            /** @var Blueprint $this */
            $definition = new ForeignIdColumnDefinition($this, [
                'type' => 'char',
                'name' => $column,
                'length' => RandomId::LENGTH,
            ]);
            $this->addColumnDefinition($definition);

            return $definition;
        });

        Blueprint::macro('randomIdMorphs', function (string $name, bool $nullable = false): void {
            /** @var Blueprint $this */
            $this->string("{$name}_type")->nullable($nullable);
            $this->char("{$name}_id", RandomId::LENGTH)->nullable($nullable);
            $this->index(["{$name}_type", "{$name}_id"]);
        });
    }

    /**
     * Passport issues OAuth tokens to MCP clients (omp, Claude Code, ...). The consent
     * screen asks the owner which agent party the client acts as.
     */
    protected function configureOAuth(): void
    {
        Passport::authorizationView('oauth.authorize');
        Passport::tokensExpireIn(now()->addDay());
        Passport::refreshTokensExpireIn(now()->addDays(60));
    }

    /**
     * Agents are limited per party (not per IP), across the REST API and MCP server.
     * The public claim endpoint is limited per IP.
     */
    protected function configureRateLimits(): void
    {
        RateLimiter::for('agent', fn (Request $request) => Limit::perMinute((int) config('akord.agents.requests_per_minute'))
            ->by('agent:'.(AgentActor::connectionKey($request->user()) ?? 'ip:'.$request->ip())));

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
            'agent_connection' => AgentConnection::class,
            'checklist' => Checklist::class,
            'checklist_stage' => ChecklistStage::class,
            'checklist_item' => ChecklistItem::class,
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
