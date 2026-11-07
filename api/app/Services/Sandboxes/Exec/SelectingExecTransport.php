<?php

namespace App\Services\Sandboxes\Exec;

/**
 * The ExecTransport of the API: the native WebSocket client (NativeExecTransport) by default,
 * kubectl (KubectlExecTransport) where the native one cannot run a command — a kubeconfig it does
 * not handle (ExecKubeconfig: an exec plugin, an auth provider, a token or certificate in a file,
 * basic auth, impersonation, a CA file, a proxy-url — or a proxy of the environment that kubectl
 * would use), an API server that refuses the upgrade (no v5.channel.k8s.io for a command with
 * stdin, an error) — and for everything when sandbox.exec.client is "kubectl" (SANDBOX_EXEC_CLIENT,
 * the escape hatch). A command reaches kubectl only when it has not started in the pod. Each
 * reason is logged once per process: "sandbox exec: kubectl instead of the native client: <why>".
 *
 * An API server that refused an upgrade is remembered for FALLBACK_SECONDS per process, so the
 * commands after it go to kubectl directly instead of trying the upgrade each time.
 */
final class SelectingExecTransport implements ExecTransport
{
    public const FALLBACK_SECONDS = 600;

    /** @var array<string, float> API server → until when commands go to kubectl */
    private static array $refused = [];

    /** @var array<string, true> reasons already logged */
    private static array $logged = [];

    public function __construct(
        private readonly NativeExecTransport $native,
        private readonly KubectlExecTransport $kubectl,
    ) {}

    public function exec(
        PodTarget $target,
        array $argv,
        ?string $stdin = null,
        int $timeoutSeconds = 60,
        ?callable $onOutput = null,
        int $maxOutputBytes = 10485760,
        ?callable $onTick = null,
        ?string $ackId = null,
    ): ExecResult {
        if (self::nativeFor($target) !== null) {
            try {
                return $this->native->exec($target, $argv, $stdin, $timeoutSeconds, $onOutput, $maxOutputBytes, $onTick, $ackId);
            } catch (NativeExecUnsupported $e) {
                self::fallBack($target, $e);
            }
        }

        return $this->kubectl->exec($target, $argv, $stdin, $timeoutSeconds, $onOutput, $maxOutputBytes, $onTick, $ackId);
    }

    /** The configured client: "native" (default) or "kubectl". */
    public static function client(): string
    {
        return config('sandbox.exec.client', 'native') === 'kubectl' ? 'kubectl' : 'native';
    }

    /**
     * The parsed kubeconfig when this target's commands go to the native client, null when
     * they go to kubectl (configured, an unsupported kubeconfig, a server that refused lately).
     */
    public static function nativeFor(PodTarget $target): ?ExecKubeconfig
    {
        if (self::client() === 'kubectl') {
            return null;
        }
        try {
            $config = ExecKubeconfig::parse($target->kubeconfig);
        } catch (NativeExecUnsupported $e) {
            self::log($e->getMessage());

            return null;
        }
        if (($proxy = $config->environmentProxy()) !== null) {
            self::log("{$proxy} sends the API server through a proxy, which only kubectl follows");

            return null;
        }
        $until = self::$refused[$config->serverKey()] ?? null;
        if ($until !== null && $until > microtime(true)) {
            return null;
        }
        unset(self::$refused[$config->serverKey()]);

        return $config;
    }

    /**
     * The native client could not run this command (it has not started): the server is remembered
     * when it cannot take the native client at all, the reason logged either way.
     */
    public static function fallBack(PodTarget $target, NativeExecUnsupported $e): void
    {
        if ($e->remember) {
            self::refuse($target, $e->getMessage());
        } else {
            self::log($e->getMessage());
        }
    }

    /** The native client could not run a command on this target's API server: kubectl for a while. */
    public static function refuse(PodTarget $target, string $reason): void
    {
        try {
            $key = ExecKubeconfig::parse($target->kubeconfig)->serverKey();
        } catch (NativeExecUnsupported) {
            return;
        }
        if (count(self::$refused) >= 64) {
            self::$refused = [];
        }
        self::$refused[$key] = microtime(true) + self::FALLBACK_SECONDS;
        self::log($reason);
    }

    /** Forgets refused servers and logged reasons (tests). */
    public static function reset(): void
    {
        self::$refused = [];
        self::$logged = [];
    }

    private static function log(string $reason): void
    {
        if (isset(self::$logged[$reason]) || count(self::$logged) >= 64) {
            return;
        }
        self::$logged[$reason] = true;
        logger()->info('sandbox exec: kubectl instead of the native client: '.$reason, ['reason' => $reason]);
    }
}
