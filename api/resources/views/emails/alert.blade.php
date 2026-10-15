<x-mail::message>
# {{ $alert->title }}

{{ $alert->text }}

@foreach ($alert->fields as $label => $value)
**{{ $label }}:** {{ $value }}
@endforeach

@if ($alert->url)
<x-mail::button :url="$alert->url">
Open in RunR
</x-mail::button>
@endif

{{ config('app.name') }}
</x-mail::message>
