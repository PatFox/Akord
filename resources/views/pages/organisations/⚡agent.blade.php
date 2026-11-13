<?php

use App\Actions\Organisations\CreateAgentClaimCode;
use App\Actions\Organisations\RevokeAgentConnection;
use App\Actions\Organisations\RevokeAgentToken;
use App\Actions\Organisations\SetAgentAbilities;
use App\Livewire\Concerns\RunsActions;
use App\Models\AgentClaimCode;
use App\Models\AgentConnection;
use App\Models\Contract;
use App\Models\Organisation;
use App\Models\Party;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use App\Support\Agents\PartyMcpUrl;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    use RunsActions;

    public Organisation $organisation;

    public Party $party;

    /** @var list<string> */
    public array $abilities = ['comment', 'approve'];

    /** The code just generated; shown once and never stored in plain text. */
    public ?string $newCode = null;

    public function mount(Organisation $organisation, Party $party): void
    {
        abort_unless(Organisation::query()->visibleTo(Auth::user())->whereKey($organisation->id)->exists(), 404);
        abort_unless($party->organisation_id === $organisation->id && $party->isAgent(), 404);
        abort_unless(Auth::user()->isOwnerOf($organisation->id), 403);

        $this->organisation = $organisation;
        $this->party = $party;
    }

    /**
     * @return Collection<int, PersonalAccessToken>
     */
    #[Computed]
    public function tokens(): Collection
    {
        return $this->party->tokens()->latest('id')->get();
    }

    /**
     * MCP clients (omp, Claude Code, ...) connected through OAuth sign-in.
     *
     * @return Collection<int, AgentConnection>
     */
    #[Computed]
    public function connections(): Collection
    {
        return AgentConnection::query()->active()->where('party_id', $this->party->id)->with(['client', 'approver'])->latest('id')->get();
    }

    public function disconnect(int $connectionId): void
    {
        $connection = AgentConnection::query()->where('party_id', $this->party->id)->findOrFail($connectionId);
        $this->attempt(fn () => app(RevokeAgentConnection::class)->handle(Auth::user(), $this->party, $connection), __('Disconnected.'));
        unset($this->connections);
    }

    /**
     * @return Collection<int, AgentClaimCode>
     */
    #[Computed]
    public function codes(): Collection
    {
        return $this->party->claimCodes()->latest('id')->limit(10)->get();
    }

    /**
     * @return Collection<int, Contract>
     */
    #[Computed]
    public function contracts(): Collection
    {
        return Contract::query()->visibleTo($this->party)->orderBy('title')->get();
    }

    public function generate(): void
    {
        $result = $this->attempt(fn () => app(CreateAgentClaimCode::class)->handle(Auth::user(), $this->party, $this->abilities));

        if ($result !== null) {
            $this->newCode = $result[1];
            unset($this->codes);
        }
    }

    public function revoke(int $tokenId): void
    {
        $this->attempt(fn () => app(RevokeAgentToken::class)->handle(Auth::user(), $this->party, $tokenId), __('Token revoked.'));
        unset($this->tokens);
    }

    /**
     * Turns one ability on or off for an existing MCP connection or API token.
     */
    public function toggleAbility(string $kind, int $id, string $ability): void
    {
        $credential = $kind === 'connection'
            ? AgentConnection::query()->where('party_id', $this->party->id)->findOrFail($id)
            : $this->party->tokens()->whereKey($id)->firstOrFail();
        $current = array_values(array_map(strval(...), (array) $credential->abilities));
        $abilities = in_array($ability, $current, true) ? array_values(array_diff($current, [$ability])) : [...$current, $ability];

        $this->attempt(fn () => app(SetAgentAbilities::class)->handle(Auth::user(), $this->party, $credential, $abilities), __('Abilities saved.'));
        unset($this->connections, $this->tokens);
    }

    #[Computed]
    public function mcpUrl(): string
    {
        return PartyMcpUrl::for($this->party);
    }

    #[Computed]
    public function ompConfig(): string
    {
        return (string) json_encode(['mcpServers' => ['akord' => ['type' => 'http', 'url' => $this->mcpUrl]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function render()
    {
        return $this->view()->title(__('Agent access').' · '.$this->party->name);
    }
}; ?>

<section class="w-full max-w-4xl space-y-8">
    <div>
        <flux:breadcrumbs class="mb-2">
            <flux:breadcrumbs.item :href="route('dashboard')" wire:navigate>{{ __('Organisations') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item :href="route('organisations.show', $organisation)" wire:navigate>{{ $organisation->name }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item :href="route('organisations.parties', $organisation)" wire:navigate>{{ __('Parties') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $party->name }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>
        <flux:heading size="xl" level="1">{{ __('Agent access: :name', ['name' => $party->name]) }}</flux:heading>
        <flux:subheading>{{ __('Connect an AI agent with a one-time claim code. The agent exchanges it for an API token.') }}</flux:subheading>
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Connect an MCP client (recommended)') }}</flux:heading>
        <flux:text size="sm">{{ __('This party has its own MCP URL. Put it in the MCP settings of the repo whose agent represents this party; the tool signs in with your Akord account in the browser, and the approval screen is fixed to this party. A sign-in made for this URL cannot act as any other party.') }}</flux:text>
        <div class="space-y-2 text-sm">
            <div>{{ __('MCP URL') }}: <code class="select-all font-mono text-xs" data-test="party-mcp-url">{{ $this->mcpUrl }}</code></div>
            <div>
                <strong>omp</strong>: {{ __('commit this as') }} <code class="font-mono text-xs">.omp/mcp.json</code> {{ __('in the repo (no secrets in it), then in omp') }} <code class="select-all font-mono text-xs">/mcp reauth akord</code>.
                {{ __('For the review skill, also install the plugin:') }} <code class="select-all font-mono text-xs">omp plugin marketplace add PatFox/Akord</code>, <code class="select-all font-mono text-xs">omp plugin install akord@akord</code>.
                <pre class="mt-1 select-all overflow-x-auto rounded bg-zinc-100 px-2 py-1 font-mono text-xs dark:bg-zinc-900" data-test="omp-mcp-json">{{ $this->ompConfig }}</pre>
            </div>
            <div>{{ __('Other MCP clients: add the URL above as an HTTP MCP server and sign in when asked.') }}</div>
        </div>

        @if ($this->connections->isNotEmpty())
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Tool') }}</flux:table.column>
                    <flux:table.column>{{ __('Abilities') }}</flux:table.column>
                    <flux:table.column>{{ __('Approved by') }}</flux:table.column>
                    <flux:table.column>{{ __('Last used') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->connections as $connection)
                        <flux:table.row :key="'connection-'.$connection->id">
                            <flux:table.cell class="font-medium">{{ $connection->client?->name ?? __('Unknown tool') }}</flux:table.cell>
                            <flux:table.cell>@include('partials.agent-abilities', ['kind' => 'connection', 'id' => $connection->id, 'abilities' => $connection->abilities])</flux:table.cell>
                            <flux:table.cell>{{ $connection->approver?->name }}</flux:table.cell>
                            <flux:table.cell>{{ $connection->last_used_at?->diffForHumans() ?? __('Never') }}</flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="disconnect({{ $connection->id }})" wire:confirm="{{ __('Disconnect this tool? It loses access immediately.') }}">{{ __('Disconnect') }}</flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Or: a one-time claim code') }}</flux:heading>
        <flux:text size="sm">{{ __('For agents that call the REST API or cannot sign in through a browser.') }}</flux:text>
        <form wire:submit="generate" class="space-y-3">
            <flux:text size="sm">{{ __('Abilities for the next code you generate (not saved until you generate it). To change a connected tool or an existing token, use the checkboxes in its row.') }}</flux:text>
            <div class="flex flex-wrap gap-4">
                <flux:checkbox checked disabled :label="__('Read')" />
                @foreach (\App\Support\Agents\AgentAbilities::optionalLabels() as $ability => $label)
                    <flux:checkbox wire:model="abilities" :value="$ability" :label="$label" wire:key="new-code-{{ $ability }}" />
                @endforeach
            </div>
            <flux:text size="sm">{{ __('The token can only do what the agent party’s role on each contract also allows.') }}</flux:text>
            <flux:button type="submit" variant="primary" icon="key">{{ __('Generate claim code') }}</flux:button>
        </form>

        @if ($newCode)
            <flux:callout icon="key" color="amber" :heading="__('Copy this code now; it will not be shown again')" data-test="new-claim-code">
                <flux:callout.text>
                    <code class="block select-all rounded bg-white px-2 py-1 font-mono text-base text-zinc-900 dark:bg-zinc-900 dark:text-zinc-100">{{ $newCode }}</code>
                    <span class="mt-3 block">{{ __('It works once and expires in :hours hours. The agent claims a token with:', ['hours' => config('akord.agents.claim_code_hours')]) }}</span>
                    <code class="mt-1 block select-all whitespace-pre-wrap rounded bg-white px-2 py-1 font-mono text-xs text-zinc-900 dark:bg-zinc-900 dark:text-zinc-100">curl -X POST {{ url('/api/v1/agent/claim') }} -H "Accept: application/json" -H "Content-Type: application/json" -d '{"code":"{{ $newCode }}","name":"my-agent"}'</code>
                    <span class="mt-3 block">{{ __('Then use the token as a Bearer token for the REST API at :api or the MCP server at :mcp.', ['api' => url('/api/v1'), 'mcp' => url('/mcp')]) }}</span>
                </flux:callout.text>
            </flux:callout>
        @endif

        @if ($this->codes->isNotEmpty())
            <div class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 text-sm dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($this->codes as $code)
                    <div class="flex flex-wrap items-center justify-between gap-2 px-3 py-2" wire:key="code-{{ $code->id }}">
                        <span>{{ __('Created :date', ['date' => $code->created_at?->diffForHumans()]) }} · {{ implode(', ', $code->abilities ?? ['read']) }}</span>
                        <flux:badge size="sm" :color="$code->claimed_at ? 'green' : ($code->expires_at->isPast() ? 'zinc' : 'sky')">
                            {{ $code->claimed_at ? __('Claimed') : ($code->expires_at->isPast() ? __('Expired') : __('Waiting to be claimed')) }}
                        </flux:badge>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Tokens') }}</flux:heading>
        @if ($this->tokens->isEmpty())
            <flux:text size="sm">{{ __('No tokens yet. Generate a claim code and give it to the agent.') }}</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Abilities') }}</flux:table.column>
                    <flux:table.column>{{ __('Last used') }}</flux:table.column>
                    <flux:table.column>{{ __('Created') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->tokens as $token)
                        <flux:table.row :key="'token-'.$token->id">
                            <flux:table.cell class="font-medium">{{ $token->name }}</flux:table.cell>
                            <flux:table.cell>@include('partials.agent-abilities', ['kind' => 'token', 'id' => $token->id, 'abilities' => $token->abilities])</flux:table.cell>
                            <flux:table.cell>{{ $token->last_used_at?->diffForHumans() ?? __('Never') }}</flux:table.cell>
                            <flux:table.cell>{{ $token->created_at?->toFormattedDayDateString() }}</flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="revoke({{ $token->id }})" wire:confirm="{{ __('Revoke this token? The agent loses access immediately.') }}">{{ __('Revoke') }}</flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Contracts') }}</flux:heading>
        @forelse ($this->contracts as $contract)
            <div><flux:link :href="route('contracts.show', $contract)" wire:navigate>{{ $contract->title }} v{{ $contract->version }}</flux:link></div>
        @empty
            <flux:text size="sm">{{ __('Not attached to any contract yet. Attach it from a contract’s settings.') }}</flux:text>
        @endforelse
    </div>
</section>
