<?php

namespace App\Services\Sandboxes\Exec;

/**
 * kubectl exec invocation shared by the HTTP transport and the WebSocket handler: the kubeconfig
 * goes to a 0600 temp file that is deleted after the command, never into the pod.
 */
final class KubectlCommand
{
    /** kubectl's own trailer when the remote command exits non-zero */
    private const EXIT_TRAILER = '/(?:^|\n)command terminated with exit code (\d+)\r?\n?$/';

    public static function writeKubeconfig(string $kubeconfig): string
    {
        $path = sys_get_temp_dir().'/runr-sbx-'.bin2hex(random_bytes(8));
        file_put_contents($path, $kubeconfig, LOCK_EX);
        @chmod($path, 0600);

        return $path;
    }

    public static function removeKubeconfig(?string $path): void
    {
        if ($path && is_file($path)) {
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
        $cmd = [(string) config('sandbox.exec.kubectl', 'kubectl'), 'exec'];
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
