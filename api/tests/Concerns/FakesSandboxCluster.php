<?php

namespace Tests\Concerns;

use App\Models\KnativeRegion;
use App\Models\User;
use App\Services\Sandboxes\Exec\ExecTransport;
use App\Services\Sandboxes\HostResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\FakeSandboxExecTransport;

/**
 * Sandbox API test fixtures: an in-memory Kubernetes API (namespaces, pods, NetworkPolicies,
 * ResourceQuotas, events, logs) behind Http::fake, the fake exec transport, a fixed DNS table
 * and a region with gVisor and Kata (fc, clh) installed.
 *
 * Pods created through the API start in $podStartState: running (default), pending or
 * image_error (ErrImagePull).
 */
trait FakesSandboxCluster
{
    /** @var list<string> "METHOD /path" of every call */
    protected array $k8sCalls = [];

    /** @var array<string, mixed> last decoded body per "METHOD /path" */
    protected array $k8sBodies = [];

    /** @var array<string, array> name → Namespace */
    protected array $k8sNamespaces = [];

    /** @var array<string, array> "ns/name" → Pod */
    protected array $k8sPods = [];

    /** @var array<string, array> "ns/name" → NetworkPolicy */
    protected array $k8sPolicies = [];

    /** @var array<string, array> "ns/name" → ResourceQuota */
    protected array $k8sQuotas = [];

    /** @var array<string, array> "ns/name" → Secret */
    protected array $k8sSecrets = [];

    protected string $podStartState = 'running';

    protected FakeSandboxExecTransport $exec;

    /** @var array<string, list<string>> host → addresses */
    protected array $dnsTable = [
        'pypi.org' => ['151.101.0.223'],
        'files.pythonhosted.org' => ['151.101.1.63', '2a04:4e42::223'],
        'metadata.example.com' => ['169.254.169.254'],
    ];

    protected function fakeSandboxCluster(): void
    {
        $this->exec = new FakeSandboxExecTransport;
        $this->app->instance(ExecTransport::class, $this->exec);

        $lookup = fn (string $host): array => $this->dnsTable[$host] ?? [];
        $this->app->instance(HostResolver::class, new class($lookup) implements HostResolver
        {
            public function __construct(private \Closure $lookup) {}

            public function resolve(string $host): array
            {
                return ($this->lookup)($host);
            }
        });

        Http::fake(fn (Request $request) => $this->k8sRespond($request));
    }

    protected function sandboxKubeconfig(): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://sbx.test:6443', 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    /** A region with gVisor and Kata (Firecracker + Cloud Hypervisor) installed. */
    protected function sandboxRegion(array $attributes = []): KnativeRegion
    {
        return KnativeRegion::create($attributes + [
            'name' => 'eu-1',
            'kubeconfig' => $this->sandboxKubeconfig(),
            'is_active' => true,
            'sandbox_gvisor_status' => 'ready',
            'sandbox_kata_status' => 'ready',
            'sandbox_kata_hypervisor' => ['clh', 'fc'],
        ]);
    }

    protected function sandboxUser(bool $admin = false): User
    {
        $user = User::factory()->create();
        $user->setAttribute('is_admin', $admin);

        return $user;
    }

    /** Pod of a sandbox as the fake cluster holds it. */
    protected function podOf(string $sandboxId, ?string $namespace = null): ?array
    {
        foreach ($this->k8sPods as $key => $pod) {
            if ($pod['metadata']['name'] === 'sbx-'.strtolower($sandboxId) && ($namespace === null || str_starts_with($key, $namespace.'/'))) {
                return $pod;
            }
        }

        return null;
    }

    /** Mark a fake pod (namespace/name) with a status. */
    protected function setPodStatus(string $key, array $status): void
    {
        $this->k8sPods[$key]['status'] = $status;
    }

    protected function k8sCalled(string $method, string $pathPattern): bool
    {
        foreach ($this->k8sCalls as $call) {
            [$m, $p] = explode(' ', $call, 2);
            if ($m === $method && preg_match($pathPattern, $p)) {
                return true;
            }
        }

        return false;
    }

