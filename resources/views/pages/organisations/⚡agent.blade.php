<?php

use App\Actions\Organisations\CreateAgentClaimCode;
use App\Actions\Organisations\CreateAgentConnectionLink;
use App\Actions\Organisations\RevokeAgentConnection;
use App\Actions\Organisations\RevokeAgentToken;
use App\Actions\Organisations\SetAgentAbilities;
use App\Livewire\Concerns\RunsActions;
use App\Models\AgentClaimCode;
use App\Models\AgentConnection;
use App\Models\AgentConnectionLink;
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

    /** The connection link just created; shown once and never stored in plain text. */
    public ?string $newLink = null;

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
     * Turns one of the party's abilities on or off; every connected tool and token gets it on its next request.
     */
    public function toggleAbility(string $ability): void
    {
        $current = $this->party->agentAbilities();
        $abilities = in_array($ability, $current, true) ? array_values(array_diff($current, [$ability])) : [...$current, $ability];

        $this->attempt(fn () => app(SetAgentAbilities::class)->handle(Auth::user(), $this->party, $abilities), __('Abilities saved.'));
        $this->party->refresh();
    }

    public function createLink(): void
    {
        $result = $this->attempt(fn () => app(CreateAgentConnectionLink::class)->handle(Auth::user(), $this->party));

        if ($result !== null) {
            $this->newLink = $result[1];
            unset($this->links);
        }
    }

    /**
     * @return Collection<int, AgentConnectionLink>
     */
    #[Computed]
    public function links(): Collection
    {
        return $this->party->connectionLinks()->latest()->latest('id')->limit(10)->get();
    }

    /**
     * MCP clients (omp, Claude Code, ...) connected through OAuth sign-in.
     *
     * @return Collection<int, AgentConnection>
     */
    #[Computed]
    public function connections(): Collection
    {
        return AgentConnection::query()->active()->where('party_id', $this->party->id)->with(['client', 'approver'])->latest()->latest('id')->get();
    }

    public function disconnect(string $connectionId): void
    {
        $connection = AgentConnection::query()->where('party_id', $this->party->id)->findOrFail($connectionId);
        $this->attempt(fn () => app(RevokeAgentConnection::class)->handle(Auth::user(), $this->party, $connection), __('Disconnected.'));
        unset($this->connections);
    }

    public function generate(): void
    {
        $result = $this->attempt(fn () => app(CreateAgentClaimCode::class)->handle(Auth::user(), $this->party));

        if ($result !== null) {
            $this->newCode = $result[1];
            unset($this->codes);
        }
    }

    /**
     * @return Collection<int, AgentClaimCode>
     */
    #[Computed]
    public function codes(): Collection
    {
        return $this->party->claimCodes()->latest()->latest('id')->limit(10)->get();
    }

    /**
     * @return Collection<int, PersonalAccessToken>
     */
    #[Computed]
    public function tokens(): Collection
    {
        return $this->party->tokens()->latest('id')->get();
    }

    public function revoke(int $tokenId): void
    {
        $this->attempt(fn () => app(RevokeAgentToken::class)->handle(Auth::user(), $this->party, $tokenId), __('Token revoked.'));
        unset($this->tokens);
    }

    /**
     * The contracts the party is attached to, with its role on each.
     *
     * @return Collection<int, Contract>
     */
    #[Computed]
    public function contracts(): Collection
    {
        return $this->party->contracts()->orderBy('title')->orderBy('version')->get();
    }

    #[Computed]
    public function mcpUrl(): string
    {
        return PartyMcpUrl::for($this->party);
    }

    public function render()
    {
        return $this->view()->title(__('Agent access').' · '.$this->party->name);
    }
}; ?>

