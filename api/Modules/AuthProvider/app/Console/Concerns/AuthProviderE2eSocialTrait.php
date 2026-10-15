<?php

namespace Modules\AuthProvider\Console\Concerns;

use App\Models\User;
use Modules\AuthProvider\Models\SocialAccount;

trait AuthProviderE2eSocialTrait
{
    protected function runSocialAccountTests(array &$results): void
    {
        $results[] = $this->runTest('Social Account — Create via DB', function () {
            // Simulate a social account link (normally done via OAuth callback)
            $user = User::where('username', 'admin')->first();
            $this->e2eAssert((bool) $user, 'Admin user not found');

            $account = SocialAccount::create([
                'user_id' => $user->id,
                'provider' => 'google',
                'provider_user_id' => 'google-test-12345',
                'email' => $user->email,
                'name' => $user->name,
                'avatar_url' => null,
                'access_token' => 'test-access-token',
                'refresh_token' => 'test-refresh-token',
                'token_expires_at' => now()->addHour(),
            ]);
            $this->socialAccountId = $account->id;
            $this->e2eAssert(! empty($account->id), 'Social account not created');
        });

        $results[] = $this->runTest('Social Account — List (API)', function () {
            $this->e2eAssert((bool) $this->socialAccountId, 'Skip: prereq');

            $r = $this->api()->get('/api/social-accounts');
            $this->e2eAssert($r->successful(), "List: {$r->status()}");

            $accounts = collect($r->json());
            $this->e2eAssert($accounts->count() >= 1, 'No social accounts returned');

            $found = $accounts->firstWhere('id', $this->socialAccountId);
            $this->e2eAssert($found !== null, 'Created account not in list');
            $this->e2eAssert($found['provider'] === 'google', 'Provider mismatch');
            $this->e2eAssert($found['provider_user_id'] === 'google-test-12345', 'Provider user ID mismatch');

            // Tokens should NOT be exposed in list response
            $this->e2eAssert(! isset($found['access_token']), 'access_token leaked');
            $this->e2eAssert(! isset($found['refresh_token']), 'refresh_token leaked');
        });

        $results[] = $this->runTest('Social Account — Unlink', function () {
            $this->e2eAssert((bool) $this->socialAccountId, 'Skip: prereq');

            $r = $this->api()->delete("/api/social-accounts/{$this->socialAccountId}");
            $this->e2eAssert($r->successful(), "Unlink: {$r->status()}");

            // Verify deleted
            $r = $this->api()->get('/api/social-accounts');
            $accounts = collect($r->json());
            $found = $accounts->firstWhere('id', $this->socialAccountId);
            $this->e2eAssert($found === null, 'Account still present after unlink');
        });

        $results[] = $this->runTest('Social Account — Unlink Forbidden (Other User)', function () {
            // Create account for admin, try to delete with test user
            $adminUser = User::where('username', 'admin')->first();
            $account = SocialAccount::create([
                'user_id' => $adminUser->id,
                'provider' => 'github',
                'provider_user_id' => 'github-admin-99999',
                'email' => $adminUser->email,
                'access_token' => 'test-token',
            ]);

            // Try to delete admin's account with test user token
            $r = $this->api($this->userToken)->delete("/api/social-accounts/{$account->id}");
            $this->e2eAssert(
                $r->status() === 403,
                "Expected 403 for other user's account, got {$r->status()}"
            );

            // Cleanup
            $account->delete();
        });
    }

    protected function runOAuthFlowTests(array &$results): void
    {
        $results[] = $this->runTest('OAuth Callback — Invalid State', function () {
            // Callback with invalid/expired state should redirect with error
            $r = $this->publicApi()->withoutRedirecting()->get(
                '/oauth/google/callback?state=invalid-state&code=test-code'
            );
            // Should redirect (302) to frontend with oauth_error param
            $this->e2eAssert(
                $r->status() === 302,
                "Expected 302 redirect, got {$r->status()}"
            );
            $location = $r->header('Location') ?? '';
            $this->e2eAssert(
                str_contains($location, 'oauth_error'),
                "Redirect should contain oauth_error: {$location}"
            );
        });

        $results[] = $this->runTest('OAuth Link — Requires Auth', function () {
            // Link endpoint requires authentication
            $r = $this->publicApi()->get('/api/oauth/google/link');
            $this->e2eAssert(
                $r->status() === 401,
                "Link without auth: expected 401, got {$r->status()}"
            );
        });
    }

    protected function runCleanupTests(array &$results): void
    {
        $results[] = $this->runTest('Delete Provider — Google', function () {
            $this->e2eAssert((bool) $this->googleProviderId, 'Skip: prereq');

            $r = $this->api()->delete("/api/auth-providers/{$this->googleProviderId}");
            $this->e2eAssert($r->successful(), "Delete Google: {$r->status()}");
        });

        $results[] = $this->runTest('Delete Provider — GitHub', function () {
            $this->e2eAssert((bool) $this->githubProviderId, 'Skip: prereq');

            $r = $this->api()->delete("/api/auth-providers/{$this->githubProviderId}");
            $this->e2eAssert($r->successful(), "Delete GitHub: {$r->status()}");
        });

        $results[] = $this->runTest('Delete Provider — OIDC', function () {
            $this->e2eAssert((bool) $this->oidcProviderId, 'Skip: prereq');

            $r = $this->api()->delete("/api/auth-providers/{$this->oidcProviderId}");
            $this->e2eAssert($r->successful(), "Delete OIDC: {$r->status()}");
        });

        $results[] = $this->runTest('Verify All Deleted', function () {
            $r = $this->api()->get('/api/auth-providers');
            $this->e2eAssert($r->successful(), "List: {$r->status()}");
            $count = count($r->json());
            $this->e2eAssert($count === 0, "Expected 0 providers after cleanup, got {$count}");

            // Discover should return empty too
            $r = $this->publicApi()->get('/api/auth-providers/discover');
            $this->e2eAssert($r->successful(), "Discover: {$r->status()}");
            $this->e2eAssert(count($r->json()) === 0, 'Discover should return empty');
        });
    }
}
