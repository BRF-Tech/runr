@php($brand = app(\App\Services\WhiteLabel\BrandingService::class)->forMail())
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="$brand['app_url']">
{{ $brand['product_name'] }}
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
@foreach ($brand['footer_links'] as $link)
{{ $link['label'] }}: {{ $link['url'] }}
@endforeach
@if (!empty($brand['support_email']))
{{ __('whitelabel.mail.support') }} {{ $brand['support_email'] }}
@endif
© {{ date('Y') }} {{ $brand['company_name'] }}. {{ __('whitelabel.mail.rights') }}
@if ($brand['powered_by'] && !$brand['is_default_brand'])
{{ __('whitelabel.mail.powered_by') }}
@endif
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
