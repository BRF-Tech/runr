<?php

namespace App\Services\Concerns;

use App\Models\MetricSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

trait MetricsDownsampleTrait
{
    /**
     * Downsample fine-grained snapshots into coarser granularity.
     *
     * Tiers:
     *  - 1m → 5m  after 7 days
     *  - 5m → 1h  after 30 days
     *  - 1h → 1d  after 90 days
     *
     * A bucket holds the average of its samples' CPU, memory, requests per second, concurrency
     * and pods (rounded) and the sum of their cold starts; metadata.samples counts the 1-minute
     * samples behind it (a row without the count is one). Samples age past a tier hour by hour,
     * so later runs find the bucket already there: they fold their rows into it, weighted by the
     * samples on both sides, instead of dropping them.
     */
    public function downsample(): void
    {
        $this->downsampleTier('1m', '5m', 7, 5);
        $this->downsampleTier('5m', '1h', 30, 60);
        $this->downsampleTier('1h', '1d', 90, 1440);
    }

    private function downsampleTier(string $sourceGranularity, string $targetGranularity, int $olderThanDays, int $bucketMinutes): void
    {
        $cutoff = now()->subDays($olderThanDays);

        $rows = MetricSnapshot::where('granularity', $sourceGranularity)
            ->where('recorded_at', '<', $cutoff)
            ->orderBy('recorded_at')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $grouped = $rows->groupBy(function ($row) use ($bucketMinutes) {
            $ts = $row->recorded_at->timestamp;
            $bucket = intdiv($ts, $bucketMinutes * 60) * $bucketMinutes * 60;

            return $row->project_id.'|'.$row->region_id.'|'.$bucket;
        });

        $folded = 0;

        foreach ($grouped as $key => $group) {
            [$projectId, $regionId, $bucketTs] = explode('|', $key);
            $recordedAt = date('Y-m-d H:i:s', (int) $bucketTs);

            $bucket = MetricSnapshot::where('project_id', $projectId)
                ->where('region_id', $regionId)
                ->where('granularity', $targetGranularity)
                ->where('recorded_at', $recordedAt)
                ->first();

            if ($bucket) {
                $values = self::fold($group->concat([$bucket]));
                $values['metadata'] = array_merge((array) ($bucket->metadata ?? []), $values['metadata']);
                $bucket->update($values);
            } else {
                MetricSnapshot::create([
                    'project_id' => $projectId,
                    'deployment_id' => $group->first()->deployment_id,
                    'region_id' => $regionId,
                    'recorded_at' => $recordedAt,
                    'granularity' => $targetGranularity,
                ] + self::fold($group));
            }

            MetricSnapshot::whereIn('id', $group->pluck('id'))->delete();
            $folded++;
        }

        Log::info("Downsampled metrics {$sourceGranularity}→{$targetGranularity}", ['buckets' => $folded]);
    }

    /**
     * The values of a bucket made of $rows: sample-weighted averages, cold starts summed.
     *
     * @param  Collection<int, MetricSnapshot>  $rows
     * @return array<string, mixed>
     */
    private static function fold(Collection $rows): array
    {
        $weight = fn (MetricSnapshot $row): int => max(1, (int) ($row->metadata['samples'] ?? 1));

        $average = function (string $column) use ($rows, $weight): ?float {
            $samples = 0;
            $total = 0.0;
            foreach ($rows as $row) {
                $value = $row->getAttribute($column);
                if ($value === null) {
                    continue;   // a sample without this value does not count for it
                }
                $samples += $weight($row);
                $total += (float) $value * $weight($row);
            }

            return $samples === 0 ? null : $total / $samples;
        };

        $pods = $average('pod_count');

        return [
            'cpu_cores' => $average('cpu_cores'),
            'memory_mb' => $average('memory_mb'),
            'rps' => $average('rps'),
            'concurrency' => $average('concurrency'),
            'pod_count' => $pods === null ? null : (int) round($pods),
            'cold_starts' => (int) $rows->sum('cold_starts'),
            'metadata' => ['samples' => (int) $rows->sum($weight)],
        ];
    }
}
