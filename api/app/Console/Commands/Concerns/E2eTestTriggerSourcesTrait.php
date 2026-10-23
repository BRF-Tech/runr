<?php

namespace App\Console\Commands\Concerns;

use App\Jobs\DropCdcSlotsJob;
use App\Models\Project;
use App\Services\GitDeploy\TenantContext;
use App\Services\InternalNetwork\InternalNetwork;
use App\Triggers\Cleanup\CdcSlotCleanup;
use App\Triggers\Types\Sources\CdcTrigger;
use Illuminate\Support\Facades\Bus;

/**
 * E2E of the broker and external trigger sources (test:e2e --suite=trigger-sources,
 * runtime/lib/test-trigger-sources.sh, docs/triggers.md#broker-and-external-sources). One Python
 * function gets a trigger of every source; each system runs as a throwaway pod + Service in the
 * project namespace (images of the reqs mirror), the function is redeployed once, and each event
 * must reach it through the event gateway (gateway /stats via the API proxy):
 *
 *  - src-object-store the region's object store (managed, through the API) and an object binding
 *                     whose bucket and keys an s3 trigger watches; the binding is attached to the
 *                     project (the data namespace admits the pods of attached projects only)
 *  - src-s3           an object written into the bucket (rclone pod) → dev.runr.s3.object.created
 *  - src-kafka        Apache Kafka (KRaft, one node), topic e2e-orders made first, a consumer group
 *                     from the earliest offset; a record from kafka-console-producer
 *  - src-nats         NATS with JetStream: the stream E2E (e2e.>) made over the client protocol, a
 *                     durable pull consumer from the first message; a message published to e2e.hello
 *  - src-rabbitmq     RabbitMQ (management API): the durable queue e2e-orders, a message published
 *                     to it through the default exchange; manual acks
 *  - src-mqtt         Mosquitto: a retained QoS 1 message on e2e/hello, an mqtt trigger on e2e/#
 *  - src-cdc          Postgres with wal_level=logical: the trigger creates the publication and the
 *                     slot; an inserted row → dev.runr.cdc.insert
 *  - src-email        GreenMail: a mail sent over SMTP is read from the IMAP mailbox
 *  - src-email-move   a second mailbox whose trigger moves what it delivered (after: move): the
 *                     mail ends up in the folder Processed and INBOX is empty (IMAP MOVE, or COPY +
 *                     EXPUNGE where the server does not list MOVE)
 *  - src-schedule-tz  a schedule in Pacific/Chatham (UTC+12:45 / +13:45) through the gateway: the
 *                     zone reaches gateway.json and the gateway ticks with it
 *  - src-cdc-cleanup  the cdc trigger removed (the API answers its cleanup), the function redeployed
 *                     without it, DropCdcSlotsJob run: the slot and the publication are gone from
 *                     the database (the gateway image in cdc-cleanup mode, in the region)
 *
 * --mqtt-image (RUNR_E2E_MQTT_IMAGE) still overrides the Mosquitto image. The function and the
 * gateway checks are the triggers suite's (E2eTestTriggersTrait).
 */
trait E2eTestTriggerSourcesTrait
{
    private const SRC_RCLONE_IMAGE = 'ghcr.io/brf-tech/runr/reqs/rclone:1.68.2';

    private const SRC_KAFKA_IMAGE = 'ghcr.io/brf-tech/runr/reqs/kafka:3.9.1';

    private const SRC_NATS_IMAGE = 'ghcr.io/brf-tech/runr/reqs/nats:2.11-alpine';

    private const SRC_RABBITMQ_IMAGE = 'ghcr.io/brf-tech/runr/reqs/rabbitmq:4.1-management-alpine';

    private const SRC_MQTT_IMAGE = 'ghcr.io/brf-tech/runr/reqs/eclipse-mosquitto:2.0.20';

    private const SRC_POSTGRES_IMAGE = 'ghcr.io/brf-tech/runr/reqs/postgres:17-alpine';

    private const SRC_GREENMAIL_IMAGE = 'ghcr.io/brf-tech/runr/reqs/greenmail:2.1.3';

    private const SRC_CURL_IMAGE = 'ghcr.io/brf-tech/runr/reqs/curl:latest';

    /** Password of the throwaway brokers (RabbitMQ, Postgres, the mailbox). */
    private const SRC_SECRET = 'e2e-src-secret';

    private const SRC_PUBLICATION = 'runr_e2e_orders';

    /** @var list<string> broker pods / Services started by this run */
    private array $srcBrokers = [];

