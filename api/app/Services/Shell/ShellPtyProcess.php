<?php

namespace App\Services\Shell;

use React\EventLoop\LoopInterface;

class ShellPtyProcess
{
    private $process;

    private array $pipes = [];

    private ?string $kubeconfigPath = null;

    private bool $closed = false;

    public function __construct(
        private LoopInterface $loop,
    ) {}

    public function spawn(array $config): bool
    {
        $token = bin2hex(random_bytes(8));
        $this->kubeconfigPath = sys_get_temp_dir()."/runr-shell-{$token}";
        file_put_contents($this->kubeconfigPath, $config['kubeconfig'], LOCK_EX);
        chmod($this->kubeconfigPath, 0600);

        $args = ['kubectl', 'exec', '-it', $config['pod'], '-n', $config['namespace']];
        if (! empty($config['container'])) {
            $args[] = '-c';
            $args[] = $config['container'];
        }
        $args[] = "--kubeconfig={$this->kubeconfigPath}";
        $args[] = '--';
        $args[] = '/bin/sh';

        $cmd = implode(' ', array_map('escapeshellarg', $args));

        $env = ['TERM' => 'xterm-256color', 'PATH' => getenv('PATH')];

        $this->process = proc_open($cmd, [['pty'], ['pty'], ['pty']], $this->pipes, '/tmp', $env);

        if (! is_resource($this->process)) {
            $this->cleanup();

            return false;
        }

        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);

        return true;
    }

    public function onOutput(callable $callback): void
    {
        $this->loop->addReadStream($this->pipes[1], function ($stream) use ($callback) {
            $data = @fread($stream, 65536);
            if ($data === false || $data === '') {
                $this->close();

                return;
            }
            $callback($data);
        });
    }

    public function write(string $data): void
    {
        if (! $this->closed && isset($this->pipes[0]) && is_resource($this->pipes[0])) {
            @fwrite($this->pipes[0], $data);
        }
    }

    public function resize(int $cols, int $rows): void
    {
        if ($this->closed || ! isset($this->pipes[0])) {
            return;
        }

        // TIOCSWINSZ via stty on the PTY master
        $fd = $this->pipes[0];
        if (is_resource($fd) && function_exists('proc_get_status')) {
            $pid = proc_get_status($this->process)['pid'] ?? null;
            if ($pid) {
                @exec("stty -F /proc/{$pid}/fd/0 rows {$rows} cols {$cols} 2>/dev/null");
            }
        }
    }

    public function getKubeconfigPath(): ?string
    {
        return $this->kubeconfigPath;
    }

    public function isRunning(): bool
    {
        if ($this->closed || ! is_resource($this->process)) {
            return false;
        }
        $status = proc_get_status($this->process);

        return $status['running'] ?? false;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                $this->loop->removeReadStream($pipe);
                @fclose($pipe);
            }
        }

        if (is_resource($this->process)) {
            @proc_terminate($this->process, 9);
            @proc_close($this->process);
        }

        $this->cleanup();
    }

    private function cleanup(): void
    {
        if ($this->kubeconfigPath && file_exists($this->kubeconfigPath)) {
            @unlink($this->kubeconfigPath);
        }
    }
}
