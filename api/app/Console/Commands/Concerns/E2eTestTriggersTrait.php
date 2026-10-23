<?php

namespace App\Console\Commands\Concerns;

use App\Models\Project;
use App\Models\TriggerEvent;
use App\Services\KnativeDeployService;

/**
 * E2E of triggers (test:e2e --suite=triggers, runtime/lib/test-triggers.sh, docs/triggers.md):
 * one Python function with an HTTP trigger gets a webhook inbox, platform events about itself,
 * a schedule and — on a region with Redis — a topic subscription, then is deployed once:
 *  - trg-plan        the Knative Service stays public, no CronJob runs, the event gateway
 *                    <slug>-qc reads gateway.json from <slug>-qc-config
 *  - trg-webhook     a signed POST to the inbox URL is answered 200 and stored; the gateway claims
 *                    it from the API's inbox and the function answers 2xx: the event ends delivered
 *  - trg-webhook-dup the same delivery id again is answered "duplicate" and stored once
 *  - trg-dead-letter a body the function answers 500 ends failed after the retries; a replay
 *                    delivers it again (and fails again: replays = 1, failed)
 *  - trg-platform    the deploy's deployment.finished reaches the platform_event trigger, delivered
 *  - trg-schedule    the gateway delivers a schedule tick (/stats of the gateway, via the API proxy)
 *  - trg-topic       an event published through POST /api/topics/{t}/publish reaches the
 *                    subscription (gateway /stats) — Redis regions only
 */
trait E2eTestTriggersTrait
{
    private const TRG_SECRET = 'e2e-webhook-signing-secret';

    private const TRG_PYTHON = <<<'PY'
import json

def handler(event, context):
    body = event if isinstance(event, str) else json.dumps(event)
    if "please-fail" in body:
        return {"statusCode": 500, "body": {"error": "e2e failure"}}
    return {"statusCode": 200, "body": {"ok": True}}
PY;

    private function runTriggersSuite(string $regionId): array
    {
        $this->info('');
        $this->info('── Triggers (webhook inbox, dead letter + replay, platform events, schedule, topics) ──');

        $tests = [$this->makeTestEntry('trg-plan', [
            'service_type' => 'xaas',
            'runtime' => 'python',
            'runtime_version' => null,
            'trigger_type' => 'http',
            'code' => self::TRG_PYTHON,
        ], $regionId)];

        // First deploy: the function as an HTTP project; then the triggers, then deploy again
        $this->runTestsSequentially($tests);
        $test = &$tests[0];
        if ($test['steps']['ready'] !== true) {
            $test['steps']['verify'] = 'skipped';

            return $tests;
        }

        // The gateway pulls its inbox from the API: the API container's address on the Kind network
        $this->pointIngestAtApiContainer();
        $project = $test['projectId'];
        $hasRedis = (bool) $this->getE2eRegion()->has_redis;

        $add = fn (array $body) => $this->api()->post("/api/projects/{$project}/triggers", $body);
        $webhook = $add(['type' => 'webhook', 'name' => 'hook', 'config' => [
            'verify' => 'hmac', 'hmac' => ['header' => 'X-E2E-Signature', 'prefix' => 'sha256='],
            'delivery_id_header' => 'X-E2E-Delivery', 'delivery' => ['max_retries' => 1, 'retry_backoff_seconds' => 1],
        ], 'secrets' => ['signing_secret' => self::TRG_SECRET]]);
        $platform = $add(['type' => 'platform_event', 'name' => 'deploys', 'config' => ['events' => ['deployment.finished'], 'projects' => [$project]]]);
        $schedule = $add(['type' => 'schedule', 'name' => 'tick', 'config' => ['cron' => '* * * * *']]);

        $topicName = 'e2e-orders-'.substr(md5((string) microtime(true)), 0, 6);
        $topic = null;
        if ($hasRedis) {
            $created = $this->api()->post('/api/topics', ['name' => $topicName, 'knative_region_id' => $regionId]);
            $topic = $created->successful() ? $add(['type' => 'topic', 'name' => 'orders', 'config' => ['topic' => $topicName, 'start_from' => 'latest']]) : $created;
        }

        foreach (['webhook' => $webhook, 'platform_event' => $platform, 'schedule' => $schedule, 'topic' => $topic] as $type => $response) {
            if ($response !== null && ! $response->successful()) {
                $test['steps']['verify'] = "adding the {$type} trigger failed: {$response->status()} ".mb_substr($response->body(), 0, 200);

                return $tests;
            }
        }

        if (! $this->deploySingleTest($test)) {
            return $tests;
        }
        sleep(5);
        $this->waitSingleReady($test);
        if ($test['steps']['ready'] !== true) {
            return $tests;
        }

        $test['steps']['verify'] = $this->verifyTriggerPlan($test);
        $url = (string) $webhook->json('data.url');
        $path = (string) parse_url($url, PHP_URL_PATH);

        $entries = [$test];
        $entries[] = $this->trgEntry('trg-webhook', $test, fn () => $this->verifyWebhookDelivered($project, $path));
        $entries[] = $this->trgEntry('trg-webhook-dup', $test, fn () => $this->verifyWebhookDuplicate($project, $path));
        $entries[] = $this->trgEntry('trg-dead-letter', $test, fn () => $this->verifyDeadLetterAndReplay($project, $path));
        $entries[] = $this->trgEntry('trg-platform', $test, fn () => $this->verifyPlatformEvent($project));
        $entries[] = $this->trgEntry('trg-schedule', $test, fn () => $this->verifyGatewayDelivered($test, 'tick', 150));
        if ($hasRedis) {
            $entries[] = $this->trgEntry('trg-topic', $test, function () use ($test, $topicName) {
                $published = $this->api()->post("/api/topics/{$topicName}/publish", ['data' => ['order' => 17], 'type' => 'e2e.order.created']);
                if ($published->status() !== 202) {
                    return 'publish answered '.$published->status().': '.mb_substr($published->body(), 0, 200);
                }

                return $this->verifyGatewayDelivered($test, 'orders', 90);
            });
        }

        foreach ($entries as $entry) {
            $state = $entry['steps']['verify'] === true ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $this->line("  {$state} {$entry['name']}: ".($entry['steps']['verify'] === true ? 'verified' : $entry['steps']['verify']));
        }

        return $entries;
    }