    private function runTriggerSourcesSuite(string $regionId): array
    {
        $this->info('');
        $this->info('── Trigger sources (S3, Kafka, NATS, RabbitMQ, MQTT, Postgres CDC, IMAP, schedule time zones) ──');

        $tests = [$this->makeTestEntry('src-function', [
            'service_type' => 'xaas',
            'runtime' => 'python',
            'runtime_version' => null,
            'trigger_type' => 'http',
            'code' => self::TRG_PYTHON,
        ], $regionId)];
        $this->runTestsSequentially($tests);
        $test = &$tests[0];
        if ($test['steps']['ready'] !== true) {
            $test['steps']['verify'] = 'skipped';

            return $tests;
        }
        $test['steps']['verify'] = true;
        $project = $test['projectId'];

        // S3: the region's managed object store and a bucket of its own
        $store = $this->trgEntry('src-object-store', $test, function () use ($regionId) {
            $installed = $this->installDataService($regionId, 'object', ['mode' => 'managed', 'storage' => '1Gi']);
            if ($installed !== true) {
                return $installed;
            }

            return $this->createBinding($regionId, 'e2e-src-files', ['type' => 'object', 'settings' => ['quota_gb' => 1]]);
        });
        $entries = [$test, $store];

        $s3 = null;
        if ($store['steps']['verify'] === true) {
            // The data namespace admits only the pods of the projects a binding is attached to
            // (BindingNetworkPolicies): the bucket's binding is attached, so the project's event
            // gateway reaches it
            $attached = $this->api()->post("/api/projects/{$project}/bindings", ['binding_id' => $this->e2eBindings['e2e-src-files'], 'env_prefix' => 'FILES_']);
            if ($attached->status() !== 201) {
                $s3 = 'attaching the binding of the bucket failed: '.$attached->status().' '.mb_substr($attached->body(), 0, 200);
            }
        }
        if ($store['steps']['verify'] === true && $s3 === null) {
            $s3 = $this->api()->get("/api/bindings/{$this->e2eBindings['e2e-src-files']}/credentials")->json('data.env') ?? [];
            $added = $this->addSourceTrigger($project, ['type' => 's3', 'name' => 's3', 'config' => [
                'endpoint' => $s3['S3_ENDPOINT'] ?? '',
                'secure' => str_starts_with((string) ($s3['S3_ENDPOINT'] ?? ''), 'https://'),
                'region' => $s3['S3_REGION'] ?? null,
                'bucket' => $s3['S3_BUCKET'] ?? '',
                'path_style' => ($s3['S3_FORCE_PATH_STYLE'] ?? 'true') === 'true',
                'poll_interval' => '5s',
                'events' => ['created'],
            ], 'secrets' => ['access_key' => $s3['S3_ACCESS_KEY_ID'] ?? '', 'secret_key' => $s3['S3_SECRET_ACCESS_KEY'] ?? '']]);
            if ($added !== true) {
                $s3 = $added;
            }
        }

        // The brokers: each starts, gets what its trigger reads (topic, stream, queue, table), and
        // the trigger is added; all of them reach the cluster with one redeploy
        $slot = CdcTrigger::defaultSlot((string) $test['slug'], 'cdc');
        $ready = [
            'kafka' => $this->setUpKafka($project),
            'nats' => $this->setUpNats($project),
            'rabbitmq' => $this->setUpRabbitMq($project),
            'mqtt' => $this->setUpMqtt($project),
            'cdc' => $this->setUpCdc($project),
            'email' => $this->setUpEmail($project),
            'email-move' => $this->setUpEmailMove($project),
            'tz' => $this->addSourceTrigger($project, ['type' => 'schedule', 'name' => 'tz', 'config' => ['cron' => '* * * * *', 'timezone' => 'Pacific/Chatham']]),
        ];

        if (! $this->deploySingleTest($test)) {
            $this->deleteSourceBrokers();

            return $entries;
        }
        sleep(5);
        $this->waitSingleReady($test);
        if ($test['steps']['ready'] !== true || ! $this->waitGatewayReady($test['slug'])) {
            $test['steps']['verify'] = 'the redeploy with the source triggers did not become ready';
            $this->deleteSourceBrokers();

            return $entries;
        }

        if ($store['steps']['verify'] === true) {
            $entries[] = $this->trgEntry('src-s3', $test, function () use ($test, $s3) {
                if (! is_array($s3)) {
                    return $s3;
                }
                // the first listing is the baseline: give it a poll before the object appears
                sleep(10);
                if (($written = $this->writeObject($s3, 'e2e/hello.txt', 'hello from e2e', $test['projectId'])) !== true) {
                    return $written;
                }

                return $this->verifyGatewayDelivered($test, 's3', 90);
            });
        }

        $feeds = [
            'kafka' => fn () => $this->produceKafka('{"hello":"kafka"}'),
            'nats' => fn () => $this->publishNats('e2e.hello', '{"hello":"nats"}'),
            'rabbitmq' => fn () => $this->publishRabbitMq('{"hello":"rabbitmq"}'),
            'mqtt' => fn () => $this->publishMqtt($this->mqttImage(), 'e2e/hello', '{"hello":"mqtt"}'),
            'cdc' => fn () => $this->insertRow($slot),
            'email' => fn () => $this->sendMail('e2e hello', 'hello from e2e'),
            'email-move' => fn () => $this->sendMail('e2e move', 'move me', 'mover@e2e.test'),
        ];
        // what each source does with a delivered message beyond the delivery
        $after = ['email-move' => fn () => $this->verifyMailMoved()];
        foreach ($feeds as $name => $feed) {
            $entries[] = $this->trgEntry("src-{$name}", $test, function () use ($test, $ready, $name, $feed, $after) {
                if ($ready[$name] !== true) {
                    return $ready[$name];
                }
                if (($fed = $feed()) !== true) {
                    return $fed;
                }
                if (($delivered = $this->verifyGatewayDelivered($test, $name, 120)) !== true) {
                    return $delivered;
                }

                return isset($after[$name]) ? $after[$name]() : true;
            });
        }

        $entries[] = $this->trgEntry('src-schedule-tz', $test, function () use ($test, $ready) {
            if ($ready['tz'] !== true) {
                return $ready['tz'];
            }
            $secret = $this->getE2eKubernetesClient()->getResourceStatus('v1', 'Secret', $test['slug'].'-qc-config', $this->getE2eNamespace());
            $config = json_decode((string) base64_decode((string) ($secret['data']['gateway.json'] ?? '')), true);
            $entry = collect($config['triggers'] ?? [])->firstWhere('name', 'tz');
            if (($entry['source']['timezone'] ?? null) !== 'Pacific/Chatham') {
                return 'gateway.json does not carry the schedule\'s zone: '.json_encode($entry['source'] ?? null);
            }

            // a tick every minute, evaluated in the zone (the gateway's zone database)
            return $this->verifyGatewayDelivered($test, 'tz', 100);
        });

        $cdcDelivered = collect($entries)->firstWhere('name', 'src-cdc')['steps']['verify'] ?? null;
        $entries[] = $this->trgEntry('src-cdc-cleanup', $test, fn () => $cdcDelivered === true
            ? $this->verifyCdcCleanup($test, $slot)
            : 'skipped: the cdc trigger did not deliver');

        $this->deleteSourceBrokers();

        foreach ($entries as $entry) {
            $state = $entry['steps']['verify'] === true ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $this->line("  {$state} {$entry['name']}: ".($entry['steps']['verify'] === true ? 'verified' : $entry['steps']['verify']));
        }

        return $entries;
    }

