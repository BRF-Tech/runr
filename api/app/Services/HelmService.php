<?php

namespace App\Services;

use App\Support\GitHubReleaseAsset;
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
     * minutes. A longer process (CHART_UPGRADE_SECONDS) changes nothing: --atomic's wait and its
     * rollback are two revisions, each pending for --timeout at most.
     */
    public const INTERRUPTED_AFTER_SECONDS = 780;

    /** What a helm process may take: above helm's own --timeout 10m */
    private const PROCESS_TIMEOUT = 660;

    /**
     * What upgradeToChartVersion() may take: --atomic's wait for the release's workloads and, when
     * they fail, its rollback, each up to --timeout 10m. helm/templates/region-reconcile-job.yaml
     * counts it among the waits of region:reconcile.
     */
    public const CHART_UPGRADE_SECONDS = 1260;

    /** Tries of a Helm call that keeps failing on transient API server errors (retryTransient) */
    public const TRANSIENT_TRIES = 5;

    /** Seconds before the second try; doubled before each next one, at most TRANSIENT_MAX_WAIT */
    public const TRANSIENT_FIRST_WAIT = 2;

    public const TRANSIENT_MAX_WAIT = 30;

    /** Tries of a Helm call whose chart download keeps failing on the way (withChartDownload) */
    public const DOWNLOAD_TRIES = GitHubReleaseAsset::TRIES;

    private ?string $tmpKubeconfig = null;

    /** The time limit of the helm process run() starts */
    protected int $processTimeout = self::PROCESS_TIMEOUT;

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
     * $attempt (a Helm call that downloads $chart, a repository's "repo/name", at $version) tried
     * again while the chart's download fails on the way (HelmResult::chartDownloadFailure: a 5xx or
     * 429 of the host, a timeout, as github.com answers a release download now and then),
     * DOWNLOAD_TRIES tries in all, 2, 4, 8 s apart. A chart of a GitHub release that still does not
     * come is fetched once through the GitHub API's asset endpoint (GitHubReleaseAsset::viaApi),
     * checked against the digest the repository's index gives that version, and $attempt runs once
     * more with that file as its chart. Any other result is returned at once, as is the last try's.
     *
     * @param  callable(string): HelmResult  $attempt  gets the chart to install: $chart, or the file
     */
    public function withChartDownload(string $chart, ?string $version, callable $attempt): HelmResult
    {
        for ($try = 1; ; $try++) {
            $result = $attempt($chart);
            $url = $result->chartDownloadFailure();
            if ($url === null) {
                return $result;
            }
            if ($try >= self::DOWNLOAD_TRIES) {
                break;
            }
            $wait = min(self::TRANSIENT_MAX_WAIT, self::TRANSIENT_FIRST_WAIT * 2 ** ($try - 1));
            Log::warning('Helm: the chart download failed on the way, trying again', [
                'chart' => $chart,
                'url' => $url,
                'try' => $try + 1,
                'of' => self::DOWNLOAD_TRIES,
                'wait_s' => $wait,
                'error' => $result->errorLine(),
            ]);
            Sleep::for($wait)->seconds();
        }

        $file = $this->chartViaGitHubApi($url, $chart, $version);
        if ($file === null) {
            return $result;
        }
        try {
            return $attempt($file);
        } finally {
            @unlink($file);
            @rmdir(dirname($file));
        }
    }

    /**
     * The chart of a GitHub release download URL through the API, as a file of its name; null for
     * another URL, a chart that is no repository's "repo/name" at a version, a version the cached
     * index gives no digest, or a download that does not come with it.
     */
    private function chartViaGitHubApi(string $url, string $chart, ?string $version): ?string
    {
        $parts = GitHubReleaseAsset::parse($url);
        if ($parts === null || $version === null || str_contains($chart, '://') || substr_count($chart, '/') !== 1) {
            return null;
        }
        [$repo, $name] = explode('/', $chart, 2);
        $digest = $this->chartDigest($repo, $name, $version);
        if ($digest === null) {
            Log::warning('Helm: no digest of the chart in its repository index, so no download through the GitHub API', ['chart' => $chart, 'version' => $version]);

            return null;
        }
        $body = GitHubReleaseAsset::viaApi($url, $digest);
        if ($body === null) {
            return null;
        }
        $dir = sys_get_temp_dir().'/runr-helm-chart-'.bin2hex(random_bytes(6));
        if (! @mkdir($dir, 0700)) {
            return null;
        }
        $file = $dir.'/'.basename($parts['file']);
        file_put_contents($file, $body);

        return $file;
    }

    /** The sha256 the repository's cached index gives a chart version (helm repo add / update) */
    protected function chartDigest(string $repo, string $name, string $version): ?string
    {
        foreach ($this->getRepoChartEntries($repo, $name) as $entry) {
            if (ltrim((string) ($entry['version'] ?? ''), 'v') === ltrim($version, 'v')) {
                $digest = strtolower((string) ($entry['digest'] ?? ''));

                return preg_match('/^[0-9a-f]{64}$/', $digest) === 1 ? $digest : null;
            }
        }

        return null;
    }

    /**
     * Install or upgrade a Helm release (upgrade --install). A chart download that fails on the way
     * is tried again (withChartDownload).
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
        $run = fn (string $c) => $this->runInstall($release, $c, $namespace, $setValues, $kubeconfigYaml, $version, $valuesArray, $setJsonValues, $wait);
        $result = $this->withChartDownload($chart, $version, $run);

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

                return $this->withChartDownload($chart, $version, $run);
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

        foreach ($setValues as $key => $value) {
            $args[] = '--set';
            $args[] = "{$key}={$value}";
        }

        foreach ($setJsonValues as $key => $value) {
            $args[] = '--set-json';
            $args[] = "{$key}={$value}";
        }

        return $this->runWithValues($args, $valuesArray);
    }

    /** $args with $valuesArray as a values file, which is gone again afterwards. */
    private function runWithValues(array $args, array $valuesArray): HelmResult
    {
        $tmpValues = null;
        if ($valuesArray) {
            $tmpValues = tempnam(sys_get_temp_dir(), 'runr-helm-vals-');
            file_put_contents($tmpValues, self::valuesYaml($valuesArray));
            $args[] = '--values';
            $args[] = $tmpValues;
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
        $kubeconfig = $this->writeKubeconfig($kubeconfigYaml);

        return $this->withChartDownload($chart, $version, function (string $c) use ($release, $namespace, $kubeconfig, $version, $wait, $setJsonValues) {
            $args = [
                'helm', 'upgrade', $release, $c,
                '-n', $namespace,
                '--kubeconfig', $kubeconfig,
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
        });
    }

    /**
     * Move a release to another chart version with the values it was installed with
     * (--reset-then-reuse-values: the new chart's defaults, then the release's own values, then
     * $valuesArray). Not --reuse-values: that keeps the old chart's defaults too, so a chart whose
     * values.yaml names its image would keep running the old one. Returns once the workloads are
     * ready; a failed upgrade rolls back (--atomic), so the helm process gets
     * CHART_UPGRADE_SECONDS, as in upgradeToChartVersion().
     *
     * @param  array<string, mixed>  $valuesArray
     */
    public function upgradeToChart(
        string $release,
        string $chart,
        string $namespace,
        string $kubeconfigYaml,
        string $version,
        array $valuesArray = [],
    ): HelmResult {
        $this->processTimeout = self::CHART_UPGRADE_SECONDS;
        $kubeconfig = $this->writeKubeconfig($kubeconfigYaml);
        try {
            return $this->withChartDownload($chart, $version, fn (string $c) => $this->runWithValues([
                'helm', 'upgrade', $release, $c,
                '-n', $namespace,
                '--kubeconfig', $kubeconfig,
                '--reset-then-reuse-values',
                '--version', $version,
                '--atomic',
                '--timeout', '10m',
            ], $valuesArray));
        } finally {
            $this->processTimeout = self::PROCESS_TIMEOUT;
        }
    }

    /**
     * The values a release was given: `helm get values -o json`, or null when helm fails. Kept as
     * JSON, where {} and [] stay apart (a chart's schema may care, PHP arrays cannot tell).
     */
    public function releaseValuesJson(string $release, string $namespace, string $kubeconfigYaml): ?string
    {
        $result = $this->run([
            'helm', 'get', 'values', $release,
            '-n', $namespace,
            '--kubeconfig', $this->writeKubeconfig($kubeconfigYaml),
            '-o', 'json',
        ]);

        return $result->success ? $result->output : null;
    }

    /**
     * Upgrade a release to another chart version with exactly $valuesJson over the new chart's
     * defaults: unlike upgradeReusingValues(), nothing of the release's values that the new chart
     * may refuse comes along. --atomic waits for the release's workloads and rolls the release back
     * when they do not become ready: it never stays on a chart whose pods do not run, and the next
     * reconcile sees the version it started from. The wait and the rollback each get --timeout, so
     * the helm process gets CHART_UPGRADE_SECONDS: killed in its rollback it would leave the
     * release pending-rollback.
     */
    public function upgradeToChartVersion(
        string $release,
        string $chart,
        string $namespace,
        string $kubeconfigYaml,
        string $version,
        string $valuesJson,
    ): HelmResult {
        $values = tempnam(sys_get_temp_dir(), 'runr-helm-vals-');
        file_put_contents($values, $valuesJson);

        $this->processTimeout = self::CHART_UPGRADE_SECONDS;
        $kubeconfig = $this->writeKubeconfig($kubeconfigYaml);
        try {
            return $this->withChartDownload($chart, $version, fn (string $c) => $this->run([
                'helm', 'upgrade', $release, $c,
                '-n', $namespace,
                '--kubeconfig', $kubeconfig,
                '--version', $version,
                '--values', $values,
                '--atomic',
                '--timeout', '10m',
            ]));
        } finally {
            $this->processTimeout = self::PROCESS_TIMEOUT;
            @unlink($values);
        }
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
        return $this->listedRelease($release, $namespace, $kubeconfigYaml, $chartName)['version'] ?? null;
    }

    /**
     * deployedChartVersion() with the status of the newest revision: `failed` says that revision's
     * chart never came up, whatever the version reads.
     *
     * @return array{version: string, status: string}|null
     */
    public function listedRelease(string $release, string $namespace, string $kubeconfigYaml, string $chartName): ?array
    {
        $result = $this->run([
            'helm', 'list',
            '-n', $namespace,
            '--kubeconfig', $this->writeKubeconfig($kubeconfigYaml),
            '--filter', '^'.$release.'$',
            '-o', 'json',
        ]);

        $version = $result->success ? self::chartVersionFromList($result->output, $release, $chartName) : null;
        if ($version === null) {
            return null;
        }

        $row = collect((array) json_decode($result->output, true))->firstWhere('name', $release);

        return ['version' => $version, 'status' => (string) ($row['status'] ?? '')];
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
                .'it is rolled back once it is '.intdiv(self::INTERRUPTED_AFTER_SECONDS, 60).' minutes old (run it again then)');
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
        $process->setTimeout($this->processTimeout);
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