    private function trgEntry(string $name, array $test, callable $verify): array
    {
        $entry = $this->makeTestEntry($name, $test['config'], $test['regionId']);
        $entry['projectId'] = $test['projectId'];
        $entry['slug'] = $test['slug'];
        $entry['steps'] = ['version' => true, 'deploy' => true, 'ready' => true, 'verify' => $verify()];

        return $entry;
    }

    /** Public service, no CronJob, the gateway from its config Secret. */
    private function verifyTriggerPlan(array $test): bool|string
    {
        $k8s = $this->getE2eKubernetesClient();
        $ns = $this->getE2eNamespace();
        $slug = $test['slug'];

        $service = $k8s->getResourceStatus('serving.knative.dev/v1', 'Service', $slug, $ns);
        if (($service['metadata']['labels']['networking.knative.dev/visibility'] ?? null) === 'cluster-local') {
            return 'the Knative Service is cluster-local next to an HTTP trigger';
        }
        if ($k8s->getResourceStatus('batch/v1', 'CronJob', "{$slug}-trigger", $ns)) {
            return 'a CronJob runs the schedule next to an HTTP trigger';
        }
        $gateway = $k8s->getResourceStatus('apps/v1', 'Deployment', "{$slug}-qc", $ns);
        $volumes = $gateway['spec']['template']['spec']['volumes'] ?? [];
        if (($volumes[0]['secret']['secretName'] ?? null) !== "{$slug}-qc-config") {
            return 'the gateway does not mount <slug>-qc-config';
        }
        if (! $this->waitGatewayReady($slug)) {
            return 'the gateway Deployment is not ready';
        }

        return true;
    }

    private function waitGatewayReady(string $slug, int $timeout = 120): bool
    {
        $deadline = time() + $timeout;
        while (time() < $deadline) {
            $gateway = $this->getE2eKubernetesClient()->getResourceStatus('apps/v1', 'Deployment', "{$slug}-qc", $this->getE2eNamespace());
            if ((int) ($gateway['status']['readyReplicas'] ?? 0) >= 1) {
                return true;
            }
            sleep(3);
        }

        return false;
    }

    private function postWebhook(string $path, string $body, string $deliveryId)
    {
        return $this->api()->withHeaders([
            'X-E2E-Signature' => 'sha256='.hash_hmac('sha256', $body, self::TRG_SECRET),
            'X-E2E-Delivery' => $deliveryId,
        ])->withBody($body, 'application/json')->post($path);
    }

