<x-mail::message>
# {{ __('You are invited to a contract') }}

{{ __(':inviter invited you to join :party in :organisation, to review the contract “:contract”.', compact('inviter', 'party', 'organisation', 'contract')) }}

<x-mail::button :url="$url">
{{ __('View invitation') }}
</x-mail::button>

{{ __('The link works once and expires on :date. Sign in, or create an account, with this email address to accept it.', ['date' => $expires]) }}

{{ __('If you were not expecting this, you can ignore this email.') }}
</x-mail::message>
