<?php

namespace App\Services\Sandboxes\Exec;

use App\Services\Kubeconfigs\KubeconfigFile;

/**
 * kubectl exec invocation shared by the HTTP transport and the WebSocket handler: the kubeconfig
 * goes to a 0600 temp file that is deleted after the command, never into the pod.
 */
final class KubectlCommand
{
    /** kubectl's own trailer when the remote command exits non-zero */
    private const EXIT_TRAILER = '/(?:^|\n)command terminated with exit code (\d+)\r?\n?$/';

    /** @var array<string, KubeconfigFile> the written kubeconfigs by path, until removeKubeconfig() */
    private static array $files = [];

    /**
     * The kubeconfig as a file for kubectl (KubeconfigFile: an organization's region is checked
     * again and reached through its pin proxy). removeKubeconfig() removes it.
     */
    public static function writeKubeconfig(string $kubeconfig): string
    {
        $file = KubeconfigFile::write($kubeconfig, 'runr-sbx-');
        self::$files[$file->path] = $file;

        return $file->path;
    }

    public static function removeKubeconfig(?string $path): void
    {
        if ($path && isset(self::$files[$path])) {
            self::$files[$path]->close();
            unset(self::$files[$path]);
        } elseif ($path && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * $tty adds -t: the command runs on a terminal in the pod. kubectl then needs a terminal
     * as its own stdin (SandboxExecSocket hands it a pseudo-terminal).
     *
     * @param  list<string>  $argv
     * @return list<string>
     */
    public static function argv(PodTarget $target, string $kubeconfigPath, array $argv, bool $stdin, bool $tty = false): array
    {
        // the kubectl of the region's Kubernetes minor (KubectlBinary)
        $cmd = [KubectlBinary::for($target->kubernetesVersion), 'exec'];
        if ($stdin) {
            $cmd[] = '-i';
        }
        if ($tty) {
            $cmd[] = '-t';
        }

        return array_merge($cmd, [
            $target->pod,
            '-n', $target->namespace,
            '-c', $target->container,
            "--kubeconfig={$kubeconfigPath}",
            '--request-timeout=0',
            '--',
        ], $argv);
    }

    /** Remove kubectl's "command terminated with exit code N" trailer from stderr. */
    public static function stripTrailer(string $stderr): string
    {
        return (string) preg_replace(self::EXIT_TRAILER, '', $stderr);
    }

    /**
     * Whether kubectl itself failed (as opposed to the remote command exiting non-zero): its errors
     * start with "Error from server" / "error:" / "Unable to connect".
     */
    public static function isKubectlError(string $stderr, int $exitCode): bool
    {
        if ($exitCode === 0) {
            return false;
        }

        return (bool) preg_match('/^(Error from server|error: |Unable to connect to the server|The connection to the server)/m', $stderr)
            && ! preg_match(self::EXIT_TRAILER, $stderr);
    }
}