    private function verifyWebhookDelivered(string $project, string $path): bool|string
    {
        $unsigned = $this->api()->withBody('{"order":1}', 'application/json')->post($path);
        if ($unsigned->status() !== 401) {
            return "an unsigned request answered {$unsigned->status()} (401 expected)";
        }

        $response = $this->postWebhook($path, '{"order":17}', 'e2e-delivery-1');
        if ($response->status() !== 200 || $response->json('status') !== 'stored') {
            return "the inbox answered {$response->status()}: ".mb_substr($response->body(), 0, 200);
        }

        return $this->waitEventStatus($project, (string) $response->json('id'), TriggerEvent::DELIVERED, 90);
    }

    private function verifyWebhookDuplicate(string $project, string $path): bool|string
    {
        $again = $this->postWebhook($path, '{"order":17}', 'e2e-delivery-1');
        if ($again->json('status') !== 'duplicate') {
            return 'a repeated delivery id answered '.$again->status().' '.mb_substr($again->body(), 0, 120);
        }

        $count = $this->inProject($project, fn () => TriggerEvent::query()->where('delivery_id', 'e2e-delivery-1')->count());

        return $count === 1 ? true : "the delivery id is stored {$count} times";
    }

    private function verifyDeadLetterAndReplay(string $project, string $path): bool|string
    {
        $response = $this->postWebhook($path, '{"note":"please-fail"}', 'e2e-delivery-fail');
        $id = (string) $response->json('id');
        if (($failed = $this->waitEventStatus($project, $id, TriggerEvent::FAILED, 120)) !== true) {
            return $failed;
        }

        $replay = $this->api()->post("/api/projects/{$project}/triggers/hook/events/{$id}/replay");
        if (! $replay->successful() || $replay->json('data.replays') !== 1) {
            return 'replay answered '.$replay->status();
        }

        return $this->waitEventStatus($project, $id, TriggerEvent::FAILED, 120);
    }

    private function verifyPlatformEvent(string $project): bool|string
    {
        $deadline = time() + 120;
        while (time() < $deadline) {
            $event = $this->inProject($project, fn () => TriggerEvent::query()
                ->where('project_id', $project)
                ->where('event_type', 'dev.runr.deployment.finished')
                ->latest('received_at')
                ->first());
            if ($event?->status === TriggerEvent::DELIVERED) {
                return true;
            }
            sleep(3);
        }

        return 'no delivered deployment.finished event (last: '.($event->status ?? 'none').')';
    }

    /** The gateway's /stats (through the Kubernetes API proxy): the trigger delivered at least once. */
    private function verifyGatewayDelivered(array $test, string $trigger, int $timeout): bool|string
    {
        $k8s = $this->getE2eKubernetesClient();
        $names = app(KnativeDeployService::class)->workloadNames(Project::query()->findOrFail($test['projectId']));
        $deadline = time() + $timeout;
        $last = null;

        while (time() < $deadline) {
            $stats = $k8s->serviceProxyGet($this->getE2eNamespace(), $names['queueConsumer'], 8081, 'stats');
            foreach ((array) ($stats?->json('triggers') ?? []) as $entry) {
                if (($entry['name'] ?? null) === $trigger) {
                    $last = $entry;
                    if ((int) ($entry['delivered'] ?? 0) >= 1) {
                        return true;
                    }
                }
            }
            sleep(5);
        }

        return "the gateway delivered nothing for {$trigger} in {$timeout}s (stats: ".json_encode($last).')';
    }

    private function waitEventStatus(string $project, string $eventId, string $status, int $timeout): bool|string
    {
        $deadline = time() + $timeout;
        $event = null;
        while (time() < $deadline) {
            $event = $this->inProject($project, fn () => TriggerEvent::query()->find($eventId));
            if ($event?->status === $status) {
                return true;
            }
            sleep(2);
        }

        return "event {$eventId} is ".($event->status ?? 'missing')." after {$timeout}s ({$status} expected".($event?->last_error ? ", last error: {$event->last_error}" : '').')';
    }

    /** The project's rows live in its organization's schema: the e2e user's (central in single-tenant). */
    private function inProject(string $projectId, callable $fn): mixed
    {
        return $fn();
    }
}