<section class="w-full max-w-4xl space-y-8">
    <x-slot:breadcrumbs>
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('dashboard')" wire:navigate>{{ __('Organisations') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item :href="route('organisations.show', $organisation)" wire:navigate>{{ $organisation->name }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item :href="route('organisations.parties', $organisation)" wire:navigate>{{ __('Parties') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $party->name }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>
    </x-slot:breadcrumbs>

    <div>
        <flux:heading size="xl" level="1">{{ __('Agent access: :name', ['name' => $party->name]) }}</flux:heading>
        <flux:subheading>{{ __('Set what this agent may do, and connect it from the repository whose agent represents this party.') }}</flux:subheading>
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Abilities') }}</flux:heading>
        <flux:text size="sm">{{ __('What this agent may do on every contract it is attached to. Its role on each contract (editor, commenter or viewer, set in the contract’s settings) still applies on top. Every connected tool and token gets these, from its next request.') }}</flux:text>
        <div class="flex flex-wrap gap-4" data-test="party-abilities">
            <flux:checkbox checked disabled :label="__('Read')" />
            @foreach (\App\Support\Agents\AgentAbilities::optionalLabels() as $ability => $label)
                <flux:checkbox
                    wire:key="ability-{{ $ability }}"
                    wire:click="toggleAbility('{{ $ability }}')"
                    :checked="in_array($ability, $party->agentAbilities(), true)"
                    :label="$label"
                />
            @endforeach
        </div>
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Connect an MCP client (recommended)') }}</flux:heading>
        <flux:text size="sm">{{ __('Create a connection link and give it to the agent in the repository that represents this party. The agent fetches the MCP configuration with it and saves it, git-ignored. Then, in omp, run /mcp reload and /mcp reauth akord, and approve in the browser. A sign-in made for this party cannot act as any other party.') }}</flux:text>
        <flux:button wire:click="createLink" variant="primary" icon="link" data-test="create-link">{{ __('Create connection link') }}</flux:button>

        @if ($newLink)
            <flux:callout icon="link" color="amber" :heading="__('Give this to the agent now; it will not be shown again')" data-test="new-connection-link">
                <flux:callout.text>
                    <code class="block select-all whitespace-pre-wrap rounded bg-white px-2 py-1 font-mono text-xs text-zinc-900 dark:bg-zinc-900 dark:text-zinc-100">{{ __('Connect this repository to Akord: :link', ['link' => $newLink]) }}</code>
                    <span class="mt-3 block">{{ __('It works once and expires in :hours hours.', ['hours' => config('akord.agents.connection_link_hours')]) }}</span>
                </flux:callout.text>
            </flux:callout>
        @endif

        @if ($this->links->isNotEmpty())
            <div class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 text-sm dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($this->links as $link)
                    <div class="flex flex-wrap items-center justify-between gap-2 px-3 py-2" wire:key="link-{{ $link->id }}">
                        <span>{{ __('Link created :date', ['date' => $link->created_at?->diffForHumans()]) }}</span>
                        <flux:badge size="sm" :color="$link->used_at ? 'green' : ($link->expires_at->isPast() ? 'zinc' : 'sky')">
                            {{ $link->used_at ? __('Used') : ($link->expires_at->isPast() ? __('Expired') : __('Waiting to be used')) }}
                        </flux:badge>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="space-y-1 text-sm">
            <div>{{ __('MCP URL, for setting a client up by hand') }}: <code class="select-all font-mono text-xs" data-test="party-mcp-url">{{ $this->mcpUrl }}</code></div>
            <div>{{ __('For the review skill in omp, also install the plugin:') }} <code class="select-all font-mono text-xs">omp plugin marketplace add PatFox/Akord</code>, <code class="select-all font-mono text-xs">omp plugin install akord@akord</code>.</div>
        </div>

        @if ($this->connections->isNotEmpty())
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Tool') }}</flux:table.column>
                    <flux:table.column>{{ __('Approved by') }}</flux:table.column>
                    <flux:table.column>{{ __('Last used') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->connections as $connection)
                        <flux:table.row :key="'connection-'.$connection->id">
                            <flux:table.cell class="font-medium">{{ $connection->client?->name ?? __('Unknown tool') }}</flux:table.cell>
                            <flux:table.cell>{{ $connection->approver?->name }}</flux:table.cell>
                            <flux:table.cell>{{ $connection->last_used_at?->diffForHumans() ?? __('Never') }}</flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="disconnect('{{ $connection->id }}')" wire:confirm="{{ __('Disconnect this tool? It loses access immediately.') }}">{{ __('Disconnect') }}</flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Or: a one-time claim code') }}</flux:heading>
        <flux:text size="sm">{{ __('For agents that call the REST API or cannot sign in through a browser. The agent exchanges the code for an API token with this party’s abilities.') }}</flux:text>
        <flux:button wire:click="generate" variant="primary" icon="key">{{ __('Generate claim code') }}</flux:button>

        @if ($newCode)
            <flux:callout icon="key" color="amber" :heading="__('Copy this code now; it will not be shown again')" data-test="new-claim-code">
                <flux:callout.text>
                    <code class="block select-all rounded bg-white px-2 py-1 font-mono text-base text-zinc-900 dark:bg-zinc-900 dark:text-zinc-100">{{ $newCode }}</code>
                    <span class="mt-3 block">{{ __('It works once and expires in :hours hours. The agent claims a token with:', ['hours' => config('akord.agents.claim_code_hours')]) }}</span>
                    <code class="mt-1 block select-all whitespace-pre-wrap rounded bg-white px-2 py-1 font-mono text-xs text-zinc-900 dark:bg-zinc-900 dark:text-zinc-100">curl -X POST {{ url('/api/v1/agent/claim') }} -H "Accept: application/json" -H "Content-Type: application/json" -d '{"code":"{{ $newCode }}","name":"my-agent"}'</code>
                    <span class="mt-3 block">{{ __('Then use the token as a Bearer token for the REST API at :api or the MCP server at :mcp.', ['api' => url('/api/v1'), 'mcp' => $this->mcpUrl]) }}</span>
                </flux:callout.text>
            </flux:callout>
        @endif

        @if ($this->codes->isNotEmpty())
            <div class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 text-sm dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($this->codes as $code)
                    <div class="flex flex-wrap items-center justify-between gap-2 px-3 py-2" wire:key="code-{{ $code->id }}">
                        <span>{{ __('Created :date', ['date' => $code->created_at?->diffForHumans()]) }}</span>
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
                    <flux:table.column>{{ __('Last used') }}</flux:table.column>
                    <flux:table.column>{{ __('Created') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->tokens as $token)
                        <flux:table.row :key="'token-'.$token->id">
                            <flux:table.cell class="font-medium">{{ $token->name }}</flux:table.cell>
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
            <div class="flex items-center gap-2" wire:key="contract-{{ $contract->id }}">
                <flux:link :href="route('contracts.show', $contract)" wire:navigate>{{ $contract->title }} v{{ $contract->version }}</flux:link>
                <flux:badge size="sm" data-test="contract-role">{{ $contract->pivot->role->label() }}</flux:badge>
            </div>
        @empty
            <flux:text size="sm">{{ __('Not attached to any contract yet. Attach it from a contract’s settings.') }}</flux:text>
        @endforelse
    </div>
</section>
