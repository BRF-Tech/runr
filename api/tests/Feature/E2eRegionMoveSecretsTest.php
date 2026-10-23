<?php

namespace Tests\Feature;

use App\Console\Commands\E2eTestCommand;
use App\Models\KnativeRegion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The secret cases of the region-move e2e (E2eTestRegionMoveSecretsTrait): the function rm-secrets
 * answers the SHA-256 of its secret variables, and the suite checks them through a cluster's
 * Traefik address with the function's host — without ever printing a value or a digest.
 */
class E2eRegionMoveSecretsTest extends TestCase
{
    use RefreshDatabase;

    private function invoke(string $method, mixed ...$args): mixed
    {
        $command = app(E2eTestCommand::class);

        return (new \ReflectionMethod($command, $method))->invoke($command, ...$args);
    }

    /** @return array<string, mixed> a test entry of rm-secrets in a region with a function domain */
    private function entry(): array
    {
        $region = KnativeRegion::create(['name' => 'eu', 'kubeconfig' => 'apiVersion: v1', 'is_active' => true]);
        $region->forceFill(['knative_settings' => ['knative_domain' => 'eu.runr.test']])->save();

        return ['name' => 'rm-secrets', 'slug' => 'rm-secrets', 'regionId' => $region->id];
    }

    public function test_the_digests_are_read_through_the_address_with_the_functions_host(): void
    {
        $sec = ['APP_SECRET' => 'a-value', 'BAO_SECRET' => 'b-value'];
        $want = $this->invoke('rmDigests', ['sec' => $sec], ['app' => 'APP_SECRET', 'bao' => 'BAO_SECRET']);
        $this->assertSame(['app' => hash('sha256', 'a-value'), 'bao' => hash('sha256', 'b-value')], $want);

        // The runtime wraps the answer in {statusCode, body}, or answers the body itself
        Http::fakeSequence('10.0.0.5/*')
            ->push(['statusCode' => 200, 'body' => $want + ['ext' => null]])
            ->push($want);
        $entry = $this->entry();

        $this->assertTrue($this->invoke('rmSecretsSeen', '10.0.0.5', $entry, $want, 0));
        $this->assertTrue($this->invoke('rmSecretsSeen', '10.0.0.5', $entry, $want, 0));
        Http::assertSent(fn (Request $request) => $request->url() === 'http://10.0.0.5/' && $request->header('Host') === ['rm-secrets.eu.runr.test']);
    }

    public function test_a_missing_or_old_value_names_the_variable_not_the_value(): void
    {
        $want = ['app' => hash('sha256', 'new-value'), 'bao' => hash('sha256', 'b-value')];
        Http::fake(['10.0.0.5/*' => Http::response(['body' => ['app' => hash('sha256', 'old-value'), 'bao' => $want['bao']]])]);

        $result = $this->invoke('rmSecretsSeen', '10.0.0.5', $this->entry(), $want, 0);

        $this->assertIsString($result);
        $this->assertStringContainsString('not (yet) the wanted app', $result);
        $this->assertStringNotContainsString('bao,', $result);
        foreach ([$want['app'], $want['bao'], hash('sha256', 'old-value'), 'new-value', 'old-value'] as $secret) {
            $this->assertStringNotContainsString($secret, $result);
        }
    }

    public function test_without_an_address_it_says_so_at_once(): void
    {
        $result = $this->invoke('rmSecretsSeen', '', $this->entry(), ['app' => hash('sha256', 'x')], 0);

        $this->assertIsString($result);
        $this->assertStringContainsString('no address', $result);
        Http::assertNothingSent();
    }
}
