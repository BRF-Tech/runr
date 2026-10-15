<?php

namespace Tests\Support\Workflows;

use App\Workflows\Engine\Signature;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A workflow function for engine tests: a minimal PHP implementation of the SDK side of the
 * protocol (docs/workflows-protocol.md) answering the engine's signed requests through
 * Http::fake(). Handlers get FakeStepTools and the trigger event:
 *
 *   $fn->define('orders', function (FakeStepTools $step, object $event) {
 *       $a = $step->run('a', fn () => 1);
 *       $step->sleep('nap', '1h');
 *       return $a + 1;
 *   });
 */
class FakeWorkflowFunction
{
    /** @var array<string, array{handler: Closure, definition: array}> */
    public array $workflows = [];

    /** @var list<object> decoded requests, in order */
    public array $requests = [];

    /** @var array<string, int> how often each step callback ran, by step key */
    public array $executions = [];

    /** Force an HTTP status for the next N requests (unavailable function). */
    public int $failNextWith = 0;

    public int $failNextCount = 0;

    /** Answer this raw body instead (protocol error tests). */
    public ?string $rawResponse = null;

    public array $sdk = ['name' => 'fake-php', 'language' => 'php', 'version' => '0.0.0'];

    public function __construct(public string $signingKey) {}

    public function define(string $name, Closure $handler, array $definition = []): self
    {
        $this->workflows[$name] = ['handler' => $handler, 'definition' => ['name' => $name] + $definition];

        return $this;
    }

    public function executeRequests(): array
    {
        return array_values(array_filter($this->requests, fn ($r) => ($r->type ?? null) === 'execute'));
    }

    public function lastRequest(): ?object
    {
        return $this->requests ? $this->requests[array_key_last($this->requests)] : null;
    }

    public function handle(Request $request)
    {
        if ($this->failNextCount > 0) {
            $this->failNextCount--;

            return Http::response('<html>upstream unavailable</html>', $this->failNextWith ?: 503);
        }

        $envelope = json_decode($request->body(), false);
        $raw = is_object($envelope) ? ($envelope->request ?? null) : null;
        $header = $request->header('X-RunR-Signature')[0] ?? ($envelope->signature ?? '');

        if (! is_string($raw) || ! Signature::verify($raw, (string) $header, [$this->signingKey])) {
            return Http::response(['error' => 'runr-workflows: invalid signature'], 401);
        }

        $req = json_decode($raw, false);
        $this->requests[] = $req;

        if ($this->rawResponse !== null) {
            return Http::response($this->rawResponse, 200, ['Content-Type' => 'application/json']);
        }

        if ($req->type === 'introspect') {
            return Http::response([
                'runr_workflow' => 'v1',
                'sdk' => $this->sdk,
                'workflows' => array_values(array_map(fn ($w) => $w['definition'], $this->workflows)),
            ]);
        }

        $workflow = $this->workflows[$req->workflow] ?? null;
        if (! $workflow) {
            return Http::response(['runr_workflow' => 'v1', 'ops' => [[
                'op' => 'run_failed', 'error' => ['name' => 'WorkflowNotFound', 'message' => "no workflow {$req->workflow}"], 'retriable' => false,
            ]]]);
        }

        $tools = new FakeStepTools($req, $this);

        try {
            $output = ($workflow['handler'])($tools, $req->event, $req->run);
            $op = ['op' => 'run_completed', 'output' => $output];
        } catch (FakeInterrupt $i) {
            $op = $i->op;
        } catch (FakeStepFailed $e) {
            $op = ['op' => 'run_failed', 'error' => ['name' => 'StepError', 'message' => $e->getMessage()], 'retriable' => false];
        } catch (FakeNonRetriable $e) {
            $op = ['op' => 'run_failed', 'error' => ['name' => 'NonRetriableError', 'message' => $e->getMessage()], 'retriable' => false];
        } catch (Throwable $e) {
            $op = ['op' => 'run_failed', 'error' => ['name' => class_basename($e), 'message' => $e->getMessage()], 'retriable' => true];
        }

        return Http::response(['runr_workflow' => 'v1', 'ops' => [$op]]);
    }
}
