<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Services\Concerns\KnativeAgentTrait;
use App\Services\Helpers\AgentToken;
use App\Services\KubernetesClient;
use App\Services\Shell\AgentController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class AgentAuthTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER = 'https://region.test:6443';

    protected function setUp(): void
    {
        parent::setUp();
        // Not running in a pod: no service account → never "RunR's own cluster"
        config(['knative.agent.serviceaccount_dir' => sys_get_temp_dir().'/runr-no-sa-'.uniqid()]);
    }

    public function test_matching_token_is_accepted(): void
    {
        $token = AgentToken::generate();
        $region = $this->region(AgentToken::hash($token));

        $this->assertTrue((new AgentController)->authorizeAgent("Bearer {$token}", $region->id));
    }

    public function test_wrong_missing_or_malformed_token_is_rejected(): void
    {
        $token = AgentToken::generate();
        $region = $this->region(AgentToken::hash($token));
        $ctrl = new AgentController;

        $this->assertFalse($ctrl->authorizeAgent('Bearer '.AgentToken::generate(), $region->id));
        $this->assertFalse($ctrl->authorizeAgent('', $region->id));
        $this->assertFalse($ctrl->authorizeAgent('Bearer ', $region->id));
        $this->assertFalse($ctrl->authorizeAgent($token, $region->id));
        $this->assertFalse($ctrl->authorizeAgent("Basic {$token}", $region->id));
    }

    public function test_unknown_region_is_rejected(): void
    {
        $this->assertFalse((new AgentController)->authorizeAgent('Bearer '.AgentToken::generate(), '01unknownregion000000000000'));
    }

    public function test_region_without_stored_hash_rejects_any_token(): void
    {
        $region = $this->region(null);
        $ctrl = new AgentController;

        // The former check cached the first token it saw (impersonation); now nothing gets in
        $this->assertFalse($ctrl->authorizeAgent('Bearer anything', $region->id));
        $this->assertFalse($ctrl->authorizeAgent('Bearer anything', $region->id));
    }

    public function test_token_of_another_region_is_rejected(): void
    {
        $tokenA = AgentToken::generate();
        $this->region(AgentToken::hash($tokenA), 'a');
        $regionB = $this->region(AgentToken::hash(AgentToken::generate()), 'b');

        $this->assertFalse((new AgentController)->authorizeAgent("Bearer {$tokenA}", $regionB->id));
    }

    public function test_hash_is_hidden_from_serialization(): void
    {
        $region = $this->region(AgentToken::hash('x'));

        $this->assertArrayNotHasKey('agent_token_hash', $region->fresh()->toArray());
    }

    public function test_deploy_stores_hash_of_the_secret_token_and_rolls_the_pod_template(): void
    {
        $region = $this->region(null);
        $this->fakeCluster(existingToken: null);

        $this->deployer()->deploy($region);

        $secretToken = $this->appliedSecretToken();
        $region->refresh();

        $this->assertNotNull($secretToken);
        $this->assertSame(AgentToken::hash($secretToken), $region->agent_token_hash);
        $this->assertTrue((new AgentController)->authorizeAgent("Bearer {$secretToken}", $region->id));
        $this->assertSame(
            AgentToken::revision($region->agent_token_hash),
            $this->appliedDeployment()['spec']['template']['metadata']['annotations']['runr.dev/agent-token-rev'],
        );
    }

    public function test_redeploy_reuses_the_cluster_token_while_it_matches(): void
    {
        $token = AgentToken::generate();
        $region = $this->region(AgentToken::hash($token));
        $this->fakeCluster(existingToken: $token);

        $this->deployer()->deploy($region);

        $this->assertSame($token, $this->appliedSecretToken());
        $this->assertSame(AgentToken::hash($token), $region->refresh()->agent_token_hash);
    }

    public function test_redeploy_rotates_a_token_that_does_not_match(): void
    {
        $region = $this->region(AgentToken::hash('stored'));
        $this->fakeCluster(existingToken: 'tampered');

        $this->deployer()->deploy($region);

        $newToken = $this->appliedSecretToken();
        $this->assertNotSame('tampered', $newToken);
        $this->assertSame(AgentToken::hash($newToken), $region->refresh()->agent_token_hash);
    }

    public function test_hash_is_not_stored_when_the_secret_cannot_be_applied(): void
    {
        $oldHash = AgentToken::hash('old');
        $region = $this->region($oldHash);

        Http::fake([
            self::SERVER.'/api/v1/namespaces/observability/secrets*' => fn (Request $r) => $r->method() === 'GET'
                ? Http::response([], 404)
                : Http::response(['message' => 'forbidden'], 403),
            self::SERVER.'/*' => Http::response(['kind' => 'Status'], 200),
        ]);

        try {
            $this->deployer()->deploy($region);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('runr-agent token Secret', $e->getMessage());
        }

        $this->assertSame($oldHash, $region->refresh()->agent_token_hash);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function region(?string $hash, string $name = 'op'): KnativeRegion
    {
        $region = KnativeRegion::create([
            'name' => $name,
            'kubeconfig' => $this->kubeconfig(),
            'is_active' => true,
        ]);
        $region->forceFill(['agent_token_hash' => $hash])->save();

        return $region;
    }

    private function kubeconfig(): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => self::SERVER, 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    private function fakeCluster(?string $existingToken): void
    {
        Http::fake([
            self::SERVER.'/api/v1/namespaces/observability/secrets*' => function (Request $r) use ($existingToken) {
                if ($r->method() !== 'GET') {
                    return Http::response(['kind' => 'Secret'], 200);
                }

                return $existingToken === null
                    ? Http::response(['kind' => 'Status'], 404)
                    : Http::response(['data' => ['token' => base64_encode($existingToken)]], 200);
            },
            self::SERVER.'/*' => Http::response(['kind' => 'Status'], 200),
        ]);
    }

    private function appliedSecretToken(): ?string
    {
        return $this->appliedManifest('Secret')['stringData']['token'] ?? null;
    }

    private function appliedDeployment(): array
    {
        return $this->appliedManifest('Deployment') ?? [];
    }

    private function appliedManifest(string $kind): ?array
    {
        $found = null;
        Http::recorded(function (Request $request) use ($kind, &$found) {
            if ($request->method() === 'PATCH') {
                $body = json_decode($request->body(), true);
                if (($body['kind'] ?? null) === $kind) {
                    $found = $body;
                }
            }

            return false;
        });

        return $found;
    }

    private function deployer(): object
    {
        return new class
        {
            use KnativeAgentTrait;

            public function deploy(KnativeRegion $region): void
            {
                $this->deployAgent(new KubernetesClient($region->kubeconfig), $region);
            }
        };
    }
}
