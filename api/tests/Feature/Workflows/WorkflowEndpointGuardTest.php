<?php

namespace Tests\Feature\Workflows;

use App\Services\Dns\DnsResolver;
use App\Services\Observability\OutboundUrlGuard;
use App\Workflows\Models\WorkflowApp;
use App\Workflows\Transport\HttpWorkflowTransport;
use App\Workflows\Transport\TransportException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RunsWorkflows;
use Tests\Support\Workflows\FakeStepTools;
use Tests\TestCase;

/**
 * GHSA-2c93-rcvw-qpq2: an endpoint override is checked before every request the engine sends to
 * it, not only when it was saved, and the request goes to the addresses that check saw (curl pin,
 * no redirects). The project's own function URL is RunR's and is called as before.
 */
class WorkflowEndpointGuardTest extends TestCase
{
    use RefreshDatabase;
    use RunsWorkflows;

    /** @var array<string, list<string>> */
    public array $dns = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflows();

        $test = $this;
        $this->app->instance(DnsResolver::class, new class($test) extends DnsResolver
        {
            public function __construct(private readonly WorkflowEndpointGuardTest $test) {}

            public function addresses(string $name): array
            {
                return $this->test->dns[$name] ?? [];
            }
        });
    }

    public function test_a_request_to_an_override_is_pinned_to_the_checked_addresses(): void
    {
        $sent = [];
        Http::fake(function ($request, array $options) use (&$sent) {
            $sent[] = $options;

            return Http::response('{}', 200);
        });
        $this->dns['fn.example.com'] = ['93.184.216.34', '2606:4700:4700::1111'];

        app(HttpWorkflowTransport::class)->send('https://fn.example.com/api/runr', '{}', [], true);

        $this->assertCount(1, $sent);
        $this->assertFalse($sent[0]['allow_redirects']);
        $this->assertTrue($sent[0]['verify']);
        $this->assertSame('fn.example.com:443:93.184.216.34,[2606:4700:4700::1111]', $sent[0]['curl'][CURLOPT_RESOLVE][0]);
    }

    public function test_an_override_answering_an_internal_address_is_refused_at_request_time(): void
    {
        $sent = [];
        Http::fake(function ($request, array $options) use (&$sent) {
            $sent[] = $options;

            return Http::response('{}', 200);
        });

        foreach (['10.0.0.5', '::ffff:169.254.169.254', '64:ff9b::a9fe:a9fe', '2002:a9fe:a9fe::'] as $internal) {
            $this->dns['fn.example.com'] = [$internal];
            try {
                app(HttpWorkflowTransport::class)->send('https://fn.example.com/', '{}', [], true);
                $this->fail("{$internal} reached");
            } catch (TransportException $e) {
                $this->assertStringContainsString(__('workflows.endpoint_private'), $e->getMessage());
            }
        }
        $this->assertSame([], $sent);
    }

    public function test_the_function_url_and_allowed_private_endpoints_are_not_pinned(): void
    {
        $sent = [];
        Http::fake(function ($request, array $options) use (&$sent) {
            $sent[] = $options;

            return Http::response('{}', 200);
        });

        // the project's function URL (not an override): RunR's own ingress, called as before
        app(HttpWorkflowTransport::class)->send('https://orders.eu.runr.example/', '{}', []);

        config(['workflows.allow_private_endpoints' => true]);
        app(HttpWorkflowTransport::class)->send('http://10.0.0.5:8080/', '{}', [], true);

        $this->assertCount(2, $sent);
        foreach ($sent as $options) {
            $this->assertFalse($options['allow_redirects']);
            $this->assertArrayNotHasKey('curl', $options);
        }
    }

    /**
     * The project's function URL under its organization's own base domain (DNS the organization
     * controls, OutboundUrlGuard::organizationControlled()): checked and pinned like an override.
     */
    public function test_a_function_url_on_the_organizations_own_domain_is_guarded(): void
    {
        $this->app->instance(OutboundUrlGuard::class, new class(app(DnsResolver::class)) extends OutboundUrlGuard
        {
            public function organizationControlled(string $url): bool
            {
                return str_ends_with((string) parse_url($url, PHP_URL_HOST), '.acme.example');
            }
        });
        $sent = [];
        Http::fake(function ($request, array $options) use (&$sent) {
            $sent[] = $options;

            return Http::response('{}', 200);
        });

        $this->dns['orders.eu.acme.example'] = ['::ffff:169.254.169.254'];
        try {
            app(HttpWorkflowTransport::class)->send('https://orders.eu.acme.example/', '{}', []);
            $this->fail('the internal address was reached');
        } catch (TransportException $e) {
            $this->assertStringContainsString(__('workflows.endpoint_private'), $e->getMessage());
        }
        $this->assertSame([], $sent);

        $this->dns['orders.eu.acme.example'] = ['93.184.216.34'];
        app(HttpWorkflowTransport::class)->send('https://orders.eu.acme.example/', '{}', []);
        $this->assertSame('orders.eu.acme.example:443:93.184.216.34', $sent[0]['curl'][CURLOPT_RESOLVE][0]);
    }

    /** The engine marks the override: a run against an override that now resolves internally fails, nothing is sent. */
    public function test_the_engine_checks_the_override_before_every_run_request(): void
    {
        $this->dns['fn.test'] = ['93.184.216.34'];
        $workflow = $this->defineWorkflow('orders', fn (FakeStepTools $step) => $step->run('a', fn () => 'done'));
        $this->assertSame('https://fn.test/', WorkflowApp::forProject($this->project)->endpoint_url);

        $this->dns['fn.test'] = ['169.254.169.254'];
        $run = $this->startRun($workflow);
        $this->drain();

        $this->assertSame([], $this->fn->executeRequests());
        $this->assertSame('FunctionUnavailable', $run->fresh()->error['name']);
        $this->assertStringContainsString(__('workflows.endpoint_private'), $run->fresh()->error['message']);
    }
}
