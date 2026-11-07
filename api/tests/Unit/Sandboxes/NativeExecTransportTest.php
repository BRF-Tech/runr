<?php

namespace Tests\Unit\Sandboxes;

use App\Services\Sandboxes\Exec\ExecKubeconfig;
use App\Services\Sandboxes\Exec\ExecProtocol;
use App\Services\Sandboxes\Exec\ExecTransportException;
use App\Services\Sandboxes\Exec\KubectlExecTransport;
use App\Services\Sandboxes\Exec\NativeExecTransport;
use App\Services\Sandboxes\Exec\NativeExecUnsupported;
use App\Services\Sandboxes\Exec\PodTarget;
use App\Services\Sandboxes\Exec\SelectingExecTransport;
use App\Services\Sandboxes\Exec\WebSocketFrames;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeK8sExecServer;
use Tests\TestCase;

/**
 * The native exec client against a stand-in API server (tests/Support/fake-k8s-exec-server.php):
 * the upgrade and its protocols, the channels and the status message, stdin and its end, output
 * caps, stop and deadline, TLS and credentials, and when commands go to kubectl instead.
 */
class NativeExecTransportTest extends TestCase
{
    /** @var list<FakeK8sExecServer> */
    private array $servers = [];

    private ?string $kubectl = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('the fake API server needs pcntl');
        }
        SelectingExecTransport::reset();
        ExecKubeconfig::flush();
        // kubectl stand-in: records that it ran, then becomes the command after "--"
        $this->kubectl = tempnam(sys_get_temp_dir(), 'fake-kubectl-');
        file_put_contents($this->kubectl, "#!/bin/sh\necho ran >> {$this->kubectl}.log\nwhile [ \"\$#\" -gt 0 ] && [ \"\$1\" != \"--\" ]; do shift; done\nshift\nexec \"\$@\"\n");
        chmod($this->kubectl, 0755);
        config(['sandbox.exec.kubectl' => $this->kubectl, 'sandbox.exec.client' => 'native']);
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        if ($this->kubectl !== null) {
            @unlink($this->kubectl);
            @unlink($this->kubectl.'.log');
        }
        ExecKubeconfig::flush();
        SelectingExecTransport::reset();
        parent::tearDown();
    }

    private function server(array $options = []): FakeK8sExecServer
    {
        return $this->servers[] = FakeK8sExecServer::start($options);
    }

    private function target(string $kubeconfig): PodTarget
    {
        return new PodTarget($kubeconfig, 'runr-sbx-default', 'sbx-1');
    }

    private function native(int $grace = 15): NativeExecTransport
    {
        return new NativeExecTransport(2.0, 5.0, $grace);
    }

    private function selecting(): SelectingExecTransport
    {
        return new SelectingExecTransport($this->native(), new KubectlExecTransport);
    }

    private function kubectlRuns(): int
    {
        return is_file($this->kubectl.'.log') ? count(file($this->kubectl.'.log')) : 0;
    }

    // ── The stream ─────────────────────────────────────────────────────────────────────────

    public function test_stdout_stderr_and_the_exit_code_from_the_status_message(): void
    {
        $server = $this->server();
        $chunks = [];
        $r = $this->native()->exec($this->target($server->kubeconfig()), ['/bin/sh', '-c', 'echo out; echo err >&2; exit 3'], null, 30,
            function (string $type, string $data) use (&$chunks) {
                $chunks[] = $type;
            });

        $this->assertSame([3, "out\n", "err\n", false, false], [$r->exitCode, $r->stdout, $r->stderr, $r->timedOut, $r->truncated]);
        $this->assertContains('stdout', $chunks);
        $this->assertContains('stderr', $chunks);

        $request = $server->events('request')[0];
        $this->assertSame('/api/v1/namespaces/runr-sbx-default/pods/sbx-1/exec', $request['path']);
        $this->assertSame(['/bin/sh', '-c', 'echo out; echo err >&2; exit 3'], $request['argv']);
        $this->assertFalse($request['stdin']);
        // Without stdin v4 is offered as well; the close is answered
        $this->assertSame([ExecProtocol::V5, ExecProtocol::V4], $request['protocols']);
        $this->assertTrue($server->waitEnds(1)[0]['close_answered']);

        $ok = $this->native()->exec($this->target($server->kubeconfig()), ['true']);
        $this->assertSame([0, '', ''], [$ok->exitCode, $ok->stdout, $ok->stderr]);
    }

    public function test_stdin_is_sent_in_chunks_and_closed_binary_safe(): void
    {
        $server = $this->server(['protocols' => [ExecProtocol::V5]]);
        $bytes = random_bytes(300_000);

        $r = $this->native()->exec($this->target($server->kubeconfig()), ['cat'], $bytes, 30);

        $this->assertSame(0, $r->exitCode);
        $this->assertSame(strlen($bytes), strlen($r->stdout));
        $this->assertTrue($bytes === $r->stdout);
        $this->assertSame([ExecProtocol::V5], $server->events('request')[0]['protocols']);
        $this->assertTrue($server->events('request')[0]['stdin']);

        // Empty stdin still ends: cat exits
        $this->assertSame(0, $this->native()->exec($this->target($server->kubeconfig()), ['cat'], '', 10)->exitCode);
    }

    public function test_fragmented_messages_empty_messages_and_a_ping(): void
    {
        $server = $this->server(['fragment' => true, 'empty' => true, 'ping' => true]);

        $r = $this->native()->exec($this->target($server->kubeconfig()), ['/bin/sh', '-c', 'printf "%s" "$(seq 1 2000)"; echo e >&2; exit 5']);

        $this->assertSame(5, $r->exitCode);
        $this->assertSame(implode("\n", range(1, 2000)), $r->stdout);
        $this->assertSame("e\n", $r->stderr);
        $this->assertSame('hello', $server->waitEnds(1)[0]['pong']);
    }

    public function test_output_is_capped_per_stream_and_marked_truncated(): void
    {
        $server = $this->server();

        $r = $this->native()->exec($this->target($server->kubeconfig()), ['/bin/sh', '-c', 'head -c 3000000 /dev/zero; head -c 10 /dev/zero >&2'], null, 30, null, 1_000_000);

        $this->assertSame(0, $r->exitCode);
        $this->assertSame(1_000_000, strlen($r->stdout));
        $this->assertSame(10, strlen($r->stderr));
        $this->assertTrue($r->truncated);
    }

    public function test_a_stop_ends_the_command_and_the_connection(): void
    {
        $server = $this->server();
        $ticks = 0;
        $seen = '';

        $r = $this->native()->exec($this->target($server->kubeconfig()), ['/bin/sh', '-c', 'echo started; exec sleep 30'], null, 60,
            function (string $type, string $data) use (&$seen) {
                $seen .= $data;
            },
            10485760,
            function () use (&$ticks, &$seen) {
                $ticks++;

                return ! str_contains($seen, 'started');
            });

        $this->assertSame(130, $r->exitCode);
        $this->assertFalse($r->timedOut);
        $this->assertGreaterThan(0, $ticks);
        $this->assertLessThan(10_000, $r->durationMs);
        $this->assertTrue($server->waitEnds(1)[0]['client_gone'], 'the server saw the client go and killed the command');
    }

    public function test_the_local_deadline_and_the_in_sandbox_timeout(): void
    {
        $server = $this->server();

        // Nothing comes back: the local deadline (timeout + grace) ends it
        $r = $this->native(1)->exec($this->target($server->kubeconfig()), ['sleep', '30'], null, 1);
        $this->assertSame([137, true], [$r->exitCode, $r->timedOut]);
        $this->assertLessThan(5_000, $r->durationMs);

        // The in-sandbox timeout (timeout -s KILL) answers 137 at the limit: timed out
        $r = $this->native()->exec($this->target($server->kubeconfig()), ['/bin/sh', '-c', 'sleep 1.2; exit 137'], null, 1);
        $this->assertSame([137, true], [$r->exitCode, $r->timedOut]);
    }

    public function test_cluster_errors_and_a_stream_without_status(): void
    {
        $failure = $this->server(['end' => 'failure']);
        try {
            $this->native()->exec($this->target($failure->kubeconfig()), ['true']);
            $this->fail('an InternalError status is the cluster\'s error');
        } catch (ExecTransportException $e) {
            $this->assertSame('error: Internal error occurred: error executing command in container: container not running', $e->getMessage());
        }

        $none = $this->server(['end' => 'none']);
        $this->expectException(ExecTransportException::class);
        $this->native()->exec($this->target($none->kubeconfig()), ['true']);
    }

    // ── TLS and credentials ────────────────────────────────────────────────────────────────

    public function test_tls_with_the_kubeconfig_ca_a_client_certificate_and_a_token(): void
    {
        $server = $this->server(['tls' => true, 'client_certs' => true, 'token' => 's3cret']);
        $kubeconfig = $server->kubeconfig(
            ['certificate-authority-data' => $server->tls['ca_data']],
            ['token' => 's3cret', 'client-certificate-data' => $server->tls['client_cert_data'], 'client-key-data' => $server->tls['client_key_data']],
        );

        $r = $this->native()->exec($this->target($kubeconfig), ['/bin/sh', '-c', 'echo tls']);

        $this->assertSame([0, "tls\n"], [$r->exitCode, $r->stdout]);
        $request = $server->events('request')[0];
        $this->assertSame('runr-test', $request['client_cn']);
        $this->assertSame('Bearer s3cret', $request['authorization']);
    }

    /**
     * A ServiceAccount's bearer token alone — a region kubeconfig made with `kubectl create token`,
     * and the shape of RunR's in-cluster token (server, CA data, token): the native client
     * sends it, no client certificate, no kubectl.
     */
    public function test_a_service_account_bearer_token_alone_runs_natively(): void
    {
        $server = $this->server(['tls' => true, 'token' => 'eyJhbGciOiJSUzI1NiJ9.sa-token']);
        $kubeconfig = $server->kubeconfig(['certificate-authority-data' => $server->tls['ca_data']], ['token' => 'eyJhbGciOiJSUzI1NiJ9.sa-token']);

        $this->assertNotNull(SelectingExecTransport::nativeFor($this->target($kubeconfig)));
        $r = $this->selecting()->exec($this->target($kubeconfig), ['/bin/sh', '-c', 'echo bearer']);

        $this->assertSame([0, "bearer\n"], [$r->exitCode, $r->stdout]);
        $request = $server->events('request')[0];
        $this->assertSame('Bearer eyJhbGciOiJSUzI1NiJ9.sa-token', $request['authorization']);
        $this->assertNull($request['client_cn']);
        $this->assertSame(0, $this->kubectlRuns());

        // A refused token (401) is this command's: kubectl reports it, the server is not remembered
        $wrong = $server->kubeconfig(['certificate-authority-data' => $server->tls['ca_data']], ['token' => 'expired']);
        $this->selecting()->exec($this->target($wrong), ['true']);
        $this->assertSame(1, $this->kubectlRuns());
        $this->assertNotNull(SelectingExecTransport::nativeFor($this->target($kubeconfig)), 'a refused credential is not remembered for the server');
    }

    public function test_a_client_certificate_alone_runs_natively(): void
    {
        $server = $this->server(['tls' => true, 'client_certs' => true]);
        $kubeconfig = $server->kubeconfig(
            ['certificate-authority-data' => $server->tls['ca_data']],
            ['client-certificate-data' => $server->tls['client_cert_data'], 'client-key-data' => $server->tls['client_key_data']],
        );

        $r = $this->selecting()->exec($this->target($kubeconfig), ['/bin/sh', '-c', 'echo cert']);

        $this->assertSame([0, "cert\n"], [$r->exitCode, $r->stdout]);
        $request = $server->events('request')[0];
        $this->assertSame('runr-test', $request['client_cn']);
        $this->assertNull($request['authorization']);
        $this->assertSame(0, $this->kubectlRuns());
    }

    public function test_a_server_certificate_the_ca_does_not_sign_is_refused_unless_insecure(): void
    {
        $server = $this->server(['tls' => true]);
        $dir = sys_get_temp_dir().'/other-ca-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $other = FakeK8sExecServer::certificates($dir);
        exec('rm -rf '.escapeshellarg($dir));

        try {
            $this->native()->exec($this->target($server->kubeconfig(['certificate-authority-data' => $other['ca_data']])), ['true']);
            $this->fail('a certificate of another CA must not be accepted');
        } catch (NativeExecUnsupported $e) {
            $this->assertFalse($e->remember);
        }

        $r = $this->native()->exec($this->target($server->kubeconfig(['insecure-skip-tls-verify' => true])), ['true']);
        $this->assertSame(0, $r->exitCode);

        // tls-server-name: the name to verify the certificate against (here its DNS name)
        $r = $this->native()->exec($this->target($server->kubeconfig(['certificate-authority-data' => $server->tls['ca_data'], 'tls-server-name' => 'localhost'])), ['true']);
        $this->assertSame(0, $r->exitCode);
    }

    public function test_the_server_path_prefix_is_kept(): void
    {
        $server = $this->server();

        $this->native()->exec($this->target($server->kubeconfig([], [], '/k8s/clusters/c-1')), ['true']);

        $this->assertSame('/k8s/clusters/c-1/api/v1/namespaces/runr-sbx-default/pods/sbx-1/exec', $server->events('request')[0]['path']);
    }

    // ── kubectl instead ────────────────────────────────────────────────────────────────────

    public function test_kubeconfigs_the_native_client_does_not_handle_go_to_kubectl(): void
    {
        $server = $this->server();
        foreach ([
            ['exec' => ['command' => 'aws', 'apiVersion' => 'client.authentication.k8s.io/v1']],
            ['auth-provider' => ['name' => 'oidc']],
            ['tokenFile' => '/var/run/token'],
            ['client-certificate' => '/etc/cert.pem', 'client-key' => '/etc/key.pem'],
            ['username' => 'a', 'password' => 'b'],
        ] as $user) {
            $r = $this->selecting()->exec($this->target($server->kubeconfig([], $user)), ['/bin/sh', '-c', 'echo via-kubectl']);
            $this->assertSame("via-kubectl\n", $r->stdout);
        }
        $this->selecting()->exec($this->target($server->kubeconfig(['proxy-url' => 'http://proxy:3128'])), ['true']);
        $this->selecting()->exec($this->target("apiVersion: v1\nkind: Config\n"), ['true']);

        $this->assertSame(7, $this->kubectlRuns());
        $this->assertSame([], $server->events('request'));
    }

    /**
     * Every credential form of a kubeconfig the native client does not handle: the command goes
     * to kubectl — never a failure — and the reason is logged once, however many commands follow.
     */
    public function test_each_form_kubectl_handles_goes_to_kubectl_with_one_log_line(): void
    {
        $server = $this->server();
        $forms = [
            // [cluster fields, user fields, what the log line says]
            'exec plugin' => [[], ['exec' => ['command' => 'aws', 'args' => ['eks', 'get-token'], 'apiVersion' => 'client.authentication.k8s.io/v1']], 'an exec credential plugin (exec)'],
            'auth provider' => [[], ['auth-provider' => ['name' => 'oidc', 'config' => ['idp-issuer-url' => 'https://idp.example']]], 'an auth provider (auth-provider)'],
            'token file' => [[], ['tokenFile' => '/var/run/secrets/kubernetes.io/serviceaccount/token'], 'a token file (tokenFile)'],
            'token and token file' => [[], ['token' => 'inline', 'tokenFile' => '/var/run/token'], 'a token file (tokenFile)'],
            'client certificate file' => [[], ['client-certificate' => '/etc/runr/cert.pem', 'client-key' => '/etc/runr/key.pem'], 'a client certificate file (client-certificate)'],
            'client key file' => [[], ['client-certificate-data' => 'Y2VydA==', 'client-key' => '/etc/runr/key.pem'], 'a client key file (client-key)'],
            'basic auth' => [[], ['username' => 'admin', 'password' => 'secret'], 'basic auth (username)'],
            'password only' => [[], ['password' => 'secret'], 'basic auth (password)'],
            'impersonation' => [[], ['token' => 't', 'as' => 'system:admin'], 'impersonation (as)'],
            'impersonated groups' => [[], ['token' => 't', 'as-groups' => ['system:masters']], 'impersonation (as-groups)'],
            'CA file' => [['certificate-authority' => '/etc/runr/ca.pem'], [], 'a CA file (certificate-authority)'],
            'proxy-url' => [['proxy-url' => 'http://proxy.internal:3128'], [], 'a proxy (proxy-url)'],
        ];

        Log::spy();
        $logged = [];
        foreach ($forms as $name => [$cluster, $user, $what]) {
            SelectingExecTransport::reset();
            ExecKubeconfig::flush();
            $before = $this->kubectlRuns();
            $target = $this->target($server->kubeconfig($cluster, $user));

            $this->assertNull(SelectingExecTransport::nativeFor($target), $name);
            foreach (['first', 'second'] as $run) {
                $r = $this->selecting()->exec($target, ['/bin/sh', '-c', "echo {$run}"]);
                $this->assertSame([0, "{$run}\n"], [$r->exitCode, $r->stdout], "{$name}: kubectl runs the command");
            }

            $this->assertSame($before + 2, $this->kubectlRuns(), $name);
            $part = $cluster !== [] ? 'cluster' : 'user';
            $message = "sandbox exec: kubectl instead of the native client: the kubeconfig {$part} uses {$what}, which only kubectl handles";
            // once per form (two forms with the same reason: once each, the memory was reset between them)
            $logged[$message] = ($logged[$message] ?? 0) + 1;
            Log::shouldHaveReceived('info')->with($message, \Mockery::any())->times($logged[$message]);
        }
        $this->assertSame([], $server->events('request'), 'the native client never connected');
    }

    /**
     * A proxy of the environment (HTTPS_PROXY / HTTP_PROXY, as kubectl and the API's curl calls
     * follow it): the native client would go around it, so the server's commands go to kubectl —
     * unless NO_PROXY names the server, or it is a loopback address.
     */
    public function test_a_proxy_of_the_environment_sends_commands_to_kubectl(): void
    {
        $saved = [];
        foreach (['HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy', 'NO_PROXY', 'no_proxy'] as $name) {
            $saved[$name] = getenv($name);
            putenv($name);
        }
        try {
            $remote = $this->target("apiVersion: v1\nkind: Config\ncurrent-context: c\ncontexts: [{name: c, context: {cluster: k, user: u}}]\n"
                ."clusters: [{name: k, cluster: {server: 'https://10.255.0.10:6443', insecure-skip-tls-verify: true}}]\nusers: [{name: u, user: {token: abc}}]\n");
            $this->assertNotNull(SelectingExecTransport::nativeFor($remote), 'no proxy set');

            putenv('HTTPS_PROXY=http://proxy.internal:3128');
            Log::spy();
            $this->assertNull(SelectingExecTransport::nativeFor($remote));
            $this->assertSame("via-kubectl\n", $this->selecting()->exec($remote, ['/bin/sh', '-c', 'echo via-kubectl'])->stdout);
            $this->assertSame(1, $this->kubectlRuns());
            Log::shouldHaveReceived('info')->with('sandbox exec: kubectl instead of the native client: HTTPS_PROXY sends the API server through a proxy, which only kubectl follows', \Mockery::any())->once();

            putenv('NO_PROXY=localhost,10.255.0.0/16');
            $this->assertNotNull(SelectingExecTransport::nativeFor($remote), 'NO_PROXY names the server');
            putenv('NO_PROXY');

            // The fake API server listens on 127.0.0.1: loopback never goes through a proxy
            $server = $this->server(['tls' => true]);
            $r = $this->selecting()->exec($this->target($server->kubeconfig(['certificate-authority-data' => $server->tls['ca_data']])), ['/bin/sh', '-c', 'echo native']);
            $this->assertSame("native\n", $r->stdout);
            $this->assertCount(1, $server->events('request'));
            $this->assertSame(1, $this->kubectlRuns());
        } finally {
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : "{$name}={$value}");
            }
        }
    }

    public function test_a_server_without_v5_takes_commands_with_stdin_to_kubectl_and_is_remembered(): void
    {
        $server = $this->server(['protocols' => [ExecProtocol::V4]]);
        $target = $this->target($server->kubeconfig());

        // Without stdin, v4 is enough
        $this->assertSame("v4\n", $this->selecting()->exec($target, ['/bin/sh', '-c', 'echo v4'])->stdout);
        $this->assertSame(0, $this->kubectlRuns());

        // With stdin: the upgrade is refused before the command starts, kubectl runs it
        $this->assertSame('input', $this->selecting()->exec($target, ['cat'], 'input')->stdout);
        $this->assertSame(1, $this->kubectlRuns());
        $this->assertCount(2, $server->events('request'));

        // Remembered: the next command goes to kubectl without an upgrade
        $this->selecting()->exec($target, ['true']);
        $this->assertSame(2, $this->kubectlRuns());
        $this->assertCount(2, $server->events('request'));
    }

    public function test_a_refused_upgrade_goes_to_kubectl_for_that_command_only(): void
    {
        $server = $this->server(['status' => 404, 'body' => 'pods "sbx-1" not found']);
        $target = $this->target($server->kubeconfig());

        $this->selecting()->exec($target, ['true']);
        $this->selecting()->exec($target, ['true']);

        $this->assertSame(2, $this->kubectlRuns());
        $this->assertCount(2, $server->events('request'), 'a 404 is not remembered: each command tries the native client');
    }

    public function test_an_unreachable_server_goes_to_kubectl(): void
    {
        $server = $this->server();
        $kubeconfig = $server->kubeconfig();
        $server->stop();

        $r = $this->selecting()->exec($this->target($kubeconfig), ['/bin/sh', '-c', 'echo fallback']);

        $this->assertSame("fallback\n", $r->stdout);
        $this->assertSame(1, $this->kubectlRuns());
    }

    public function test_the_kubectl_setting_is_the_escape_hatch(): void
    {
        $server = $this->server();
        config(['sandbox.exec.client' => 'kubectl']);

        $this->selecting()->exec($this->target($server->kubeconfig()), ['true']);

        $this->assertSame(1, $this->kubectlRuns());
        $this->assertSame([], $server->events('request'));
    }

    // ── Frames ─────────────────────────────────────────────────────────────────────────────

    public function test_frames_split_over_reads_and_masked_client_frames(): void
    {
        $frames = new WebSocketFrames;
        $big = str_repeat('x', 70_000);
        $wire = chr(0x82).chr(126).pack('n', 300).str_repeat('a', 300)
            .chr(0x82).chr(127).pack('J', strlen($big)).$big
            .chr(0x89).chr(2).'hi';

        $out = [];
        foreach (str_split($wire, 7) as $piece) {
            array_push($out, ...$frames->feed($piece));
        }
        $this->assertSame([[0x2, str_repeat('a', 300)], [0x2, $big], [0x9, 'hi']], $out);

        $client = WebSocketFrames::encode(0x2, "\x00data");
        $this->assertSame(0x82, ord($client[0]));
        $this->assertSame(0x80 | 5, ord($client[1]));
        $this->assertSame("\x00data", substr($client, 6) ^ str_repeat(substr($client, 2, 4), 2));

        $this->expectException(ExecTransportException::class);
        (new WebSocketFrames)->feed(chr(0x82).chr(0x81)."\x01\x02\x03\x04x");
    }

    public function test_status_messages(): void
    {
        $this->assertSame(0, ExecProtocol::exitCode('{"metadata":{},"status":"Success"}'));
        $this->assertSame(42, ExecProtocol::exitCode('{"status":"Failure","reason":"NonZeroExitCode","details":{"causes":[{"reason":"ExitCode","message":"42"}]}}'));
        $this->expectExceptionMessage('error: pods "x" not found');
        ExecProtocol::exitCode('{"status":"Failure","reason":"NotFound","message":"pods \"x\" not found"}');
    }
}
