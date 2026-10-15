<?php

namespace Modules\AuthProvider\Console\Concerns;

trait AuthProviderE2eDiscoverTrait
{
    protected function runDiscoverTests(array &$results): void
    {
        $results[] = $this->runTest('Discover — Public (No Auth)', function () {
            // Discover endpoint should work without authentication
            $r = $this->publicApi()->get('/api/auth-providers/discover');
            $this->e2eAssert($r->successful(), "Discover: {$r->status()}");

            $providers = collect($r->json());
            // Only enabled providers should appear (Google enabled, GitHub enabled, OIDC disabled)
            $enabledCount = $providers->count();
            $this->e2eAssert($enabledCount === 2, "Expected 2 enabled, got {$enabledCount}");

            // Should not include OIDC (disabled)
            $hasOidc = $providers->contains('driver', 'oidc');
            $this->e2eAssert(! $hasOidc, 'Disabled OIDC should not appear in discover');

            // Should only return safe fields (no client_secret)
            $first = $providers->first();
            $this->e2eAssert(isset($first['id']), 'Missing id in discover response');
            $this->e2eAssert(isset($first['name']), 'Missing name in discover response');
            $this->e2eAssert(isset($first['driver']), 'Missing driver in discover response');
            $this->e2eAssert(! isset($first['client_secret']), 'client_secret leaked in discover');
            $this->e2eAssert(! isset($first['client_id']), 'client_id leaked in discover');
        });

        $results[] = $this->runTest('Discover — Ordering', function () {
            $r = $this->publicApi()->get('/api/auth-providers/discover');
            $this->e2eAssert($r->successful(), "Discover: {$r->status()}");

            $providers = $r->json();
            if (count($providers) >= 2) {
                // GitHub (order 2) should come before Google (order 10 after update)
                $first = $providers[0]['driver'];
                $second = $providers[1]['driver'];
                $this->e2eAssert(
                    $first === 'github',
                    "Expected github first (order 2), got {$first}"
                );
            }
        });

        $results[] = $this->runTest('Redirect URL — Public (No Auth)', function () {
            $this->e2eAssert((bool) $this->githubProviderId, 'Skip: prereq');

            // Redirect endpoint should return a URL (will fail on actual redirect
            // since we don't have real credentials, but the endpoint should respond)
            $r = $this->publicApi()->get('/api/oauth/github/redirect');

            // Should get either a redirect URL or an error about invalid config
            // We mainly test that the endpoint is accessible without auth
            // A 500 is acceptable here since we have dummy credentials
            $this->e2eAssert(
                $r->status() !== 401 && $r->status() !== 403,
                "Redirect should not require auth: {$r->status()}"
            );
        });
    }

    protected function runValidationTests(array &$results): void
    {
        $results[] = $this->runTest('Validation — Missing Required Fields', function () {
            $r = $this->api()->post('/api/auth-providers', [
                'name' => 'Incomplete',
                // Missing: driver, client_id, client_secret
            ]);
            $this->e2eAssert($r->status() === 422, "Expected 422, got {$r->status()}");
        });

        $results[] = $this->runTest('Validation — Invalid Driver', function () {
            $r = $this->api()->post('/api/auth-providers', [
                'name' => 'Invalid',
                'driver' => 'saml', // Not supported in this phase
                'client_id' => 'test',
                'client_secret' => 'test',
            ]);
            $this->e2eAssert($r->status() === 422, "Expected 422, got {$r->status()}");
        });

        $results[] = $this->runTest('Validation — OIDC Requires Discovery URL', function () {
            $r = $this->api()->post('/api/auth-providers', [
                'name' => 'Bad OIDC',
                'driver' => 'oidc',
                'client_id' => 'test',
                'client_secret' => 'test',
                // Missing oidc_discovery_url (required for oidc driver)
            ]);
            $this->e2eAssert($r->status() === 422, "Expected 422, got {$r->status()}");
        });

        $results[] = $this->runTest('Auth Required — CRUD Endpoints', function () {
            // List should require auth
            $r = $this->publicApi()->get('/api/auth-providers');
            $this->e2eAssert(
                $r->status() === 401,
                "List without auth: expected 401, got {$r->status()}"
            );

            // Create should require auth
            $r = $this->publicApi()->post('/api/auth-providers', [
                'name' => 'X', 'driver' => 'google',
                'client_id' => 'x', 'client_secret' => 'x',
            ]);
            $this->e2eAssert(
                $r->status() === 401,
                "Create without auth: expected 401, got {$r->status()}"
            );
        });
    }
}