    private function addSourceTrigger(string $project, array $body): bool|string
    {
        $added = $this->api()->post("/api/projects/{$project}/triggers", $body);

        return $added->successful() ? true : "adding the {$body['type']} trigger failed: ".$added->status().' '.mb_substr($added->body(), 0, 300);
    }

    private function srcHost(string $name): string
    {
        return "e2e-{$name}.".$this->getE2eNamespace().'.svc.cluster.local';
    }

    // ── Kafka ─────────────────────────────────────────────────────────────────────────────

    private function setUpKafka(string $project): bool|string
    {
        $host = $this->srcHost('kafka');
        $started = $this->startSourceBroker('kafka', self::SRC_KAFKA_IMAGE, [9092], [
            // one KRaft node; the address clients get back is the Service's
            'CLUSTER_ID' => 'MkU3OEVBNTcwNTJENDM2Qk',
            'KAFKA_NODE_ID' => '1',
            'KAFKA_PROCESS_ROLES' => 'broker,controller',
            'KAFKA_LISTENERS' => 'PLAINTEXT://:9092,CONTROLLER://:9093',
            'KAFKA_ADVERTISED_LISTENERS' => "PLAINTEXT://{$host}:9092",
            'KAFKA_CONTROLLER_LISTENER_NAMES' => 'CONTROLLER',
            'KAFKA_LISTENER_SECURITY_PROTOCOL_MAP' => 'CONTROLLER:PLAINTEXT,PLAINTEXT:PLAINTEXT',
            'KAFKA_CONTROLLER_QUORUM_VOTERS' => '1@localhost:9093',
            'KAFKA_OFFSETS_TOPIC_REPLICATION_FACTOR' => '1',
            'KAFKA_TRANSACTION_STATE_LOG_REPLICATION_FACTOR' => '1',
            'KAFKA_TRANSACTION_STATE_LOG_MIN_ISR' => '1',
            'KAFKA_GROUP_INITIAL_REBALANCE_DELAY_MS' => '0',
            'KAFKA_NUM_PARTITIONS' => '1',
            'KAFKA_HEAP_OPTS' => '-Xms256m -Xmx512m',
        ]);
        if ($started !== true) {
            return $started;
        }

        // the topic exists before the consumer group joins
        $created = $this->runOncePod($this->srcPodName('kafka-topic'), self::SRC_KAFKA_IMAGE, ['sh', '-c', <<<'SH'
for i in $(seq 1 40); do
  /opt/kafka/bin/kafka-topics.sh --bootstrap-server "$BROKER" --create --if-not-exists --topic e2e-orders --partitions 1 --replication-factor 1 && exit 0
  sleep 3
done
exit 1
SH], ['BROKER' => "{$host}:9092", 'KAFKA_HEAP_OPTS' => '-Xmx128m'], timeout: 180);
        if ($created !== true) {
            return 'creating the Kafka topic failed: '.$created;
        }

        return $this->addSourceTrigger($project, ['type' => 'kafka', 'name' => 'kafka', 'config' => [
            'brokers' => ["{$host}:9092"],
            'topic' => 'e2e-orders',
            'start_from' => 'earliest',
        ]]);
    }

