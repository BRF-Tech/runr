<x-mail::message>
# {{ $level === 'critical' ? '🔴 Critical' : '🟡 Warning' }}: {{ $typeLabel }} {{ $percentage }}%{{ $withStopped ? ' with stopped projects' : '' }}

**Region:** {{ $regionName }}
**Resource:** {{ $typeLabel }}
@if ($withStopped)
**With every stopped project resumed:** {{ $percentage }}%
@else
**Usage:** {{ $percentage }}%
@endif
**Threshold:** {{ $threshold }}%

@if ($withStopped && $advice)
{{ $advice }}

@if (count($projects) > 0)
<x-mail::table>
| Stopped project | {{ $typeLabel }} |
| :-- | --: |
@foreach ($projects as $project)
| {{ $project['name'] }} | {{ $project['amount'] }}{{ $unit }} |
@endforeach
</x-mail::table>
@endif
@endif

<x-mail::button :url="$settingsUrl">
View Settings
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
