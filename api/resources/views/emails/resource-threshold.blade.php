<x-mail::message>
# {{ $level === 'critical' ? '🔴 Critical' : '🟡 Warning' }}: {{ $typeLabel }} {{ $percentage }}%

**Region:** {{ $regionName }}
**Resource:** {{ $typeLabel }}
**Usage:** {{ $percentage }}%
**Threshold:** {{ $threshold }}%

<x-mail::button :url="$settingsUrl">
View Settings
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
