<x-mail::message>
# {{ __('You have been invited!') }}

{{ __(':tenant has invited you to join their workspace on :app.', [
    'tenant' => $invitation->tenant->name,
    'app'    => config('app.name'),
]) }}

**{{ __('Role') }}:** {{ ucfirst($invitation->role) }}

<x-mail::button :url="$acceptUrl">
{{ __('Accept Invitation') }}
</x-mail::button>

{{ __('This invitation expires on :date.', ['date' => $invitation->expires_at->format('d M Y H:i')]) }}

{{ __('If you did not expect this invitation, you can ignore this email.') }}

{{ config('app.name') }}
</x-mail::message>
