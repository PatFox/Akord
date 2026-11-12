<?php

use App\Actions\Organisations\CreateAgentClaimCode;
use App\Actions\Organisations\RevokeAgentToken;
use App\Livewire\Concerns\RunsActions;
use App\Models\AgentClaimCode;
use App\Models\Contract;
use App\Models\Organisation;
use App\Models\Party;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
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
        <flux:heading size="lg" level="2">{{ __('New claim code') }}</flux:heading>
        <form wire:submit="generate" class="space-y-3">
            <div class="flex flex-wrap gap-4">
                <flux:checkbox checked disabled :label="__('Read')" />
                <flux:checkbox wire:model="abilities" value="comment" :label="__('Comment')" />
                <flux:checkbox wire:model="abilities" value="edit" :label="__('Edit steps')" />
                <flux:checkbox wire:model="abilities" value="approve" :label="__('Approve and sign off')" />
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
                            <flux:table.cell>{{ implode(', ', $token->abilities) }}</flux:table.cell>
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
