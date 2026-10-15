<?php

namespace App\Services\GitDeploy;

use Symfony\Component\Process\Process;

/**
 * Runs the git binary (config git-deploy.git_binary) without a shell. Every call gets
 * safe.directory=* through GIT_CONFIG_* (the bare repositories live on a volume that may be owned
 * by another uid) and a HOME of its own, so no user or system config changes behaviour.
 *
 * A command runs in the repository it is given and in no other: the repository and config
 * variables of the PHP process itself (set when it runs inside a git hook or a shell of another
 * repository) do not reach git, and with a working directory but no GIT_DIR git looks for the
 * repository in that directory only, never in a parent directory.
 */
class GitRunner
{
    /** Variables of the PHP process's environment that would point git elsewhere (unset per call) */
    private const INHERITED = [
        'GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE', 'GIT_OBJECT_DIRECTORY', 'GIT_ALTERNATE_OBJECT_DIRECTORIES',
        'GIT_COMMON_DIR', 'GIT_NAMESPACE', 'GIT_CEILING_DIRECTORIES', 'GIT_DISCOVERY_ACROSS_FILESYSTEM',
        'GIT_CONFIG', 'GIT_CONFIG_GLOBAL', 'GIT_CONFIG_SYSTEM', 'GIT_CONFIG_PARAMETERS', 'XDG_CONFIG_HOME',
    ];

    /**
     * @param  list<string>  $args  arguments after "git"
     * @param  array<string, string>  $env  extra environment
     * @param  array<string, string>  $config  extra -c style config (key => value)
     *
     * @throws GitCommandException the command exited non-zero
     */
    public function run(array $args, ?string $cwd = null, array $env = [], ?string $input = null, int $timeout = 120, array $config = []): string
    {
        [$code, $out, $err] = $this->attempt($args, $cwd, $env, $input, $timeout, $config);

        if ($code !== 0) {
            throw new GitCommandException($args, $code, $err !== '' ? $err : $out);
        }

        return $out;
    }

    /**
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    public function attempt(array $args, ?string $cwd = null, array $env = [], ?string $input = null, int $timeout = 120, array $config = []): array
    {
        $environment = $this->environment($env, $config);
        $process = new Process([$this->binary(), ...$args], $cwd, $environment + $this->confinement($cwd, $environment), $input, $timeout);
        $process->run();

        return [(int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
    }

    public function binary(): string
    {
        return (string) config('git-deploy.git_binary', 'git');
    }

    /**
     * Environment of a git process: the given variables plus the config entries as
     * GIT_CONFIG_COUNT / GIT_CONFIG_KEY_n / GIT_CONFIG_VALUE_n (command-line scope).
     *
     * @param  array<string, string>  $env
     * @param  array<string, string>  $config
     * @return array<string, string>
     */
    public function environment(array $env = [], array $config = []): array
    {
        $config = ['safe.directory' => '*'] + $config;

        $vars = [
            'HOME' => $this->home(),
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_COUNT' => (string) count($config),
            'LC_ALL' => 'C',
        ];

        $i = 0;
        foreach ($config as $key => $value) {
            $vars["GIT_CONFIG_KEY_{$i}"] = (string) $key;
            $vars["GIT_CONFIG_VALUE_{$i}"] = (string) $value;
            $i++;
        }

        if (($path = getenv('PATH')) !== false) {
            $vars['PATH'] = $path;
        }

        return $env + $vars;
    }

    /**
     * The inherited variables unset (false) unless the call sets them, and GIT_CEILING_DIRECTORIES
     * at the parent of $cwd when the call names no GIT_DIR.
     *
     * @param  array<string, string>  $environment
     * @return array<string, string|false>
     */
    private function confinement(?string $cwd, array $environment): array
    {
        $vars = array_fill_keys(self::INHERITED, false);

        if ($cwd !== null && ! isset($environment['GIT_DIR'])) {
            $vars['GIT_CEILING_DIRECTORIES'] = dirname(realpath($cwd) ?: $cwd);
        }

        return $vars;
    }

    private function home(): string
    {
        $home = sys_get_temp_dir().DIRECTORY_SEPARATOR.'runr-git-home';
        if (! is_dir($home)) {
            @mkdir($home, 0700, true);
        }

        return $home;
    }
}
