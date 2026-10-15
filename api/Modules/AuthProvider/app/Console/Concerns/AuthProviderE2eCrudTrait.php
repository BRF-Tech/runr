<?php

namespace Modules\AuthProvider\Console\Concerns;

trait AuthProviderE2eCrudTrait
{
    protected function runProviderCrudTests(array &$results): void
    {
        $results[] = $this->runTest('Create Google Provider', function () {
            $r = $this->api()->post('/api/auth-providers', [
                'name' => 'Google Workspace',
                'driver' => 'google',
                'client_id' => 'test-google-client-id',
                'client_secret' => 'test-google-secret',
                'scopes' => ['email', 'profile'],
                'is_enabled' => true,
                'auto_register' => true,
                'display_order' => 1,
            ]);
            $this->e2eAssert($r->status() === 201, "Create: {$r->status()} — {$r->body()}");
            $this->googleProviderId = $r->json('id');
            $this->e2eAssert(! empty($this->googleProviderId), 'No provider ID returned');
            $this->e2eAssert($r->json('name') === 'Google Workspace', 'Name mismatch');
            $this->e2eAssert($r->json('driver') === 'google', 'Driver mismatch');
        });

        $results[] = $this->runTest('Create GitHub Provider', function () {
            $r = $this->api()->post('/api/auth-providers', [
                'name' => 'GitHub',
                'driver' => 'github',
                'client_id' => 'test-github-client-id',
                'client_secret' => 'test-github-secret',
                'scopes' => ['user:email'],
                'is_enabled' => true,
                'auto_register' => false,
                'display_order' => 2,
            ]);
            $this->e2eAssert($r->status() === 201, "Create: {$r->status()} — {$r->body()}");
            $this->githubProviderId = $r->json('id');
            $this->e2eAssert(! empty($this->githubProviderId), 'No provider ID returned');
        });

        $results[] = $this->runTest('Create OIDC Provider', function () {
            $r = $this->api()->post('/api/auth-providers', [
                'name' => 'Corporate SSO',
                'driver' => 'oidc',
                'client_id' => 'test-oidc-client-id',
                'client_secret' => 'test-oidc-secret',
                'oidc_discovery_url' => 'https://sso.example.com/.well-known/openid-configuration',
                'oidc_issuer' => 'https://sso.example.com',
                'is_enabled' => false,
                'display_order' => 3,
            ]);
            $this->e2eAssert($r->status() === 201, "Create: {$r->status()} — {$r->body()}");
            $this->oidcProviderId = $r->json('id');
            $this->e2eAssert($r->json('is_enabled') === false, 'OIDC should be disabled');
        });

        $results[] = $this->runTest('List All Providers', function () {
            $this->e2eAssert((bool) $this->googleProviderId, 'Skip: prereq');

            $r = $this->api()->get('/api/auth-providers');
            $this->e2eAssert($r->successful(), "List: {$r->status()}");

            $providers = collect($r->json());
            $this->e2eAssert($providers->count() === 3, "Expected 3 providers, got {$providers->count()}");

            // Verify ordering (display_order ASC)
            $first = $providers->first();
            $this->e2eAssert($first['driver'] === 'google', "First should be google, got {$first['driver']}");
        });

        $results[] = $this->runTest('Update Provider', function () {
            $this->e2eAssert((bool) $this->googleProviderId, 'Skip: prereq');

            $r = $this->api()->put("/api/auth-providers/{$this->googleProviderId}", [
                'name' => 'Google (Updated)',
                'auto_register' => false,
                'display_order' => 10,
            ]);
            $this->e2eAssert($r->successful(), "Update: {$r->status()}");
            $this->e2eAssert($r->json('name') === 'Google (Updated)', 'Name not updated');
            $this->e2eAssert($r->json('auto_register') === false, 'auto_register not updated');
        });

        $results[] = $this->runTest('Toggle Provider', function () {
            $this->e2eAssert((bool) $this->githubProviderId, 'Skip: prereq');

            // Disable GitHub
            $r = $this->api()->patch("/api/auth-providers/{$this->githubProviderId}/toggle");
            $this->e2eAssert($r->successful(), "Toggle off: {$r->status()}");
            $this->e2eAssert($r->json('is_enabled') === false, 'Should be disabled');

            // Re-enable GitHub
            $r = $this->api()->patch("/api/auth-providers/{$this->githubProviderId}/toggle");
            $this->e2eAssert($r->successful(), "Toggle on: {$r->status()}");
            $this->e2eAssert($r->json('is_enabled') === true, 'Should be enabled');
        });

        $results[] = $this->runTest('Encrypted Fields Not Exposed', function () {
            $this->e2eAssert((bool) $this->googleProviderId, 'Skip: prereq');

            $r = $this->api()->get('/api/auth-providers');
            $this->e2eAssert($r->successful(), "List: {$r->status()}");

            $google = collect($r->json())->firstWhere('id', $this->googleProviderId);
            $this->e2eAssert($google !== null, 'Google not found in list');

            // client_id is the decrypted value (not the raw encrypted blob); the client secret
            // is write-only — never returned, only whether one is stored
            $this->e2eAssert(
                $google['client_id'] === 'test-google-client-id',
                "client_id mismatch: {$google['client_id']}"
            );
            $this->e2eAssert(! array_key_exists('client_secret', $google), 'client_secret must not be returned');
            $this->e2eAssert(($google['has_client_secret'] ?? false) === true, 'has_client_secret should be true');
        });
    }
}
