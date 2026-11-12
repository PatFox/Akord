<?php

use App\Actions\Invitations\AcceptInvitation;
use App\Casts\NormalisedEmail;
use App\Livewire\Concerns\RunsActions;
use App\Models\Invitation;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    use RunsActions;

    #[Locked]
    public string $token = '';

    public function mount(string $token): void
    {
        abort_if(Invitation::findByToken($token) === null, 404);
        $this->token = $token;

        if (Auth::guest()) {
            // Log in or register, then come back here.
            session(['url.intended' => route('invitations.show', ['token' => $token])]);
        }
    }

    public function invitation(): Invitation
    {
        return Invitation::findByToken($this->token)?->load(['contract', 'party', 'organisation', 'inviter'])
            ?? abort(404);
    }

    public function accept(): void
    {
        $user = Auth::user() ?? abort(403);

        $invitation = $this->attempt(fn () => app(AcceptInvitation::class)->handle($user, $this->token));

        if ($invitation !== null) {
            session()->flash('status', __('Invitation accepted.'));
            $this->redirectRoute('contracts.show', ['contract' => $invitation->contract_id], navigate: true);
        }
    }

    public function render()
    {
        return $this->view(['invitation' => $this->invitation()])
            ->layout('layouts::auth')
            ->title(__('Invitation'));
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header :title="__('You are invited')" :description="__(':inviter invited you to join :party in :organisation.', [
        'inviter' => $invitation->inviter?->name ?? __('An owner'),
        'party' => $invitation->party->name,
        'organisation' => $invitation->organisation->name,
    ])" />

    <flux:card class="space-y-1 text-center">
        <flux:text size="sm">{{ __('Contract') }}</flux:text>
        <flux:heading size="lg">{{ $invitation->contract->title }}</flux:heading>
        <flux:text size="sm">{{ __('For :email', ['email' => $invitation->email]) }}</flux:text>
    </flux:card>

    @if ($invitation->status() !== 'pending')
        <flux:callout icon="x-circle" color="red" data-test="invitation-unavailable">
            <flux:callout.text>
                @switch($invitation->status())
                    @case('accepted') {{ __('This invitation has already been used.') }} @break
                    @case('cancelled') {{ __('This invitation was cancelled.') }} @break
                    @default {{ __('This invitation has expired. Ask the person who invited you to resend it.') }}
                @endswitch
            </flux:callout.text>
        </flux:callout>
        @auth
            <flux:button :href="route('dashboard')" wire:navigate>{{ __('Go to your organisations') }}</flux:button>
        @endauth
    @elseguest
        <flux:text class="text-center">{{ __('Sign in, or create an account, with :email to accept.', ['email' => $invitation->email]) }}</flux:text>
        <div class="flex flex-col gap-2">
            <flux:button variant="primary" :href="route('login')">{{ __('Log in') }}</flux:button>
            @if (Route::has('register'))
                <flux:button :href="route('register')">{{ __('Create an account') }}</flux:button>
            @endif
        </div>
    @else
        @if (\App\Casts\NormalisedEmail::normalise(auth()->user()->email) !== $invitation->email)
            <flux:callout icon="exclamation-triangle" color="amber" data-test="invitation-wrong-account">
                <flux:callout.text>{{ __('You are signed in as :current, but this invitation is for :invited. Log out and sign in with that address.', ['current' => auth()->user()->email, 'invited' => $invitation->email]) }}</flux:callout.text>
            </flux:callout>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:button type="submit" class="w-full">{{ __('Log out') }}</flux:button>
            </form>
        @else
            <flux:button variant="primary" wire:click="accept" data-test="accept-invitation">{{ __('Accept invitation') }}</flux:button>
        @endif
    @endif
</div>
