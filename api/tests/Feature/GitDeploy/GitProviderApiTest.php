<?php

namespace Tests\Feature\GitDeploy;

use App\Models\Git\GitProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\GitDeployFixtures;
use Tests\TestCase;

/**
 * Settings → Git providers: git_providers.manage, per-type validation, write-only encrypted
 * secrets (empty keeps), the URLs to enter at the provider, delete only while unused.
 */
class GitProviderApiTest extends TestCase
{
    use ActsWithRoles, GitDeployFixtures, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGitDeploy();
        $this->owner = $this->userWithRole('owner');
    }

    protected function tearDown(): void
    {
        $this->tearDownGitDeploy();
        parent::tearDown();
    }

    private function githubPayload(array $override = []): array
    {
        return array_merge([
            'type' => 'github',
            'name' => 'GitHub',
            'app_id' => '12345',
            'app_slug' => 'runr-test',
            'client_id' => 'Iv1.client',
            'client_secret' => 'client-secret',
            'private_key' => self::testPrivateKey(),
            'webhook_secret' => 'a-webhook-secret-of-24ch',
        ], $override);
    }

    public function test_a_github_app_is_added_with_write_only_secrets(): void
    {
        $response = $this->signedIn($this->owner)->postJson('/api/git-providers', $this->githubPayload())->assertCreated();

        $id = $response->json('data.id');
        $response
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.has_private_key', true)
            ->assertJsonPath('data.has_webhook_secret', true)
            ->assertJsonPath('data.has_client_secret', true)
            ->assertJsonPath('data.web_url', 'https://github.com')
            ->assertJsonPath('data.urls.webhook', "https://console.test/api/git/webhooks/github/{$id}")
            ->assertJsonPath('data.urls.setup', 'https://console.test/api/git/github/setup')
            ->assertJsonMissingPath('data.private_key')
            ->assertJsonMissingPath('data.webhook_secret')
            ->assertJsonMissingPath('data.client_secret');

        $raw = DB::table('git_providers')->where('id', $id)->first();
        $this->assertStringNotContainsString('BEGIN', (string) $raw->private_key);
        $this->assertNotSame('a-webhook-secret-of-24ch', $raw->webhook_secret);

        $listed = $this->signedIn($this->owner)->getJson('/api/git-providers')->assertOk();
        $this->assertStringNotContainsString('a-webhook-secret-of-24ch', $listed->getContent());
        $this->assertStringNotContainsString('client-secret', $listed->getContent());
    }

    public function test_empty_secret_fields_keep_the_stored_value(): void
    {
        $id = $this->signedIn($this->owner)->postJson('/api/git-providers', $this->githubPayload())->json('data.id');

        $this->signedIn($this->owner)->putJson("/api/git-providers/{$id}", ['name' => 'GitHub Org', 'webhook_secret' => '', 'private_key' => null])
            ->assertOk()->assertJsonPath('data.name', 'GitHub Org');

        $provider = GitProvider::findOrFail($id);
        $this->assertSame('a-webhook-secret-of-24ch', $provider->webhook_secret);
        $this->assertNotEmpty($provider->private_key);

        $this->signedIn($this->owner)->putJson("/api/git-providers/{$id}", ['type' => 'gitlab'])->assertUnprocessable();
    }

    public function test_validation_per_type(): void
    {
        $this->signedIn($this->owner)->postJson('/api/git-providers', $this->githubPayload(['private_key' => 'not a key']))
            ->assertUnprocessable()->assertJsonValidationErrors('private_key');
        $this->signedIn($this->owner)->postJson('/api/git-providers', $this->githubPayload(['webhook_secret' => 'short']))
            ->assertUnprocessable()->assertJsonValidationErrors('webhook_secret');
        $this->signedIn($this->owner)->postJson('/api/git-providers', ['type' => 'forgejo', 'name' => 'Code'])
            ->assertUnprocessable()->assertJsonValidationErrors('base_url');
        $this->signedIn($this->owner)->postJson('/api/git-providers', ['type' => 'bitbucket', 'name' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('type');

        $this->signedIn($this->owner)->postJson('/api/git-providers', ['type' => 'forgejo', 'name' => 'Code', 'base_url' => 'https://code.example.com/'])
            ->assertCreated()
            ->assertJsonPath('data.base_url', 'https://code.example.com')
            ->assertJsonPath('data.connect_modes', ['pat']);
        $this->signedIn($this->owner)->postJson('/api/git-providers', ['type' => 'gitlab', 'name' => 'GitLab', 'client_id' => 'app', 'client_secret' => 'secret'])
            ->assertCreated()
            ->assertJsonPath('data.connect_modes', ['oauth', 'pat'])
            ->assertJsonPath('data.urls.callback', 'https://console.test/api/git/oauth/gitlab/callback');
    }

    public function test_only_holders_of_git_providers_manage(): void
    {
        $developer = $this->userWithRole('developer');

        $this->signedIn($developer)->getJson('/api/git-providers')->assertForbidden();
        $this->signedIn($developer)->postJson('/api/git-providers', ['type' => 'forgejo', 'name' => 'x', 'base_url' => 'https://x.test'])->assertForbidden();
    }

    public function test_a_provider_in_use_cannot_be_deleted(): void
    {
        $provider = $this->provider(GitProvider::FORGEJO);
        $link = $this->link($this->faasProject($this->demoRegion()), $provider);

        $this->signedIn($this->owner)->deleteJson("/api/git-providers/{$provider->id}")->assertUnprocessable();

        $link->delete();
        $this->signedIn($this->owner)->deleteJson("/api/git-providers/{$provider->id}")->assertNoContent();
        $this->assertSame(0, GitProvider::count());
    }
}
