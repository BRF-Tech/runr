<?php

namespace Tests\Feature\Triggers;

use App\Models\Project;
use App\Models\User;
use App\Services\KnativeDeployService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsTriggerFixtures;
use Tests\TestCase;

/**
 * Broker and external sources (docs/triggers.md#broker-and-external-sources): Kafka, NATS,
 * RabbitMQ, MQTT, Postgres CDC, S3 object events and inbound email. Each validates its config,
 * keeps its credentials write-only and renders them only into the gateway's config Secret.
 */
class BrokerSourcesTest extends TestCase
{
    use ActsWithRoles;
    use BuildsTriggerFixtures;
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->userWithRole('owner');
        $this->project = $this->triggerProject($this->triggerRegion(redis: false), 'http', 'ingest');
    }

    private function add(array $body)
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson("/api/projects/{$this->project->id}/triggers", $body);
    }

    /** gateway.json source settings of the trigger named $name. */
    private function sourceOf(string $name): array
    {
        $manifests = app(KnativeDeployService::class)->buildManifests($this->project->fresh(), $this->triggerVersion($this->project));
        $secret = $this->manifestNamed($manifests, 'Secret', 'ingest-qc-config');
        $this->assertNotNull($secret);
        $this->assertStringNotContainsString('super-secret', json_encode($this->manifestNamed($manifests, 'Deployment', 'ingest-qc')));
        $config = json_decode(base64_decode($secret['data']['gateway.json']), true);

        return collect($config['triggers'])->firstWhere('name', $name);
    }

    public function test_kafka_needs_brokers_and_a_sasl_password_and_defaults_its_group(): void
    {
        $this->add(['type' => 'kafka', 'config' => ['topic' => 'orders']])->assertUnprocessable()->assertJsonValidationErrors('config.brokers');
        $this->add(['type' => 'kafka', 'config' => ['brokers' => ['k1:9092'], 'topic' => 'orders', 'sasl' => ['mechanism' => 'scram-sha-512', 'username' => 'svc']]])
            ->assertUnprocessable()->assertJsonValidationErrors('secrets.sasl_password');

        $this->add(['type' => 'kafka', 'name' => 'orders', 'config' => [
            'brokers' => ['k1:9092', 'k2:9092'], 'topic' => 'orders', 'sasl' => ['mechanism' => 'scram-sha-512', 'username' => 'svc'],
            'tls' => ['enabled' => true], 'delivery' => ['concurrency' => 3],
        ], 'secrets' => ['sasl_password' => 'super-secret-kafka']])->assertCreated()->assertJsonPath('data.secrets', ['sasl_password']);

        $entry = $this->sourceOf('orders');
        $this->assertSame('kafka', $entry['kind']);
        $this->assertSame(3, $entry['concurrency']);
        $this->assertSame(['k1:9092', 'k2:9092'], $entry['source']['brokers']);
        $this->assertSame('runr.ingest.orders', $entry['source']['group_id']);
        $this->assertSame('latest', $entry['source']['start_from']);
        $this->assertSame(['mechanism' => 'scram-sha-512', 'username' => 'svc', 'password' => 'super-secret-kafka'], $entry['source']['sasl']);
        $this->assertTrue($entry['source']['tls']['enabled']);
    }

    public function test_nats_jetstream_gets_a_durable_and_core_nats_a_queue_group(): void
    {
        $this->add(['type' => 'nats', 'name' => 'js', 'config' => ['url' => 'nats://n:4222', 'subject' => 'orders.>', 'stream' => 'ORDERS', 'auth' => ['mode' => 'token']]])
            ->assertUnprocessable()->assertJsonValidationErrors('secrets.token');
        $this->add(['type' => 'nats', 'name' => 'js', 'config' => ['url' => 'nats://n:4222', 'subject' => 'orders.>', 'stream' => 'ORDERS', 'auth' => ['mode' => 'token']], 'secrets' => ['token' => 'super-secret-token']])->assertCreated();
        $this->add(['type' => 'nats', 'name' => 'core', 'config' => ['url' => 'nats://n:4222', 'subject' => 'events']])->assertCreated();

        $js = $this->sourceOf('js')['source'];
        $this->assertSame('runr-ingest-js', $js['durable']);
        $this->assertSame('new', $js['deliver']);
        $this->assertSame('super-secret-token', $js['auth']['token']);
        $this->assertArrayNotHasKey('queue_group', $js);

        $core = $this->sourceOf('core')['source'];
        $this->assertSame('runr.ingest.core', $core['queue_group']);
        $this->assertArrayNotHasKey('durable', $core);
    }

    public function test_rabbitmq_takes_its_url_from_the_secret(): void
    {
        $this->add(['type' => 'rabbitmq', 'config' => ['queue' => 'jobs']])->assertUnprocessable()->assertJsonValidationErrors('secrets.url');
        $this->add(['type' => 'rabbitmq', 'config' => ['queue' => 'jobs'], 'secrets' => ['url' => 'http://nope']])->assertUnprocessable()->assertJsonValidationErrors('secrets.url');
        $this->add(['type' => 'rabbitmq', 'config' => ['queue' => 'jobs', 'declare' => true, 'bindings' => [['exchange' => 'events', 'routing_key' => 'job.*']]], 'secrets' => ['url' => 'amqps://u:super-secret@mq:5671/prod']])
            ->assertCreated();

        $source = $this->sourceOf('rabbitmq')['source'];
        $this->assertSame('amqps://u:super-secret@mq:5671/prod', $source['url']);
        $this->assertSame([['exchange' => 'events', 'routing_key' => 'job.*']], $source['bindings']);
        $this->assertTrue($source['declare']);
    }

    public function test_mqtt_defaults_to_qos_1_with_a_persistent_session_and_a_short_client_id(): void
    {
        $this->add(['type' => 'mqtt', 'config' => ['broker' => 'ssl://mq:8883', 'topic' => 'sensors/+/temp', 'username' => 'dev'], 'secrets' => ['password' => 'super-secret-mqtt']])->assertCreated();

        $source = $this->sourceOf('mqtt')['source'];
        $this->assertSame(1, $source['qos']);
        $this->assertFalse($source['clean_session']);
        $this->assertMatchesRegularExpression('/^runr-[0-9a-f]{18}$/', $source['client_id']);
        $this->assertSame('super-secret-mqtt', $source['password']);
    }

    public function test_cdc_runs_one_delivery_at_a_time_with_a_slot_per_trigger(): void
    {
        $this->add(['type' => 'cdc', 'config' => ['publication' => 'runr_orders', 'create_publication' => true], 'secrets' => ['dsn' => 'postgres://r:super-secret@db/app']])
            ->assertUnprocessable()->assertJsonValidationErrors('config.tables');
        $this->add(['type' => 'cdc', 'name' => 'orders', 'config' => ['publication' => 'runr_orders', 'tables' => ['public.orders'], 'create_publication' => true, 'delivery' => ['concurrency' => 8]], 'secrets' => ['dsn' => 'postgres://r:super-secret@db/app']])
            ->assertCreated()->assertJsonPath('data.delivery.concurrency', 1);

        $entry = $this->sourceOf('orders');
        $this->assertSame(1, $entry['concurrency']);
        $this->assertSame('runr_ingest_orders', $entry['source']['slot']);
        $this->assertSame(['insert', 'update', 'delete'], $entry['source']['operations']);
        $this->assertSame('postgres://r:super-secret@db/app', $entry['source']['dsn']);
    }

    public function test_s3_needs_both_keys_and_watches_created_and_updated_by_default(): void
    {
        $this->add(['type' => 's3', 'config' => ['endpoint' => 's3.example.com', 'bucket' => 'uploads'], 'secrets' => ['access_key' => 'AK']])
            ->assertUnprocessable()->assertJsonValidationErrors('secrets.secret_key');
        $this->add(['type' => 's3', 'config' => ['endpoint' => 's3.example.com', 'bucket' => 'uploads', 'prefix' => 'in/'], 'secrets' => ['access_key' => 'AK', 'secret_key' => 'super-secret-s3']])->assertCreated();

        $source = $this->sourceOf('s3')['source'];
        $this->assertSame(['created', 'updated'], $source['events']);
        $this->assertSame('latest', $source['start_from']);
        $this->assertTrue($source['path_style']);
        $this->assertSame(['AK', 'super-secret-s3'], [$source['access_key'], $source['secret_key']]);
    }

    public function test_email_reads_imap_with_tls_by_default(): void
    {
        $this->add(['type' => 'email', 'config' => ['host' => 'imap.example.com', 'username' => 'inbox@example.com', 'after' => 'move']])
            ->assertUnprocessable()->assertJsonValidationErrors(['config.move_to']);
        $this->add(['type' => 'email', 'config' => ['host' => 'imap.example.com', 'username' => 'inbox@example.com'], 'secrets' => ['password' => 'super-secret-mail']])->assertCreated();

        $source = $this->sourceOf('email')['source'];
        $this->assertSame(['implicit', 993, 'INBOX', 'mark_seen'], [$source['tls'], $source['port'], $source['mailbox'], $source['after']]);
        $this->assertSame('super-secret-mail', $source['password']);
    }

    public function test_the_sources_describe_their_fields_for_the_console(): void
    {
        $types = collect($this->actingAs($this->owner, 'sanctum')->getJson('/api/trigger-types')->assertOk()->json('data'))->keyBy('type');

        foreach (['kafka', 'nats', 'rabbitmq', 'mqtt', 'cdc', 's3', 'email'] as $type) {
            $this->assertTrue($types[$type]['gateway'], $type);
            $this->assertFalse($types[$type]['requires_redis'], $type);
            $this->assertNotEmpty($types[$type]['fields'], $type);
            foreach ($types[$type]['fields'] as $field) {
                $this->assertNotSame($field['key'], $field['label'], "{$type}.{$field['key']} has a label");
            }
        }
        $this->assertTrue(collect($types['rabbitmq']['fields'])->firstWhere('key', 'url')['secret']);
    }
}
