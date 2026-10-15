<?php

namespace Tests\Feature\Ai;

use App\Models\AiHubCredential;
use App\Models\User;
use App\Services\Ai\Hub\HubClient;
use App\Services\Ai\Hub\HubException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The server-side Hugging Face Hub proxy: search with task / library / sort filters and cursor
 * paging, model info (pinned commit, size from the weight files, license, gated flag, card
 * summary without its YAML header, engine suggestions), the organization's token on every call,
 * error mapping, the browse switch for air-gapped consoles, and the token endpoints.
 */
class HubProxyTest extends TestCase
{
    use RefreshDatabase;

    private const HUB = 'https://huggingface.co';

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.huggingface.endpoint' => self::HUB, 'ai.huggingface.browse_enabled' => true, 'ai.huggingface.cache_seconds' => 0]);
        $this->actingAs(User::factory()->create(), 'sanctum');
    }

    private function fakeHub(): void
    {
        Http::fake([
            self::HUB.'/api/models/sentence-transformers/all-MiniLM-L6-v2*' => Http::response([
                'id' => 'sentence-transformers/all-MiniLM-L6-v2',
                'sha' => 'c9745ed1d9f207416be6d2e6f8de32d1f16199bf',
                'pipeline_tag' => 'sentence-similarity',
                'library_name' => 'sentence-transformers',
                'gated' => false,
                'private' => false,
                'downloads' => 1000,
                'likes' => 10,
                'tags' => ['sentence-transformers', 'pytorch', 'text-embeddings-inference', 'license:apache-2.0'],
                'cardData' => ['license' => 'apache-2.0'],
                // the same model in seven formats, as the real repository has it
                'siblings' => [
                    ['rfilename' => '1_Pooling/config.json', 'size' => 190],
                    ['rfilename' => 'README.md', 'size' => 10_000],
                    ['rfilename' => 'config.json', 'size' => 600],
                    ['rfilename' => 'model.safetensors', 'size' => 90_000_000],
                    ['rfilename' => 'onnx/model.onnx', 'size' => 90_400_000],
                    ['rfilename' => 'onnx/model_qint8_avx512.onnx', 'size' => 23_000_000],
                    ['rfilename' => 'openvino/openvino_model.bin', 'size' => 90_300_000],
                    ['rfilename' => 'pytorch_model.bin', 'size' => 90_900_000],
                    ['rfilename' => 'rust_model.ot', 'size' => 90_900_000],
                    ['rfilename' => 'tf_model.h5', 'size' => 91_000_000],
                    ['rfilename' => 'tokenizer.json', 'size' => 466_000],
                ],
                'safetensors' => ['total' => 22_700_000],
            ]),
            self::HUB.'/sentence-transformers/all-MiniLM-L6-v2/resolve/*' => Http::response("---\nlicense: apache-2.0\ntags: [a]\n---\n# all-MiniLM-L6-v2\n\n![badge](https://example.com/b.svg)\nMaps sentences to a 384 dimensional dense vector space.\n"),
            self::HUB.'/api/models?*' => Http::response([
                ['id' => 'BAAI/bge-small-en-v1.5', 'pipeline_tag' => 'feature-extraction', 'library_name' => 'sentence-transformers', 'downloads' => 50, 'likes' => 5, 'tags' => ['onnx'], 'lastModified' => '2026-01-01T00:00:00Z'],
                ['id' => 'meta-llama/Llama-3.2-1B', 'pipeline_tag' => 'text-generation', 'gated' => 'manual', 'downloads' => 40, 'likes' => 4],
            ], 200, ['Link' => '<https://huggingface.co/api/models?search=bge&cursor=eyJhIjoxfQ%3D%3D>; rel="next"']),
        ]);
    }

    public function test_search_passes_the_filters_and_maps_the_results(): void
    {
        $this->fakeHub();

        $response = $this->getJson('/api/models/hub/search?search=bge&task=feature-extraction&library=sentence-transformers&sort=likes&limit=10')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'BAAI/bge-small-en-v1.5')
            ->assertJsonPath('data.0.author', 'BAAI')
            ->assertJsonPath('data.0.task', 'feature-extraction')
            ->assertJsonPath('data.1.gated', true)
            ->assertJsonPath('next_cursor', 'eyJhIjoxfQ==');

        Http::assertSent(function (Request $request) {
            if (! str_starts_with($request->url(), self::HUB.'/api/models?')) {
                return false;
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return $q['search'] === 'bge'
                && $q['pipeline_tag'] === 'feature-extraction'
                && $q['filter'] === 'sentence-transformers'
                && $q['sort'] === 'likes'
                && $q['direction'] === '-1'
                && $q['limit'] === '10';
        });
        $this->assertCount(2, $response->json('data'));
    }

    public function test_info_pins_the_commit_and_sizes_the_weights(): void
    {
        $this->fakeHub();

        $info = $this->getJson('/api/models/hub/info?id=sentence-transformers/all-MiniLM-L6-v2')
            ->assertOk()
            ->json();

        $this->assertSame('c9745ed1d9f207416be6d2e6f8de32d1f16199bf', $info['sha']);
        $this->assertSame('sentence-similarity', $info['task']);
        $this->assertSame('apache-2.0', $info['license']);
        $this->assertFalse($info['gated']);
        $this->assertSame(90_000_000, $info['size_bytes'], 'the safetensors the engines load, not the other six formats, README or config');
        $this->assertSame(['format' => 'safetensors', 'files' => ['model.safetensors']], $info['weights']);
        $this->assertSame(1024, $info['suggested']['memory_mb'], 'TEI: 2 × 86 MiB + 512 MiB, at least 1 GiB');
        $this->assertSame(22_700_000, $info['parameters']);
        $this->assertStringStartsWith('# all-MiniLM-L6-v2', $info['card_summary'], 'YAML header stripped');
        $this->assertStringNotContainsString('badge', $info['card_summary'], 'images stripped');
        $this->assertSame('tei', $info['suggested']['engine'], 'TEI-compatible embedding model');
        $this->assertSame('cpu', $info['suggested']['device']);
        $this->assertSame('tei', collect($info['engines'])->firstWhere('recommended', true)['engine']);
    }

    public function test_the_organization_token_is_sent_to_the_hub(): void
    {
        $this->fakeHub();
        AiHubCredential::create(['provider' => 'huggingface', 'token' => 'hf_secret_token_1234', 'hint' => '…1234']);

        $this->getJson('/api/models/hub/search?search=x')->assertOk();

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer hf_secret_token_1234'));
    }

    public function test_hub_errors_are_mapped(): void
    {
        Http::fake([
            self::HUB.'/api/models/acme/private*' => Http::response(['error' => 'Repository not found'], 401),
            self::HUB.'/api/models/acme/gone*' => Http::response(['error' => 'not found'], 404),
            self::HUB.'/api/models?*' => Http::response('upstream', 500),
        ]);

        $this->getJson('/api/models/hub/info?id=acme/private')->assertStatus(403);
        $this->getJson('/api/models/hub/info?id=acme/gone')->assertStatus(404);
        $this->getJson('/api/models/hub/search?search=x')->assertStatus(502);
        $this->getJson('/api/models/hub/info?id=../etc/passwd')->assertStatus(422);
    }

    public function test_air_gapped_consoles_do_not_call_the_hub(): void
    {
        Http::fake();
        config(['ai.huggingface.browse_enabled' => false]);

        $this->getJson('/api/models/hub/search?search=bert')
            ->assertStatus(503)
            ->assertJsonPath('message', __('ai.hub.disabled'));

        Http::assertNothingSent();
    }

    public function test_gated_models_warn_about_the_token(): void
    {
        Http::fake([
            self::HUB.'/api/models/meta-llama/Llama-3.2-1B*' => Http::response([
                'id' => 'meta-llama/Llama-3.2-1B', 'sha' => str_repeat('b', 40), 'pipeline_tag' => 'text-generation',
                'library_name' => 'transformers', 'gated' => 'manual', 'siblings' => [['rfilename' => 'model.safetensors', 'size' => 2_500_000_000]],
            ]),
            self::HUB.'/meta-llama/*' => Http::response('', 404),
        ]);

        $info = $this->getJson('/api/models/hub/info?id=meta-llama/Llama-3.2-1B')->assertOk()->json();

        $this->assertTrue($info['gated']);
        $this->assertContains('gated_needs_token', $info['warnings']);
        $this->assertNull($info['card_summary'], 'a missing card is no error');
    }

    public function test_token_endpoints_store_it_encrypted_and_never_return_it(): void
    {
        Http::fake([self::HUB.'/api/whoami-v2' => Http::response(['name' => 'acme'])]);

        $this->putJson('/api/models/hub-token', ['token' => 'hf_abcdefgh12345678', 'validate' => true])
            ->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonPath('hint', '…5678')
            ->assertJsonMissingPath('token');

        $raw = DB::table('ai_hub_credentials')->value('token');
        $this->assertNotSame('hf_abcdefgh12345678', $raw, 'encrypted at rest');
        $this->assertSame('hf_abcdefgh12345678', AiHubCredential::huggingFace()->token);

        $this->getJson('/api/models/hub-token')->assertOk()->assertJsonPath('configured', true)->assertJsonPath('hint', '…5678');

        $this->deleteJson('/api/models/hub-token')->assertOk()->assertJsonPath('configured', false);
        $this->assertNull(AiHubCredential::huggingFace());
    }

    public function test_a_token_the_hub_rejects_is_not_stored(): void
    {
        Http::fake([self::HUB.'/api/whoami-v2' => Http::response(['error' => 'Invalid'], 401)]);

        $this->putJson('/api/models/hub-token', ['token' => 'hf_wrongwrong', 'validate' => true])->assertStatus(422);
        $this->assertNull(AiHubCredential::huggingFace());
    }

    public function test_summaries_and_cursor_helpers(): void
    {
        $this->assertNull(HubClient::summarize("---\na: b\n---\n"));
        $this->assertSame(1201, mb_strlen(HubClient::summarize(str_repeat('x', 5000))), '1200 characters + ellipsis');
        $this->assertNull(HubClient::nextCursor(null));

        $this->expectException(HubException::class);
        HubClient::assertId('not-a-repo-id');
    }
}
