<x-mail::message>
# {{ $title }}

{!! nl2br(e($text)) !!}

@if ($actionUrl)
<x-mail::button :url="$actionUrl">
{{ __('promotions.open') }}
</x-mail::button>
@endif

{{ config('app.name') }}
</x-mail::message>