    private function produceKafka(string $value): bool|string
    {
        return $this->runOncePod($this->srcPodName('kafka-produce'), self::SRC_KAFKA_IMAGE, ['sh', '-c',
            'printf "%s\n" "$VALUE" | /opt/kafka/bin/kafka-console-producer.sh --bootstrap-server "$BROKER" --topic e2e-orders'],
            ['BROKER' => $this->srcHost('kafka').':9092', 'VALUE' => $value, 'KAFKA_HEAP_OPTS' => '-Xmx128m'], timeout: 180);
    }

    // ── NATS (JetStream) ──────────────────────────────────────────────────────────────────

    private function setUpNats(string $project): bool|string
    {
        $started = $this->startSourceBroker('nats', self::SRC_NATS_IMAGE, [4222], [], command: ['nats-server', '-js', '-p', '4222']);
        if ($started !== true) {
            return $started;
        }

        // The stream the durable consumer reads (the gateway creates the consumer, not the
        // stream): a JetStream API request over the client protocol, from the image's busybox nc
        $created = $this->runOncePod($this->srcPodName('nats-stream'), self::SRC_NATS_IMAGE, ['sh', '-c', <<<'SH'
for i in $(seq 1 30); do
  out=$( { printf 'CONNECT {"verbose":false}\r\nSUB _INBOX.e2e 1\r\nPUB $JS.API.STREAM.CREATE.E2E _INBOX.e2e %d\r\n%s\r\nPING\r\n' "${#BODY}" "$BODY"; sleep 3; } | nc "$HOST" 4222 )
  echo "$out"
  case "$out" in *stream_create_response*) exit 0 ;; esac
  sleep 3
done
exit 1
SH], ['HOST' => $this->srcHost('nats'), 'BODY' => '{"name":"E2E","subjects":["e2e.>"]}'], timeout: 150);
        if ($created !== true) {
            return 'creating the JetStream stream failed: '.$created;
        }

