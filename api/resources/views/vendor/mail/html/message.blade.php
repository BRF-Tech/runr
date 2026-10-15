{{-- Branded mail layout (white-label, docs/white-label.md): product name / logo, brand colours, footer links --}}
@php($brand = app(\App\Services\WhiteLabel\BrandingService::class)->forMail())
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="$brand['app_url']">
@if (!empty($brand['mail_logo_url']))
<img src="{{ $brand['mail_logo_url'] }}" class="logo" alt="{{ $brand['product_name'] }}" style="height: 48px; width: auto; max-width: 240px;">
@else
<span style="color: {{ $brand['primary_color'] }};">{{ $brand['product_name'] }}</span>
@endif
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
@if (!empty($brand['footer_links']))
@foreach ($brand['footer_links'] as $link)
[{{ $link['label'] }}]({{ $link['url'] }})@if (!$loop->last) · @endif
@endforeach

@endif
@if (!empty($brand['support_email']))
{{ __('whitelabel.mail.support') }} [{{ $brand['support_email'] }}](mailto:{{ $brand['support_email'] }})
@endif
@if (!empty($brand['support_url']))
[{{ $brand['support_url'] }}]({{ $brand['support_url'] }})
@endif

© {{ date('Y') }} {{ $brand['company_name'] }}. {{ __('whitelabel.mail.rights') }}
@if ($brand['powered_by'] && !$brand['is_default_brand'])

{{ __('whitelabel.mail.powered_by') }}
@endif
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
