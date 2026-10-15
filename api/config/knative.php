<?php

use App\Services\Contracts\KnativeConstants;
use App\Services\Manifests\BuildManifests;
use App\Support\Images\RegistryMove;

// The region Traefik's image (TraefikImage): the reqs mirror of the pinned chart's image, or
// KNATIVE_TRAEFIK_IMAGE (Helm value knative.traefikImage), e.g. for a registry of your own
$traefikImage = RegistryMove::ref(env('KNATIVE_TRAEFIK_IMAGE') ?: KnativeConstants::TRAEFIK_IMAGE);

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
    | cert_manager_cluster_issuer: cert-manager ClusterIssuer used for the
    |   "cert-manager" TLS mode of project custom domains. The region setting
    |   `cert_manager_cluster_issuer` (PUT /knative-regions/{id}/settings)
    |   takes precedence. The mode is offered only when cert-manager.io/v1 is
    |   served by the region cluster and the ClusterIssuer exists there.
    |
    */
    'domains' => [
        'cert_manager_cluster_issuer' => env('RUNR_CERT_MANAGER_CLUSTER_ISSUER'),
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
        'ghcr.io/brf-tech/runr-queue-consumer:latest',
        BuildManifests::KANIKO_IMAGE,
        'ghcr.io/brf-tech/reqs/redis:7-alpine',
        'ghcr.io/brf-tech/reqs/alpine:3.20',
        'ghcr.io/brf-tech/reqs/alpine:3.21',
        'ghcr.io/brf-tech/reqs/busybox:latest',
        'ghcr.io/brf-tech/reqs/curl:latest',
        'ghcr.io/brf-tech/reqs/composer:latest',
    ],
];