        return $this->addSourceTrigger($project, ['type' => 'nats', 'name' => 'nats', 'config' => [
            'url' => 'nats://'.$this->srcHost('nats').':4222',
            'subject' => 'e2e.>',
            'stream' => 'E2E',
            'deliver' => 'all',
        ]]);
    }

    private function publishNats(string $subject, string $payload): bool|string
    {
        return $this->runOncePod($this->srcPodName('nats-pub'), self::SRC_NATS_IMAGE, ['sh', '-c',
            '{ printf "CONNECT {\"verbose\":false}\r\nPUB %s %d\r\n%s\r\nPING\r\n" "$SUBJECT" "${#PAYLOAD}" "$PAYLOAD"; sleep 2; } | nc "$HOST" 4222 | grep -q PONG'],
            ['HOST' => $this->srcHost('nats'), 'SUBJECT' => $subject, 'PAYLOAD' => $payload]);
    }

    // ── RabbitMQ ──────────────────────────────────────────────────────────────────────────

    private function setUpRabbitMq(string $project): bool|string
    {
        $started = $this->startSourceBroker('rabbitmq', self::SRC_RABBITMQ_IMAGE, [5672, 15672], [
            // guest may only connect from localhost
            'RABBITMQ_DEFAULT_USER' => 'runr',
            'RABBITMQ_DEFAULT_PASS' => self::SRC_SECRET,
        ]);
        if ($started !== true) {
            return $started;
        }

        // the durable queue, as the trigger declares it (passive declare finds it)
        $declared = $this->runOncePod($this->srcPodName('rabbitmq-queue'), self::SRC_CURL_IMAGE, ['sh', '-c', <<<'SH'
for i in $(seq 1 40); do
  curl -fsS -u "runr:$PASS" -X PUT -H 'content-type: application/json' -d '{"durable":true}' "http://$HOST:15672/api/queues/%2F/e2e-orders" && exit 0
  sleep 3
done
exit 1
SH], ['HOST' => $this->srcHost('rabbitmq'), 'PASS' => self::SRC_SECRET], timeout: 180);
        if ($declared !== true) {
            return 'declaring the RabbitMQ queue failed: '.$declared;
        }

        return $this->addSourceTrigger($project, ['type' => 'rabbitmq', 'name' => 'rabbitmq',
            'config' => ['queue' => 'e2e-orders', 'declare' => true],
            'secrets' => ['url' => 'amqp://runr:'.self::SRC_SECRET.'@'.$this->srcHost('rabbitmq').':5672/'],
        ]);
    }

    private function publishRabbitMq(string $payload): bool|string
    {
        $body = json_encode(['properties' => ['content_type' => 'application/json'], 'routing_key' => 'e2e-orders', 'payload' => $payload, 'payload_encoding' => 'string']);

        return $this->runOncePod($this->srcPodName('rabbitmq-pub'), self::SRC_CURL_IMAGE, ['sh', '-c',
            'curl -fsS -u "runr:$PASS" -H "content-type: application/json" -d "$BODY" "http://$HOST:15672/api/exchanges/%2F/amq.default/publish" | tee /dev/stderr | grep -q "\"routed\":true"'],
            ['HOST' => $this->srcHost('rabbitmq'), 'PASS' => self::SRC_SECRET, 'BODY' => (string) $body]);
    }

    // ── MQTT ──────────────────────────────────────────────────────────────────────────────

    private function mqttImage(): string
    {
        return (string) ($this->option('mqtt-image') ?: (getenv('RUNR_E2E_MQTT_IMAGE') ?: self::SRC_MQTT_IMAGE));
    }

    private function setUpMqtt(string $project): bool|string
    {
        $started = $this->startSourceBroker('mqtt', $this->mqttImage(), [1883], [], command: ['mosquitto', '-c', '/mosquitto-no-auth.conf']);
        if ($started !== true) {
            return $started;
        }

        return $this->addSourceTrigger($project, ['type' => 'mqtt', 'name' => 'mqtt', 'config' => [
            'broker' => 'tcp://'.$this->srcHost('mqtt').':1883',
            'topic' => 'e2e/#',
            'qos' => 1,
        ]]);
    }

    /** A retained QoS 1 message: the gateway gets it also when it subscribes after the publish. */
    private function publishMqtt(string $image, string $topic, string $payload): bool|string
    {
        return $this->runOncePod($this->srcPodName('mqtt-pub'), $image, ['sh', '-c', <<<'SH'
for i in $(seq 1 20); do
  mosquitto_pub -h "$HOST" -t "$TOPIC" -q 1 -r -m "$PAYLOAD" && exit 0
  sleep 3
done
exit 1
SH], ['HOST' => $this->srcHost('mqtt'), 'TOPIC' => $topic, 'PAYLOAD' => $payload]);
    }

    // ── Postgres CDC ──────────────────────────────────────────────────────────────────────

    private function pgDsn(): string
    {
        return 'postgresql://postgres:'.self::SRC_SECRET.'@'.$this->srcHost('postgres').':5432/shop?sslmode=disable';
    }

    private function setUpCdc(string $project): bool|string
    {
        $started = $this->startSourceBroker('postgres', self::SRC_POSTGRES_IMAGE, [5432], [
            'POSTGRES_PASSWORD' => self::SRC_SECRET,
            'POSTGRES_DB' => 'shop',
        ], args: ['postgres', '-c', 'wal_level=logical', '-c', 'max_replication_slots=10', '-c', 'max_wal_senders=10']);
        if ($started !== true) {
            return $started;
        }

        $created = $this->runOncePod($this->srcPodName('pg-table'), self::SRC_POSTGRES_IMAGE, ['sh', '-c', <<<'SH'
for i in $(seq 1 40); do
  psql "$DSN" -v ON_ERROR_STOP=1 -c 'CREATE TABLE IF NOT EXISTS public.orders (id int PRIMARY KEY, status text)' && exit 0
  sleep 3
done
exit 1
SH], ['DSN' => $this->pgDsn()], timeout: 180);
        if ($created !== true) {
            return 'creating the table failed: '.$created;
        }

        // the trigger creates (and marks) the publication and the slot; the superuser has REPLICATION
        return $this->addSourceTrigger($project, ['type' => 'cdc', 'name' => 'cdc', 'config' => [
            'publication' => self::SRC_PUBLICATION,
            'create_publication' => true,
            'tables' => ['public.orders'],
            'operations' => ['insert', 'update', 'delete'],
        ], 'secrets' => ['dsn' => $this->pgDsn()]]);
    }

    /** Waits until the gateway reads the slot, then inserts a row. */
    private function insertRow(string $slot): bool|string
    {
        return $this->runOncePod($this->srcPodName('pg-insert'), self::SRC_POSTGRES_IMAGE, ['sh', '-c', <<<'SH'
for i in $(seq 1 40); do
  [ "$(psql "$DSN" -tAc "SELECT count(*) FROM pg_replication_slots WHERE slot_name = '$SLOT' AND active")" = 1 ] && break
  sleep 3
done
psql "$DSN" -v ON_ERROR_STOP=1 -c "INSERT INTO public.orders VALUES (1, 'new') ON CONFLICT (id) DO UPDATE SET status = 'again'"
SH], ['DSN' => $this->pgDsn(), 'SLOT' => $slot], timeout: 180);
    }

    /**
     * The cdc trigger goes: the API names the slot it drops, the redeploy stops the gateway from
     * reading it, and DropCdcSlotsJob (run here at once instead of after its wait) drops the slot
     * and the publication RunR created.
     */
    private function verifyCdcCleanup(array $test, string $slot): bool|string
    {
        $project = $test['projectId'];
        $removed = $this->api()->delete("/api/projects/{$project}/triggers/cdc");
        if (! $removed->successful()) {
            return 'removing the cdc trigger failed: '.$removed->status().' '.mb_substr($removed->body(), 0, 200);
        }
        if ($removed->json('cleanup.0.slots') !== [$slot] || $removed->json('cleanup.0.publication') !== self::SRC_PUBLICATION) {
            return 'the answer does not name the slot and publication RunR drops: '.json_encode($removed->json('cleanup'));
        }

        if (! $this->deploySingleTest($test)) {
            return 'the redeploy without the cdc trigger failed';
        }
        sleep(5);
        $this->waitSingleReady($test);
        if (! $this->waitGatewayReady($test['slug'])) {
            return 'the gateway without the cdc trigger did not become ready';
        }

        $this->inProject($project, function () use ($project, $slot) {
            $model = Project::query()->findOrFail($project);
            Bus::dispatchSync(new DropCdcSlotsJob(
                TenantContext::currentId(), (string) $model->id, (string) $model->slug, (string) $model->knative_region_id,
                ['runr/project-id' => (string) $model->id] + InternalNetwork::podLabels($model),
                CdcSlotCleanup::workloads($model), 'cdc',
                ['dsn' => $this->pgDsn(), 'slots' => [$slot], 'publication' => self::SRC_PUBLICATION, 'drop_publication' => true],
                'removed',
            ));
        });

        $left = $this->runOncePod($this->srcPodName('pg-left'), self::SRC_POSTGRES_IMAGE, ['sh', '-c', <<<'SH'
s=$(psql "$DSN" -tAc "SELECT count(*) FROM pg_replication_slots WHERE slot_name = '$SLOT'")
p=$(psql "$DSN" -tAc "SELECT count(*) FROM pg_publication WHERE pubname = '$PUBLICATION'")
echo "slots=$s publications=$p"
[ "$s" = 0 ] && [ "$p" = 0 ]
SH], ['DSN' => $this->pgDsn(), 'SLOT' => $slot, 'PUBLICATION' => self::SRC_PUBLICATION]);

        return $left === true ? true : 'the slot or the publication is still on the database: '.$left;
    }

    // ── IMAP ──────────────────────────────────────────────────────────────────────────────

    private function setUpEmail(string $project): bool|string
    {
        $started = $this->startSourceBroker('mail', self::SRC_GREENMAIL_IMAGE, [3025, 3143], [
            // SMTP 3025 and IMAP 3143 (no TLS), the mailbox e2e@e2e.test with login e2e
            'GREENMAIL_OPTS' => '-Dgreenmail.setup.test.smtp -Dgreenmail.setup.test.imap -Dgreenmail.hostname=0.0.0.0 -Dgreenmail.users=e2e:'.self::SRC_SECRET.'@e2e.test,mover:'.self::SRC_SECRET.'@e2e.test -Dgreenmail.verbose',
            'JAVA_OPTS' => '-Djava.net.preferIPv4Stack=true -Xmx256m',
        ]);
        if ($started !== true) {
            return $started;
        }

        return $this->addSourceTrigger($project, ['type' => 'email', 'name' => 'email', 'config' => [
            'host' => $this->srcHost('mail'),
            'port' => 3143,
            'tls' => 'none',
            'username' => 'e2e',
            'mailbox' => 'INBOX',
            'poll_interval' => '5s',
        ], 'secrets' => ['password' => self::SRC_SECRET]]);
    }

    /** The mailbox mover@e2e.test of the same GreenMail: its trigger moves delivered mail to Processed. */
    private function setUpEmailMove(string $project): bool|string
    {
        return $this->addSourceTrigger($project, ['type' => 'email', 'name' => 'email-move', 'config' => [
            'host' => $this->srcHost('mail'),
            'port' => 3143,
            'tls' => 'none',
            'username' => 'mover',
            'mailbox' => 'INBOX',
            'poll_interval' => '5s',
            'after' => 'move',
            'move_to' => 'Processed',
        ], 'secrets' => ['password' => self::SRC_SECRET]]);
    }

    /** The delivered mail of mover@e2e.test is in Processed and INBOX is empty (IMAP STATUS through curl). */
    private function verifyMailMoved(): bool|string
    {
        return $this->runOncePod($this->srcPodName('mail-moved'), self::SRC_CURL_IMAGE, ['sh', '-c', <<<'SH'
for i in $(seq 1 20); do
  moved=$(curl -sS --url "imap://$HOST:3143/" -u "mover:$PASS" -X 'STATUS Processed (MESSAGES)' 2>&1)
  inbox=$(curl -sS --url "imap://$HOST:3143/" -u "mover:$PASS" -X 'STATUS INBOX (MESSAGES)' 2>&1)
  echo "Processed: $moved / INBOX: $inbox"
  echo "$moved" | grep -q 'MESSAGES 1)' && echo "$inbox" | grep -q 'MESSAGES 0)' && exit 0
  sleep 3
done
curl -sS --url "imap://$HOST:3143/" -u "mover:$PASS" -X 'CAPABILITY' 2>&1
exit 1
SH], ['HOST' => $this->srcHost('mail'), 'PASS' => self::SRC_SECRET]);
    }

    private function sendMail(string $subject, string $text, string $to = 'e2e@e2e.test'): bool|string
    {
        return $this->runOncePod($this->srcPodName('mail-send'), self::SRC_CURL_IMAGE, ['sh', '-c', <<<'SH'
printf 'From: sender@e2e.test\r\nTo: %s\r\nSubject: %s\r\nMessage-ID: <e2e-%s@e2e.test>\r\n\r\n%s\r\n' "$TO" "$SUBJECT" "$(date +%s)" "$TEXT" > /tmp/mail.txt
for i in $(seq 1 30); do
  curl -fsS --url "smtp://$HOST:3025" --mail-from sender@e2e.test --mail-rcpt "$TO" --upload-file /tmp/mail.txt && exit 0
  sleep 3
done
exit 1
SH], ['HOST' => $this->srcHost('mail'), 'SUBJECT' => $subject, 'TEXT' => $text, 'TO' => $to], timeout: 150);
    }

    // ── S3 ────────────────────────────────────────────────────────────────────────────────

    /**
     * One object written into the binding's bucket by an rclone pod (S3 credentials from env),
     * labelled as a pod of the project the binding is attached to (the data namespace admits
     * no other).
     */
    private function writeObject(array $s3, string $key, string $content, string $projectId): bool|string
    {
        $endpoint = (string) ($s3['S3_ENDPOINT'] ?? '');
        $env = [
            'RCLONE_CONFIG_DST_TYPE' => 's3',
            'RCLONE_CONFIG_DST_PROVIDER' => 'Other',
            'RCLONE_CONFIG_DST_ENDPOINT' => $endpoint,
            'RCLONE_CONFIG_DST_REGION' => (string) ($s3['S3_REGION'] ?? 'garage'),
            'RCLONE_CONFIG_DST_ACCESS_KEY_ID' => (string) ($s3['S3_ACCESS_KEY_ID'] ?? ''),
            'RCLONE_CONFIG_DST_SECRET_ACCESS_KEY' => (string) ($s3['S3_SECRET_ACCESS_KEY'] ?? ''),
            'RCLONE_CONFIG_DST_FORCE_PATH_STYLE' => 'true',
        ];
        $target = 'dst:'.($s3['S3_BUCKET'] ?? '').'/'.$key;

        return $this->runOncePod($this->srcPodName('s3'), self::SRC_RCLONE_IMAGE, ['sh', '-c', 'printf %s "$CONTENT" | rclone rcat '.escapeshellarg($target)], $env + ['CONTENT' => $content], ['runr/project-id' => $projectId]);
    }

    // ── Pods ──────────────────────────────────────────────────────────────────────────────

    private function srcPodName(string $what): string
    {
        return 'e2e-src-'.$what.'-'.substr(md5(uniqid('', true)), 0, 8);
    }

    /**
     * A throwaway system: Pod + Service e2e-<name> in the project namespace (the gateway's), up
     * once the pod runs (each step that uses it retries until it answers).
     *
     * @param  list<int>  $ports
     * @param  array<string, string>  $env
     * @param  list<string>|null  $command
     * @param  list<string>|null  $args
     */
    private function startSourceBroker(string $name, string $image, array $ports, array $env = [], ?array $command = null, ?array $args = null): bool|string
    {
        $k8s = $this->getE2eKubernetesClient();
        $ns = $this->getE2eNamespace();
        $pod = "e2e-{$name}";
        $labels = ['app.kubernetes.io/managed-by' => 'runr', 'runr.dev/purpose' => "e2e-{$name}"];
        $this->srcBrokers[] = $pod;

        $container = array_filter([
            'name' => $name,
            'image' => $image,
            'command' => $command,
            'args' => $args,
            'env' => array_map(fn ($k, $v) => ['name' => $k, 'value' => $v], array_keys($env), array_values($env)) ?: null,
            'ports' => array_map(fn (int $p) => ['containerPort' => $p], $ports),
            // small requests: the region capacity the e2e function needs stays free
            'resources' => ['requests' => ['cpu' => '50m', 'memory' => '128Mi'], 'limits' => ['memory' => '1Gi']],
        ], fn ($v) => $v !== null);

        try {
            $k8s->applyResource([
                'apiVersion' => 'v1', 'kind' => 'Pod',
                'metadata' => ['name' => $pod, 'namespace' => $ns, 'labels' => $labels],
                'spec' => ['enableServiceLinks' => false, 'containers' => [$container]],
            ], $ns);
            $k8s->applyResource([
                'apiVersion' => 'v1', 'kind' => 'Service',
                'metadata' => ['name' => $pod, 'namespace' => $ns, 'labels' => $labels],
                'spec' => ['selector' => $labels, 'ports' => array_map(fn (int $p) => ['name' => "p{$p}", 'port' => $p, 'targetPort' => $p], $ports)],
            ], $ns);
        } catch (\Throwable $e) {
            return "starting {$name} failed: ".$e->getMessage();
        }

        $deadline = time() + 240;
        while (time() < $deadline) {
            $status = $k8s->getResourceStatus('v1', 'Pod', $pod, $ns);
            if (($status['status']['phase'] ?? null) === 'Running') {
                return true;
            }
            sleep(3);
        }

        return "the {$name} pod ({$image}) did not start: ".json_encode($status['status']['containerStatuses'][0]['state'] ?? $status['status']['phase'] ?? null);
    }

    private function deleteSourceBrokers(): void
    {
        $k8s = $this->getE2eKubernetesClient();
        foreach (array_unique($this->srcBrokers) as $name) {
            foreach (['Pod', 'Service'] as $kind) {
                try {
                    $k8s->deleteResource('v1', $kind, $name, $this->getE2eNamespace());
                } catch (\Throwable) {
                }
            }
        }
        $this->srcBrokers = [];
    }

    /**
     * A pod that runs one command to completion; true when it succeeded.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     * @param  array<string, string>  $labels
     */
    private function runOncePod(string $name, string $image, array $command, array $env = [], array $labels = [], int $timeout = 120): bool|string
    {
        $k8s = $this->getE2eKubernetesClient();
        $ns = $this->getE2eNamespace();
        $k8s->applyResource([
            'apiVersion' => 'v1', 'kind' => 'Pod',
            'metadata' => ['name' => $name, 'namespace' => $ns, 'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr.dev/purpose' => 'e2e-trigger-source'] + $labels],
            'spec' => [
                'restartPolicy' => 'Never',
                'enableServiceLinks' => false,
                'containers' => [[
                    'name' => 'run', 'image' => $image, 'command' => $command,
                    'env' => array_map(fn ($k, $v) => ['name' => $k, 'value' => $v], array_keys($env), array_values($env)),
                ]],
            ],
        ], $ns);

        $phase = $this->waitForPods($k8s, $ns, [$name], $timeout)[$name] ?? 'Timeout';
        $logs = (string) $k8s->getPodLogs($name, $ns, 'run', 50);
        try {
            $k8s->deleteResource('v1', 'Pod', $name, $ns);
        } catch (\Throwable) {
        }

        return $phase === 'Succeeded' ? true : "{$name}: {$phase} ".mb_substr($logs, -300);
    }
}
