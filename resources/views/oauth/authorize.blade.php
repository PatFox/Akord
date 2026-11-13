@php
    /** @var \Laravel\Passport\Client $client */
    $user = auth()->user();
    $parties = \App\Models\Party::query()
        ->where('type', \App\Enums\PartyType::Agent->value)
        ->whereIn('organisation_id', $user->organisations()->wherePivot('role', \App\Enums\OrganisationRole::Owner->value)->pluck('organisations.id'))
        ->with('organisation')
        ->orderBy('name')
        ->get();
    // MCP clients send the server URL as `resource`. A party's own URL (/mcp/agents/{id}) fixes the
    // choice: that URL only accepts sign-ins approved for its party (App\Support\Agents\AgentActor).
    $lockedPartyId = \App\Support\Agents\PartyMcpUrl::partyIdFrom($request->query('resource'));
    if ($lockedPartyId !== null) {
        $parties = $parties->where('id', $lockedPartyId)->values();
    }
    $redirect = (string) $request->query('redirect_uri', '');
    $redirectHost = parse_url($redirect, PHP_URL_HOST) ?: $redirect;
    $isLoopback = in_array($redirectHost, ['localhost', '127.0.0.1', '[::1]'], true);
@endphp

<x-layouts::auth :title="__('Connect :name', ['name' => $client->name])">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Connect :name to Akord', ['name' => $client->name])"
                       :description="__('This tool will act as one of your agent parties, with the abilities you choose.')" />

        <flux:callout :color="$isLoopback ? 'zinc' : 'amber'" icon="information-circle">
            <flux:callout.text>
                {{ __('After you approve, Akord sends you back to') }} <strong>{{ $redirectHost }}</strong>{{ $isLoopback ? ' '.__('(a program on this computer)') : '' }}.
                {{ __('Only continue if you started this connection yourself.') }}
            </flux:callout.text>
        </flux:callout>

        @if ($parties->isEmpty())
            <flux:callout color="red" icon="x-circle">
                <flux:callout.text>
                    {{ $lockedPartyId !== null
                        ? __('This sign-in is for an agent party you are not an owner of. Sign in with an owner of its organisation, or check the URL in the tool’s MCP settings.')
                        : __('You have no agent parties. Create one under Parties in an organisation you own, attach it to a contract, then try again.') }}
                </flux:callout.text>
            </flux:callout>
        @else
            <form method="POST" action="{{ route('agents.connect.approve') }}" class="flex flex-col gap-4" data-test="connect-form">
                @csrf
                <input type="hidden" name="auth_token" value="{{ $authToken }}">

                @if ($lockedPartyId !== null)
                    <input type="hidden" name="party_id" value="{{ $parties->first()->id }}">
                    <flux:input :label="__('Act as agent party')" :value="$parties->first()->name.' — '.$parties->first()->organisation->name" readonly data-test="locked-party" />
                    <flux:text size="sm">{{ __('Set by the URL this tool connects to.') }}</flux:text>
                @else
                    <flux:select name="party_id" :label="__('Act as agent party')">
                        @foreach ($parties as $party)
                            <flux:select.option :value="$party->id">{{ $party->name }} — {{ $party->organisation->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <div class="flex flex-col gap-2">
                    <flux:label>{{ __('Abilities') }}</flux:label>
                    <flux:checkbox checked disabled :label="__('Read contracts')" />
                    <flux:checkbox name="abilities[]" value="comment" checked :label="__('Comment')" />
                    <flux:checkbox name="abilities[]" value="edit" :label="__('Edit steps')" />
                    <flux:checkbox name="abilities[]" value="approve" :label="__('Approve and sign off')" />
                    <flux:text size="sm">{{ __('The party’s role on each contract still applies.') }}</flux:text>
                </div>

                <flux:button type="submit" variant="primary" class="w-full" data-test="approve-connection">{{ __('Connect') }}</flux:button>
            </form>
        @endif

        <form method="POST" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <flux:button type="submit" variant="ghost" class="w-full">{{ __('Cancel') }}</flux:button>
        </form>
    </div>
</x-layouts::auth>
