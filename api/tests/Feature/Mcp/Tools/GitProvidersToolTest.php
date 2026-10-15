<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Git\GitProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * git_providers_list: the organization's GitHub Apps / GitLab / Forgejo instances, read-only,
 * secrets as has_* flags only (git_providers.manage). Setting a provider up stays in the console.
 */
class GitProvidersToolTest extends TestCase
{
    use McpToolFixtures, RefreshDatabase;

    private const SECRET = 'gitlab-application-secret-do-not-show';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
    }

    public function test_providers_are_listed_without_secrets(): void
    {
        GitProvider::create([
            'type' => GitProvider::GITLAB,
            'name' => 'Company GitLab',
            'base_url' => 'https://gitlab.example.com',
            'client_id' => 'app-id',
            'client_secret' => self::SECRET,
            'is_enabled' => true,
        ]);

        $result = $this->structured($this->callTool('git_providers_list'));

        $this->assertSame(1, $result['count']);
        $provider = $result['providers'][0];
        $this->assertSame('gitlab', $provider['type']);
        $this->assertSame('Company GitLab', $provider['name']);
        $this->assertTrue($provider['has_client_secret']);
        $this->assertSame(0, $provider['repositories']);
        $this->assertStringNotContainsString(self::SECRET, json_encode($result));
    }

    public function test_listing_providers_needs_git_providers_manage(): void
    {
        $this->assertToolHidden('git_providers_list', ['*'], [], $this->userWithRole('developer'));
        $this->assertToolHidden('git_providers_list', ['git.manage']);
        $this->assertSchemaRejects('git_providers_list', ['type' => 'github'], '/type');
    }
}
