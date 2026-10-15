<x-mail::message>
# {{ __('whitelabel.mail.verify_heading') }}

{{ __('whitelabel.mail.verify_greeting', ['name' => $name]) }}

{{ __('whitelabel.mail.verify_intro', ['product' => app(\App\Services\WhiteLabel\BrandingService::class)->forMail()['product_name']]) }}

<x-mail::button :url="$verifyUrl">
{{ __('whitelabel.mail.verify_button') }}
</x-mail::button>

{{ __('whitelabel.mail.verify_expiry', ['hours' => $ttlHours]) }}

{{ __('whitelabel.mail.verify_ignore') }}
</x-mail::message>
