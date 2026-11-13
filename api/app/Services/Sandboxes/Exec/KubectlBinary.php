<?php

namespace App\Services\Sandboxes\Exec;

use Illuminate\Support\Facades\Log;

/**
 * The kubectl that runs `kubectl exec` against a region's cluster: sandbox commands the native
 * client cannot run (SelectingExecTransport, KubectlExecTransport, SandboxExecSocket) and the
 * console's terminal (ShellPtyProcess).
 *
 * kubectl is supported within one minor of the API server it talks to, and RunR accepts clusters
 * from Kubernetes 1.30 on (knative.min_k8s_minor) with no upper bound. The API image therefore
 * carries a kubectl for every second minor — 1.31, 1.33, 1.35 and 1.37, each of which covers the
 * minor below and the one above it — at sandbox.exec.kubectl_dir/<minor>/kubectl; `kubectl` on
 * PATH is the newest of them. The cluster's version is the one the region recorded
 * (knative_regions.kubernetes_version: the kubeconfig check of the region's creation, a replaced
 * kubeconfig and a migration, refreshed by region:reconcile).
 *
 * The pick: the bundled minor of the cluster's, else the one below it, else the one above; a
 * cluster further from every bundled minor takes the nearest (the newest for a newer cluster)
 * and a warning is logged once per process and minor. A region without a recorded version takes
 * the newest. sandbox.exec.kubectl (SANDBOX_KUBECTL) set names the binary for every cluster.
 */
final class KubectlBinary
{
    /** @var array<string, array<int, string>> sandbox.exec.kubectl_dir => (minor => binary) */
    private static array $bundled = [];

    /** @var array<int, true> the cluster minors a warning was logged for in this process */
    private static array $warned = [];

    /** The binary for a cluster of $kubernetesVersion ("v1.34.2", "1.34", null: not recorded). */
    public static function for(?string $kubernetesVersion): string
    {
        $override = config('sandbox.exec.kubectl');
        if (is_string($override) && trim($override) !== '') {
            return $override;
        }

        $bundled = self::bundled();
        if ($bundled === []) {
            return 'kubectl';
        }

        $minor = self::minorOf($kubernetesVersion);
        if ($minor === null) {
            return $bundled[max(array_keys($bundled))];
        }

        $pick = self::pick($minor, array_keys($bundled));
        if (abs($pick - $minor) > 1 && ! isset(self::$warned[$minor])) {
            self::$warned[$minor] = true;
            Log::warning("kubectl: no kubectl of the API image is within one minor of Kubernetes 1.{$minor}: kubectl 1.{$pick} runs exec on these clusters", [
                'cluster' => "1.{$minor}",
                'kubectl' => "1.{$pick}",
                'bundled' => array_map(fn (int $m) => "1.{$m}", array_keys($bundled)),
            ]);
        }

        return $bundled[$pick];
    }

    /**
     * The bundled minor for a cluster minor: the same, else the one below, else the one above;
     * outside one minor of every bundled one, the nearest (the lower of two as near).
     *
     * @param  list<int>  $minors  non-empty
     */
    public static function pick(int $cluster, array $minors): int
    {
        sort($minors);
        $best = $minors[0];
        foreach ($minors as $m) {
            $d = abs($m - $cluster);
            $bestD = abs($best - $cluster);
            if ($d < $bestD || ($d === $bestD && $m < $best)) {
                $best = $m;
            }
        }

        return $best;
    }

    /** The minor of a Kubernetes 1.x version ("v1.34.2-gke.1", "1.34", "1.34+"); null otherwise. */
    public static function minorOf(?string $version): ?int
    {
        return $version !== null && preg_match('/^v?1\.(\d+)/', trim($version), $m) ? (int) $m[1] : null;
    }

    /**
     * The version a region records from the cluster's /version answer: its gitVersion, else
     * "v<major>.<minor>"; null when the answer holds neither.
     *
     * @param  array<string, mixed>|null  $info
     */
    public static function versionFrom(?array $info): ?string
    {
        if (! $info) {
            return null;
        }

        $git = trim((string) ($info['gitVersion'] ?? ''));
        if ($git !== '' && self::minorOf($git) !== null) {
            return mb_substr($git, 0, 64);
        }

        $major = trim((string) ($info['major'] ?? ''));
        $minor = rtrim(trim((string) ($info['minor'] ?? '')), '+');

        return ctype_digit($major) && ctype_digit($minor) ? "v{$major}.{$minor}" : null;
    }

    /**
     * The kubectl binaries of the image, minor => path: every <dir>/1.<minor>/kubectl that is
     * executable, read once per process.
     *
     * @return array<int, string>
     */
    public static function bundled(): array
    {
        $dir = rtrim((string) config('sandbox.exec.kubectl_dir', '/usr/local/lib/runr/kubectl'), '/');
        if (! array_key_exists($dir, self::$bundled)) {
            $found = [];
            foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $entry) {
                if (preg_match('/^1\.(\d+)$/', $entry, $m) && is_file("{$dir}/{$entry}/kubectl") && is_executable("{$dir}/{$entry}/kubectl")) {
                    $found[(int) $m[1]] = "{$dir}/{$entry}/kubectl";
                }
            }
            ksort($found);
            self::$bundled[$dir] = $found;
        }

        return self::$bundled[$dir];
    }

    /** Forget what was read and warned (tests). */
    public static function flush(): void
    {
        self::$bundled = [];
        self::$warned = [];
    }
}
