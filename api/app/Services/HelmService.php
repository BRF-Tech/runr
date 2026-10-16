<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class HelmService
{
    /** Tries of a Helm call that keeps failing on transient API server errors (retryTransient) */
    public const TRANSIENT_TRIES = 5;

    /** Seconds before the second try; doubled before each next one, at most TRANSIENT_MAX_WAIT */
    public const TRANSIENT_FIRST_WAIT = 2;

    public const TRANSIENT_MAX_WAIT = 30;

    private ?string $tmpKubeconfig = null;

    public function __destruct()
    {
        if ($this->tmpKubeconfig && file_exists($this->tmpKubeconfig)) {
            @unlink($this->tmpKubeconfig);
            $this->tmpKubeconfig = null;
        }
    }

    /**
     * The values file of an install. An empty PHP array is written as {} — a map, which is what an
     * empty array of a values builder means wherever the chart wants one (Traefik's
     * logs.access.filters, …); an empty list goes into a values file left out (the chart default)
     * or as a non-empty list, never as [].
     */
    public static function valuesYaml(array $values): string
    {
        return Yaml::dump($values, 10, 2);
    }

    /**
     * $attempt (a Helm call) tried again while it fails on a transient error of the Kubernetes API
     * server (HelmResult::transientApiError: an etcd timeout, a 5xx answer, a dropped connection),
     * $tries tries in all, 2, 4, 8, 16 s apart (at most TRANSIENT_MAX_WAIT). Any other result — a
     * success, a failure of the release itself — is returned at once, as is the last try's.
     * $onRetry gets the failed result, the number of the next try and the wait before it.
     *
     * @param  callable(): HelmResult  $attempt
     * @param  (callable(HelmResult, int, int): mixed)|null  $onRetry
     */
    public static function retryTransient(callable $attempt, ?callable $onRetry = null, int $tries = self::TRANSIENT_TRIES): HelmResult
    {
        for ($try = 1; ; $try++) {
            $result = $attempt();
            if ($result->success || $try >= $tries || ! $result->transientApiError()) {
                return $result;
            }

            $wait = min(self::TRANSIENT_MAX_WAIT, self::TRANSIENT_FIRST_WAIT * 2 ** ($try - 1));
            Log::warning('Helm: transient Kubernetes API error, trying again', [
                'try' => $try + 1,
                'of' => $tries,
                'wait_s' => $wait,
                'error' => $result->errorLine(),
            ]);
            if ($onRetry) {
                $onRetry($result, $try + 1, $wait);
            }
            Sleep::for($wait)->seconds();
        }
    }

    /**
     * Install or upgrade a Helm release (upgrade --install).
     */
    public function install(
        string $release,
        string $chart,
        string $namespace,
        array $setValues,
        string $kubeconfigYaml,
        ?string $version = null,
        array $valuesArray = [],
        array $setJsonValues = [],
        bool $wait = true,
    ): HelmResult {
        $args = [
            'helm', 'upgrade', '--install', $release, $chart,
            '-n', $namespace,
            '--create-namespace',
            '--kubeconfig', $this->writeKubeconfig($kubeconfigYaml),
        ];

        if ($wait) {
            $args[] = '--wait';
            $args[] = '--timeout';
            $args[] = '10m';
        }

        if ($version) {
            $args[] = '--version';
            $args[] = $version;
        }

        $tmpValues = null;
        if ($valuesArray) {
            $tmpValues = tempnam(sys_get_temp_dir(), 'runr-helm-vals-');
            file_put_contents($tmpValues, self::valuesYaml($valuesArray));
            $args[] = '--values';
            $args[] = $tmpValues;
        }

        foreach ($setValues as $key => $value) {
            $args[] = '--set';
            $args[] = "{$key}={$value}";
        }

        foreach ($setJsonValues as $key => $value) {
            $args[] = '--set-json';
            $args[] = "{$key}={$value}";
        }

        $result = $this->run($args);

        if ($tmpValues && file_exists($tmpValues)) {
            @unlink($tmpValues);
        }

        return $result;
    }

    /**
     * Upgrade an existing release.
     */
    public function upgrade(
        string $release,
        string $chart,
        string $namespace,
        array $setValues,
        string $kubeconfigYaml,
        ?string $version = null,
    ): HelmResult {
        return $this->install($release, $chart, $namespace, $setValues, $kubeconfigYaml, $version);
    }

    /**
     * Upgrade an existing release keeping all its values (--reuse-values) except the given
     * --set-json keys, at the chart version it already runs ($version, deployedChartVersion() —
     * never the repo's latest). Used by region reconcile steps: only the named keys change.
     * $wait false returns once the manifests are applied (rollouts continue in the cluster).
     *
     * @param  array<string, string>  $setJsonValues  key => JSON value
     */
    public function upgradeReusingValues(
        string $release,
        string $chart,
        string $namespace,
        string $kubeconfigYaml,
        string $version,
        array $setJsonValues,
        bool $wait = false,
    ): HelmResult {
        $args = [
            'helm', 'upgrade', $release, $chart,
            '-n', $namespace,
            '--kubeconfig', $this->writeKubeconfig($kubeconfigYaml),
            '--reuse-values',
            '--version', $version,
        ];

        if ($wait) {
            $args[] = '--wait';
            $args[] = '--timeout';
            $args[] = '10m';
        }

        foreach ($setJsonValues as $key => $value) {
            $args[] = '--set-json';
            $args[] = "{$key}={$value}";
        }

        return $this->run($args);
    }

    /**
     * Uninstall a release.
     */
    public function uninstall(string $release, string $namespace, string $kubeconfigYaml): HelmResult
    {
        $args = [
            'helm', 'uninstall', $release,
            '-n', $namespace,
            '--kubeconfig', $this->writeKubeconfig($kubeconfigYaml),
        ];

        return $this->run($args);
    }

    /**
     * Chart version a release runs: `helm list -o json` ("chart": "loki-stack-2.10.3" → "2.10.3").
     * `helm status -o json` cannot tell — Helm strips the chart from it (cmd/helm/status.go,
     * rel.Chart = nil). Null when the release is not listed (not installed, or pending: `helm
     * list` shows deployed and failed releases), runs another chart, or helm fails.
     *
     * @param  string  $chartName  chart name without repo ("loki-stack", "traefik")
     */
    public function deployedChartVersion(string $release, string $namespace, string $kubeconfigYaml, string $chartName): ?string
    {
        $result = $this->run([
            'helm', 'list',
            '-n', $namespace,
            '--kubeconfig', $this->writeKubeconfig($kubeconfigYaml),
            '--filter', '^'.$release.'$',
            '-o', 'json',
        ]);

        return $result->success ? self::chartVersionFromList($result->output, $release, $chartName) : null;
    }

    /**
     * @param  string  $json  `helm list -o json` output
     */
    public static function chartVersionFromList(string $json, string $release, string $chartName): ?string
    {
        $rows = json_decode($json, true);

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (($row['name'] ?? null) !== $release) {
                continue;
            }

            $chart = (string) ($row['chart'] ?? '');
            $prefix = $chartName.'-';
            if (! str_starts_with($chart, $prefix)) {
                return null;
            }

            $version = substr($chart, strlen($prefix));

            return preg_match('/^v?\d+\.\d+\.\d+([-+][0-9A-Za-z.+-]+)?$/', $version) === 1 ? $version : null;
        }

        return null;
    }

    /**
     * Get status of a Helm release, returns null if not found. The output carries no chart
     * (Helm strips it) — deployedChartVersion() for the chart version.
     */
    public function status(string $release, string $namespace, string $kubeconfigYaml): ?array
    {
        $args = [
            'helm', 'status', $release,
            '-n', $namespace,
            '--kubeconfig', $this->writeKubeconfig($kubeconfigYaml),
            '-o', 'json',
        ];

        $result = $this->run($args);

        if (! $result->success) {
            return null;
        }

        return json_decode($result->output, true);
    }

    /**
     * Add a Helm repo.
     */
    public function repoAdd(string $name, string $url): HelmResult
    {
        return $this->run(['helm', 'repo', 'add', $name, $url, '--force-update']);
    }

    /**
     * Update Helm repos.
     */
    public function repoUpdate(): HelmResult
    {
        return $this->run(['helm', 'repo', 'update']);
    }

    /**
     * Read all chart entries from a repo's local index cache.
     * Returns entries with version, appVersion, kubeVersion, description etc.
     */
    public function getRepoChartEntries(string $repoName, string $chartName): array
    {
        $result = $this->run(['helm', 'env', 'HELM_CACHE_HOME']);
        if (! $result->success) {
            return [];
        }

        $cacheHome = trim($result->output);
        $indexPath = $cacheHome.'/repository/'.$repoName.'-index.yaml';

        if (! file_exists($indexPath)) {
            return [];
        }

        $index = Yaml::parseFile($indexPath);

        return $index['entries'][$chartName] ?? [];
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function writeKubeconfig(string $yaml): string
    {
        if ($this->tmpKubeconfig && file_exists($this->tmpKubeconfig)) {
            @unlink($this->tmpKubeconfig);
        }

        $path = tempnam(sys_get_temp_dir(), 'runr-helm-kc-');
        file_put_contents($path, $yaml);
        chmod($path, 0600);
        $this->tmpKubeconfig = $path;

        return $path;
    }

    protected function run(array $command): HelmResult
    {
        Log::debug('Helm command', ['cmd' => implode(' ', $command)]);

        $process = new Process($command);
        $process->setTimeout(660); // 11 min
        $process->run();

        $result = new HelmResult(
            success: $process->isSuccessful(),
            output: $process->getOutput(),
            error: $process->getErrorOutput(),
            exitCode: $process->getExitCode(),
        );

        if (! $result->success) {
            Log::error('Helm command failed', [
                'cmd' => implode(' ', array_slice($command, 0, 5)),
                'exitCode' => $result->exitCode,
                'error' => $result->error,
            ]);
        }

        return $result;
    }
}
