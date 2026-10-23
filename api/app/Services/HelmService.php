<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class HelmService
{
    /**
     * Age after which a pending revision belongs to no running helm process (recoverInterrupted):
     * helm's own --timeout 10m of a waiting upgrade, the 660 s RunR gives a helm process, and two
     * minutes.
     */
    public const INTERRUPTED_AFTER_SECONDS = 780;

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
        $result = $this->runInstall($release, $chart, $namespace, $setValues, $kubeconfigYaml, $version, $valuesArray, $setJsonValues, $wait);

        // the release's last operation was interrupted (a killed helm process): rolled back once
        // it is old enough, then the install runs again, once (recoverInterrupted)
        if (self::operationInProgress($result)) {
            try {
                $recovered = $this->recoverInterrupted($release, $namespace, $kubeconfigYaml);
            } catch (RuntimeException $e) {
                return new HelmResult(false, $result->output, trim($result->error).' — '.$e->getMessage(), $result->exitCode);
            }
            if ($recovered !== null) {
                Log::warning('Helm: '.$recovered);

                return $this->runInstall($release, $chart, $namespace, $setValues, $kubeconfigYaml, $version, $valuesArray, $setJsonValues, $wait);
            }
        }

        return $result;
    }

    private function runInstall(
        string $release,
        string $chart,
        string $namespace,
        array $setValues,
        string $kubeconfigYaml,
        ?string $version,
        array $valuesArray,
        array $setJsonValues,
        bool $wait,
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
     * The last revisions of a release (`helm history -o json`), oldest first: revision, updated,
     * status (deployed, superseded, failed, pending-install, pending-upgrade, pending-rollback,
     * uninstalling, …), chart. Null when the release does not exist or helm fails.
     *
     * @return list<array{revision: int, updated: string, status: string, chart: string}>|null
     */
    public function history(string $release, string $namespace, string $kubeconfigYaml, int $max = 10): ?array
    {
        $result = $this->run([
            'helm', 'history', $release,
            '-n', $namespace,
            '--kubeconfig', $this->writeKubeconfig($kubeconfigYaml),
            '--max', (string) $max,
            '-o', 'json',
        ]);
        $rows = $result->success ? json_decode($result->output, true) : null;
        if (! is_array($rows) || $rows === []) {
            return null;
        }

        return array_values(array_map(fn ($row) => [
            'revision' => (int) ($row['revision'] ?? 0),
            'updated' => (string) ($row['updated'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'chart' => (string) ($row['chart'] ?? ''),
        ], array_filter($rows, 'is_array')));
    }

    /**
     * A release whose last operation was interrupted: its newest revision is pending-upgrade or
     * pending-rollback (the helm process that ran it was killed — a region:reconcile cut off in
     * a `helm upgrade --wait`, a pod that stopped), and every later operation fails with "another
     * operation (install/upgrade/rollback) is in progress" while `helm list` no longer shows it.
     * Rolled back to the revision that was deployed before, so the next upgrade can run.
     *
     * Only once the pending revision is older than INTERRUPTED_AFTER_SECONDS: a younger one may
     * still belong to a helm process that runs (RunR's wait at most 10 minutes, the process 11).
     *
     * @return string|null what was done; null when nothing is pending
     *
     * @throws RuntimeException pending but too young to be interrupted, pending-install, or the
     *                          rollback failed — the operator's call (helm -n NS history RELEASE)
     */
    public function recoverInterrupted(string $release, string $namespace, string $kubeconfigYaml): ?string
    {
        $history = $this->history($release, $namespace, $kubeconfigYaml);
        $last = $history ? $history[array_key_last($history)] : null;
        if (! $last || ! str_starts_with($last['status'], 'pending-')) {
            return null;
        }

        $name = "Helm release {$namespace}/{$release}";
        $since = $last['updated'] !== '' ? " since {$last['updated']}" : '';
        try {
            $age = $last['updated'] !== '' ? now()->getTimestamp() - Carbon::parse($last['updated'])->getTimestamp() : null;
        } catch (\Throwable) {
            $age = null;
        }
        if ($age === null || $age < self::INTERRUPTED_AFTER_SECONDS) {
            throw new RuntimeException("{$name} is {$last['status']}{$since}: another helm operation may still run on it — "
                .'it is rolled back once it is '.intdiv(self::INTERRUPTED_AFTER_SECONDS, 60).' minutes old (run php artisan region:reconcile again then)');
        }

        $deployed = array_values(array_filter(
            array_slice($history, 0, -1),
            fn (array $row) => $row['status'] === 'deployed',
        ));
        $target = $deployed ? $deployed[array_key_last($deployed)]['revision'] : null;
        if ($last['status'] === 'pending-install' || $target === null) {
            throw new RuntimeException("{$name} is {$last['status']}{$since} and was never deployed before: "
                ."check it with helm -n {$namespace} history {$release} and roll it back or uninstall it by hand");
        }

        $result = $this->rollback($release, $namespace, $kubeconfigYaml, $target);
        if (! $result->success) {
            throw new RuntimeException("{$name} is {$last['status']}{$since}; rolling it back to revision {$target} failed: ".trim($result->error));
        }

        return "rolled back the interrupted {$last['status']} of {$name}{$since} to revision {$target}";
    }

    /** helm's answer to an operation on a release whose last operation has not ended (or was interrupted) */
    public static function operationInProgress(HelmResult $result): bool
    {
        return ! $result->success && str_contains($result->error, 'another operation (install/upgrade/rollback) is in progress');
    }

    /**
     * Roll a release back to an earlier revision (`helm rollback`, without --wait: the next
     * upgrade waits). Also takes a release whose last operation was interrupted (pending-upgrade).
     */
    public function rollback(string $release, string $namespace, string $kubeconfigYaml, int $revision): HelmResult
    {
        return $this->run([
            'helm', 'rollback', $release, (string) $revision,
            '-n', $namespace,
            '--kubeconfig', $this->writeKubeconfig($kubeconfigYaml),
        ]);
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
