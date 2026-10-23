# Trigger sources e2e (broker and external sources of the event gateway, docs/triggers.md).
#
# cmd_test_trigger_sources — `ci.sh test-trigger-sources [cmd_test flags]`: Kind + Knative
#                            (cmd_test) running only the trigger sources suite
#                            (test:e2e --suite=trigger-sources,
#                            api/app/Console/Commands/Concerns/E2eTestTriggerSourcesTrait.php). One
#                            Python function gets a trigger of every source and each system runs as
#                            a throwaway pod in the project namespace:
#                              - s3: the region's managed object store (through the API), an object
#                                binding's bucket, an object written by an rclone pod;
#                              - kafka (KRaft, one node), nats (JetStream stream + durable consumer),
#                                rabbitmq (durable queue, manual acks), mqtt (Mosquitto, retained
#                                QoS 1), cdc (Postgres with wal_level=logical, an inserted row),
#                                email (GreenMail: SMTP in, IMAP read);
#                              - a schedule in Pacific/Chatham through the gateway;
#                              - the cdc trigger removed: its slot and publication dropped from the
#                                database (DropCdcSlotsJob, the gateway image in cdc-cleanup mode).
#                            Each event must be delivered by the event gateway (gateway /stats via
#                            the API proxy). RUNR_E2E_MQTT_IMAGE (--mqtt-image) overrides the
#                            Mosquitto image.
# preload_trigger_sources_images — pulls the source images and loads them into the Kind cluster
#                            (cmd_test calls it for --suite=trigger-sources, with the object store
#                            images of the bindings suite).
#
# The images come from RunR's reqs mirror (ghcr.io/brf-tech/runr/reqs, BRF-Tech/runr-runtimes
# lib/mirror.sh REQS_IMAGES):
#   "kafka:3.9.1|apache/kafka:3.9.1"
#   "nats:2.11-alpine|nats:2.11-alpine"
#   "rabbitmq:4.1-management-alpine|rabbitmq:4.1-management-alpine"
#   "eclipse-mosquitto:2.0.20|eclipse-mosquitto:2.0.20"
#   "greenmail:2.1.3|greenmail/standalone:2.1.3"
# postgres:17-alpine, curl:latest and rclone:1.68.2 are mirrored already.
#
# KIND_LOCK                — one Kind cluster at a time on a shared host; defaults to
#                            /tmp/runr-kind.lock here (test-runtimes.sh: acquire_kind_lock).

TRIGGER_SOURCES_E2E_IMAGES=(
  "ghcr.io/brf-tech/runr/reqs/kafka:3.9.1"
  "ghcr.io/brf-tech/runr/reqs/nats:2.11-alpine"
  "ghcr.io/brf-tech/runr/reqs/rabbitmq:4.1-management-alpine"
  "ghcr.io/brf-tech/runr/reqs/eclipse-mosquitto:2.0.20"
  "ghcr.io/brf-tech/runr/reqs/postgres:17-alpine"
  "ghcr.io/brf-tech/runr/reqs/greenmail:2.1.3"
  "ghcr.io/brf-tech/runr/reqs/curl:latest"
)

preload_trigger_sources_images() {
  local cluster="$1"
  [ -z "$cluster" ] && return 0
  # KIND_PRELOAD=0 (test.sh): the nodes pull what they run
  if [ "${KIND_PRELOAD:-1}" = "0" ]; then
    echo "Trigger sources e2e: source images not loaded (KIND_PRELOAD=0): the nodes pull them"
    return 0
  fi

  echo ""
  echo "Trigger sources e2e: broker images → kind/${cluster}"
  local img
  for img in "${TRIGGER_SOURCES_E2E_IMAGES[@]}"; do
    pull_image "$img" || echo "  pull failed: ${img} (the cluster pulls it itself)"
    kind_load_image "$img" "$cluster" || echo "  not loaded: ${img} (the cluster pulls it itself)"
  done
  echo -e "  ${GREEN}✓ broker images loaded${NC}"
}

cmd_test_trigger_sources() {
  export KIND_LOCK="${KIND_LOCK:-/tmp/runr-kind.lock}"
  if [ -n "${RUNR_E2E_MQTT_IMAGE:-}" ]; then
    export TEST_E2E_EXTRA_ARGS="${TEST_E2E_EXTRA_ARGS:-} --mqtt-image=${RUNR_E2E_MQTT_IMAGE}"
  fi
  cmd_test --suite=trigger-sources "$@"
}