    private function k8sRespond(Request $request)
    {
        $method = strtoupper($request->method());
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $body = json_decode((string) $request->body(), true);

        $this->k8sCalls[] = "{$method} {$path}";
        $this->k8sBodies["{$method} {$path}"] = $body;

        $json = fn ($data, int $status = 200) => Http::response($data, $status);
        $notFound = fn () => Http::response(['kind' => 'Status', 'code' => 404, 'reason' => 'NotFound'], 404);

        if (preg_match('#^/api/v1/namespaces/([^/]+)$#', $path, $m)) {
            if ($method === 'PATCH' || $method === 'POST') {
                $this->k8sNamespaces[$m[1]] = $body;

                return $json($body);
            }

            return isset($this->k8sNamespaces[$m[1]]) ? $json($this->k8sNamespaces[$m[1]]) : $notFound();
        }

        if (preg_match('#^/api/v1/namespaces/([^/]+)/pods$#', $path, $m) && $method === 'POST') {
            $pod = $body;
            $pod['metadata']['namespace'] = $m[1];
            $pod['metadata']['creationTimestamp'] = now()->subMinute()->toIso8601String();
            $pod['status'] = $this->startStatus();
            $this->k8sPods[$m[1].'/'.$pod['metadata']['name']] = $pod;

            return $json($pod, 201);
        }

        if (preg_match('#^/api/v1/namespaces/([^/]+)/pods/([^/]+)/log$#', $path, $m)) {
            return Http::response('sandbox log', 200);
        }

        if (preg_match('#^/api/v1/namespaces/([^/]+)/pods/([^/]+)$#', $path, $m)) {
            $key = $m[1].'/'.$m[2];
            if ($method === 'DELETE') {
                if (! isset($this->k8sPods[$key])) {
                    return $notFound();
                }
                unset($this->k8sPods[$key]);

                return $json(['kind' => 'Status', 'status' => 'Success']);
            }
            if (! isset($this->k8sPods[$key])) {
                return $notFound();
            }
            if ($method === 'PATCH') {
                foreach (($body['metadata']['labels'] ?? []) as $label => $value) {
                    if ($value === null) {
                        unset($this->k8sPods[$key]['metadata']['labels'][$label]);
                    } else {
                        $this->k8sPods[$key]['metadata']['labels'][$label] = $value;
                    }
                }
            }

            return $json($this->k8sPods[$key]);
        }

        if (preg_match('#^/apis/networking\.k8s\.io/v1/namespaces/([^/]+)/networkpolicies/([^/]+)$#', $path, $m)) {
            $key = $m[1].'/'.$m[2];
            if ($method === 'DELETE') {
                if (! isset($this->k8sPolicies[$key])) {
                    return $notFound();
                }
                unset($this->k8sPolicies[$key]);

                return $json(['kind' => 'Status', 'status' => 'Success']);
            }
            if ($method === 'PATCH') {
                $body['metadata']['namespace'] = $m[1];
                $this->k8sPolicies[$key] = $body;

                return $json($body);
            }

            return isset($this->k8sPolicies[$key]) ? $json($this->k8sPolicies[$key]) : $notFound();
        }

        if (preg_match('#^/api/v1/namespaces/([^/]+)/resourcequotas/([^/]+)$#', $path, $m)) {
            $key = $m[1].'/'.$m[2];
            if ($method === 'DELETE') {
                if (! isset($this->k8sQuotas[$key])) {
                    return $notFound();
                }
                unset($this->k8sQuotas[$key]);

                return $json(['kind' => 'Status']);
            }
            $this->k8sQuotas[$key] = $body;

            return $json($body);
        }

        if (preg_match('#^/api/v1/namespaces/([^/]+)/secrets/([^/]+)$#', $path, $m)) {
            $key = $m[1].'/'.$m[2];
            if ($method === 'DELETE') {
                if (! isset($this->k8sSecrets[$key])) {
                    return $notFound();
                }
                unset($this->k8sSecrets[$key]);

                return $json(['kind' => 'Status']);
            }
            if ($method === 'PATCH') {
                $body['metadata']['namespace'] = $m[1];
                $this->k8sSecrets[$key] = $body;

                return $json($body);
            }

            return isset($this->k8sSecrets[$key]) ? $json($this->k8sSecrets[$key]) : $notFound();
        }

        if ($path === '/api/v1/secrets') {
            return $json(['items' => array_values($this->k8sSecrets)]);
        }

        if (preg_match('#^/api/v1/namespaces/([^/]+)/events$#', $path)) {
            return $json(['items' => []]);
        }

        if ($path === '/api/v1/pods') {
            return $json(['items' => array_values($this->k8sPods)]);
        }

        if ($path === '/apis/networking.k8s.io/v1/networkpolicies') {
            return $json(['items' => array_values($this->k8sPolicies)]);
        }

        return $json([]);
    }

    private function startStatus(): array
    {
        return match ($this->podStartState) {
            'pending' => ['phase' => 'Pending'],
            'image_error' => ['phase' => 'Pending', 'containerStatuses' => [[
                'name' => 'sandbox',
                'state' => ['waiting' => ['reason' => 'ErrImagePull', 'message' => 'pull access denied for example.com/nope']],
            ]]],
            default => ['phase' => 'Running', 'containerStatuses' => [[
                'name' => 'sandbox',
                'ready' => true,
                'state' => ['running' => ['startedAt' => now()->toIso8601String()]],
            ]]],
        };
    }
}
