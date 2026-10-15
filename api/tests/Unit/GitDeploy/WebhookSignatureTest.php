<?php

namespace Tests\Unit\GitDeploy;

use App\Services\GitDeploy\Providers\WebhookSignature;
use Tests\TestCase;

/** Webhook authenticity of GitHub, GitLab and Forgejo/Gitea deliveries (recorded bodies). */
class WebhookSignatureTest extends TestCase
{
    private function body(string $path): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/git/{$path}"));
    }

    public function test_github_hmac_sha256(): void
    {
        $body = $this->body('github/push.json');
        $good = 'sha256='.hash_hmac('sha256', $body, 'github-webhook-secret');

        $this->assertTrue(WebhookSignature::github($body, $good, 'github-webhook-secret'));
        $this->assertFalse(WebhookSignature::github($body, $good, 'another-secret'));
        $this->assertFalse(WebhookSignature::github($body.' ', $good, 'github-webhook-secret'));
        $this->assertFalse(WebhookSignature::github($body, 'sha1='.hash_hmac('sha1', $body, 'github-webhook-secret'), 'github-webhook-secret'));
        $this->assertFalse(WebhookSignature::github($body, null, 'github-webhook-secret'));
        $this->assertFalse(WebhookSignature::github($body, $good, null));
    }

    public function test_gitlab_secret_token(): void
    {
        $this->assertTrue(WebhookSignature::gitlab('link-webhook-secret', 'link-webhook-secret'));
        $this->assertFalse(WebhookSignature::gitlab('link-webhook-secreT', 'link-webhook-secret'));
        $this->assertFalse(WebhookSignature::gitlab('', 'link-webhook-secret'));
        $this->assertFalse(WebhookSignature::gitlab(null, 'link-webhook-secret'));
        $this->assertFalse(WebhookSignature::gitlab('anything', null));
    }

    public function test_forgejo_and_gitea_signatures(): void
    {
        $body = $this->body('forgejo/push.json');
        $hex = hash_hmac('sha256', $body, 'link-webhook-secret');

        $this->assertTrue(WebhookSignature::forgejo($body, ['x-forgejo-signature' => $hex], 'link-webhook-secret'));
        $this->assertTrue(WebhookSignature::forgejo($body, ['x-gitea-signature' => strtoupper($hex)], 'link-webhook-secret'));
        $this->assertTrue(WebhookSignature::forgejo($body, ['x-hub-signature-256' => 'sha256='.$hex], 'link-webhook-secret'));
        $this->assertFalse(WebhookSignature::forgejo($body, ['x-gitea-signature' => $hex], 'wrong'));
        $this->assertFalse(WebhookSignature::forgejo($body, [], 'link-webhook-secret'));
        $this->assertFalse(WebhookSignature::forgejo($body, ['x-gitea-signature' => $hex], null));
    }
}
