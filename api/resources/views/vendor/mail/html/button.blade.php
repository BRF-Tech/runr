@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
])
@php($brandColor = app(\App\Services\WhiteLabel\BrandingService::class)->forMail()['primary_color'])
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
@if ($color === 'primary')
<a href="{{ $url }}" class="button button-{{ $color }}" target="_blank" rel="noopener" style="background-color: {{ $brandColor }}; border-bottom: 8px solid {{ $brandColor }}; border-left: 18px solid {{ $brandColor }}; border-right: 18px solid {{ $brandColor }}; border-top: 8px solid {{ $brandColor }};">{!! $slot !!}</a>
@else
<a href="{{ $url }}" class="button button-{{ $color }}" target="_blank" rel="noopener">{!! $slot !!}</a>
@endif
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>
