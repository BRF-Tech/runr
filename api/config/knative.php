<?php

use App\Services\Contracts\KnativeConstants;
use App\Services\Manifests\BuildManifests;
use App\Support\Images\RegistryMove;
use App\Support\Images\ReleaseImages;

// The region Traefik's image (TraefikImage): the reqs mirror of the pinned chart's image, or
// KNATIVE_TRAEFIK_IMAGE (Helm value knative.traefikImage), e.g. for a registry of your own
$traefikImage = RegistryMove::ref(env('KNATIVE_TRAEFIK_IMAGE') ?: KnativeConstants::TRAEFIK_IMAGE);

// RunR's images of the regions at the API's own version (APP_VERSION v0.6.0 → :0.6.0, as the API and
// UI images follow the chart's appVersion; a build that is no release: :latest). ReleaseImages
$queueConsumerImage = env('RUNR_QUEUE_CONSUMER_IMAGE') ?: ReleaseImages::ref('queue-consumer', (string) env('APP_VERSION', ''));
$agentImage = env('RUNR_AGENT_IMAGE') ?: ReleaseImages::ref('agent', (string) env('APP_VERSION', ''));

return [
    /*
    |--------------------------------------------------------------------------
    | Minimum Kubernetes Minor Version
    |--------------------------------------------------------------------------
    |
    | Clusters below this K8s minor version will be rejected when adding a
    | new region.  For example, 30 means K8s 1.30+ is required.
    |
    */
    'min_k8s_minor' => (int) env('KNATIVE_MIN_K8S_MINOR', 30),

    'traefik_image' => $traefikImage,

    /*
    |--------------------------------------------------------------------------
    | runr-agent Connection
    |--------------------------------------------------------------------------
    |
    | Where the region agent connects to (/ws/agent/{regionId} is appended).
    | Order: region setting `agent_ws_url` → in-cluster gateway when the region
    | is the cluster RunR runs in → `ws_url` below → APP_URL.
    |
    | ws_url:            base URL of the RunR gateway as seen from region
    |                    clusters, e.g. ws://10.0.0.10 or wss://runr.example.com
    | runr_namespace:    RunR's own namespace; empty → the pod's service
    |                    account namespace (Helm release namespace)
    | gateway_service:   in-cluster gateway Service (helm: runr-gateway)
    |
    */
    'agent' => [
        'ws_url' => env('RUNR_AGENT_WS_URL'),
        'runr_namespace' => env('RUNR_NAMESPACE'),
        // Not RUNR_GATEWAY_*: Kubernetes service links inject RUNR_GATEWAY_PORT=tcp://<ip>:80
        // into every pod of the release namespace (Service runr-gateway)
        'gateway_service' => env('RUNR_AGENT_GATEWAY_SERVICE', 'runr-gateway'),
        'gateway_port' => (int) env('RUNR_AGENT_GATEWAY_PORT', 80),
        'cluster_domain' => env('RUNR_CLUSTER_DOMAIN', 'cluster.local'),
        'serviceaccount_dir' => env('RUNR_SERVICEACCOUNT_DIR', '/var/run/secrets/kubernetes.io/serviceaccount'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Project Custom Domains
    |--------------------------------------------------------------------------
    |
    | cert_manager_cluster_issuer: an operator's cert-manager ClusterIssuer for
    |   the "cert-manager" TLS mode of project custom domains, on every region
    |   whose own setting `cert_manager_cluster_issuer` (PUT
    |   /knative-regions/{id}/settings) names none. With neither, RunR creates
    |   its default issuer in the region (cert_manager below).
    |
    */
    'domains' => [
        'cert_manager_cluster_issuer' => env('RUNR_CERT_MANAGER_CLUSTER_ISSUER'),
    ],

    /*
    |--------------------------------------------------------------------------
    | cert-manager in the regions (App\Services\CertManager\CertManagerService)
    |--------------------------------------------------------------------------
    |
    | install:       the region install sets cert-manager up where the cluster
    |                runs none (Helm knative.certManager.install); a cluster's
    |                own cert-manager is reused either way
    | chart_version: jetstack/cert-manager chart; empty: the pinned one
    |                (CertManager::CHART_VERSION)
    | image_prefix:  where its images come from (<prefix>/cert-manager-<component>
    |                :<chart version>); empty: the reqs mirror ghcr.io/brf-tech/runr/reqs
    | acme_email:    ACME account e-mail of RunR's default issuer on regions
    |                reachable from the internet (region setting
    |                cert_manager_acme_email overrides it); empty: the region CA
    | acme_server:   ACME directory; empty: Let's Encrypt
    |
    */
    'cert_manager' => [
        'install' => (bool) env('RUNR_CERT_MANAGER_INSTALL', true),
        'chart_version' => env('RUNR_CERT_MANAGER_CHART_VERSION'),
        'image_prefix' => env('RUNR_CERT_MANAGER_IMAGE_PREFIX'),
        'acme_email' => env('RUNR_CERT_MANAGER_ACME_EMAIL'),
        'acme_server' => env('RUNR_CERT_MANAGER_ACME_SERVER'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Function-domain TLS
    |--------------------------------------------------------------------------
    |
    | console_secret_name: the console's own TLS Secret in RunR's namespace,
    |                      copied by the "console certificate" mode. The Helm
    |                      chart sets it from ingress.tls.secretName.
    |
    */
    'function_tls' => [
        'console_secret_name' => env('CONSOLE_TLS_SECRET_NAME', 'runr-tls-custom'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Private ingress move (region:reconcile, PrivateIngressStep)
    |--------------------------------------------------------------------------
    |
    | dns_grace_seconds: after the placeholders of the Knative Services point at
    |   traefik-internal, how long the Traefik keeps serving cluster-local routes on
    |   web too, until DNS caches of the previous name expire (CoreDNS caches 30 s).
    | regenerate_timeout_seconds: how long the KIngresses get to name
    |   traefik-internal; the step fails after it, cluster-local routes stay on web
    |   too, and the next region:reconcile goes on.
    |
    */
    'private_ingress' => [
        'dns_grace_seconds' => (int) env('RUNR_PRIVATE_INGRESS_DNS_GRACE_SECONDS', 35),
        'regenerate_timeout_seconds' => (int) env('RUNR_PRIVATE_INGRESS_REGENERATE_TIMEOUT', 180),
    ],

    // The internal network (docs/internal-network.md): seconds the install and region:reconcile
    // wait for the authz before the private entry point refers to it; the image of the authz (the
    // region agent in authz mode — the e2e runs one built from the checkout)
    'internal_network' => [
        'authz_ready_timeout_seconds' => (int) env('RUNR_INTERNAL_AUTHZ_READY_TIMEOUT', 180),
        'authz_image' => env('RUNR_INTERNAL_AUTHZ_IMAGE') ?: $agentImage,
    ],

    // The queue consumer of queue-triggered projects and the topic publisher (the event gateway
    // image; the e2e runs one built from the checkout)
    'queue_consumer_image' => $queueConsumerImage,

    // The region agent (Deployment runr-agent of each region: metrics, pod sessions, heartbeats,
    // the global-health listener; KnativeAgentTrait). The e2e runs one built from the checkout
    'agent_image' => $agentImage,

    /*
    |--------------------------------------------------------------------------
    | Pre-pull Images
    |--------------------------------------------------------------------------
    |
    | Images to pre-pull on all cluster nodes after registry setup.
    | Prevents ImagePullBackOff on resource-constrained clusters (e.g. Kind).
    |
    */
    'prepull_images' => [
        // the image the region Traefik runs (TraefikImage)
        $traefikImage,
        $queueConsumerImage,
        BuildManifests::KANIKO_IMAGE,
        'ghcr.io/brf-tech/runr/reqs/redis:7-alpine',
        'ghcr.io/brf-tech/runr/reqs/alpine:3.20',
        'ghcr.io/brf-tech/runr/reqs/alpine:3.21',
        'ghcr.io/brf-tech/runr/reqs/busybox:latest',
        'ghcr.io/brf-tech/runr/reqs/curl:latest',
        'ghcr.io/brf-tech/runr/reqs/composer:latest',
    ],

    /*
    |--------------------------------------------------------------------------
    | Registry Setup: PVC Test
    |--------------------------------------------------------------------------
    |
    | Seconds the internal registry's setup waits for its test PVC to be Bound
    | (ZotRegistryService::testPvcBound). A WaitForFirstConsumer StorageClass
    | binds once the consumer pod is scheduled and its image pulled and the
    | provisioner created the volume — minutes on a busy cluster or slow
    | storage. 30 to 900; the registry setup jobs' own timeout grows with it.
    |
    */
    'registry_pvc_test_seconds' => min(900, max(30, (int) env('RUNR_REGISTRY_PVC_TEST_SECONDS', 180))),

    /*
    |--------------------------------------------------------------------------
    | Deploy Strategies (blue-green / canary rollouts)
    |--------------------------------------------------------------------------
    |
    | App\Services\Rollouts\RolloutService (docs/deploy-strategies.md).
    |
    | health_interval_seconds  time between two health checks of a blue-green rollout that
    |                          promotes itself
    | health_timeout_seconds   one health check's answer time
    | health_deadline_seconds  the checks must pass within this time after the new revision is
    |                          ready, else the rollout fails (traffic stays)
    | metrics_interval_seconds how often a canary step compares the error rate / p95 latency
    |                          with the thresholds
    | min_requests             requests a canary window needs before a threshold is judged
    |
    */
    'rollouts' => [
        'health_interval_seconds' => (int) env('RUNR_ROLLOUT_HEALTH_INTERVAL', 10),
        'health_timeout_seconds' => (int) env('RUNR_ROLLOUT_HEALTH_TIMEOUT', 10),
        'health_deadline_seconds' => (int) env('RUNR_ROLLOUT_HEALTH_DEADLINE', 300),
        'metrics_interval_seconds' => (int) env('RUNR_ROLLOUT_METRICS_INTERVAL', 60),
        'min_requests' => (int) env('RUNR_ROLLOUT_MIN_REQUESTS', 10),
    ],
];
