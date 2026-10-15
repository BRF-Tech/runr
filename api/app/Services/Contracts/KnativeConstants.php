<?php

namespace App\Services\Contracts;

interface KnativeConstants
{
    const HELM_REPO_NAME = 'knative-operator';

    const HELM_REPO_URL = 'https://knative.github.io/operator';

    const HELM_RELEASE = 'knative-operator';

    const HELM_CHART = 'knative-operator/knative-operator';

    const HELM_NAMESPACE = 'knative-operator';

    const TRAEFIK_REPO_NAME = 'traefik';

    const TRAEFIK_REPO_URL = 'https://traefik.github.io/charts';

    const TRAEFIK_RELEASE = 'traefik';

    const TRAEFIK_CHART = 'traefik/traefik';

    // Pinned: chart >= 41.0.0 renamed root `logs` to `log`/`accessLog` and its values schema
    // rejects traefikHelmValues(). 39.x bundles Gateway API v1.4 CRDs, which the
    // safe-upgrades ValidatingAdmissionPolicy of an installed Gateway API >= v1.5 rejects.
    // 40.3.0 = Traefik v3.7.4, no bundled Gateway API CRDs.
    const TRAEFIK_CHART_VERSION = '40.3.0';

    // The image that chart version runs (its appVersion), from the reqs mirror of
    // docker.io/traefik like RunR's other third-party images (runtime/lib/mirror.sh): set as the
    // chart's image (KnativeTraefikTrait), pre-pulled by the nodes (config knative.prepull_images)
    // and loaded into Kind by the e2e. KNATIVE_TRAEFIK_IMAGE overrides it (TraefikImage).
    // Changes with TRAEFIK_CHART_VERSION, together with its REQS_IMAGES entry.
    const TRAEFIK_IMAGE = 'ghcr.io/runrsh/reqs/traefik:v3.7.4';

    // IngressClass owned by the region Traefik; "traefik-knative" when another controller owns "traefik"
    const TRAEFIK_INGRESS_CLASS = 'traefik';

    const TRAEFIK_KNATIVE_INGRESS_CLASS = 'traefik-knative';

    // Queue-proxy sidecar resources (config-deployment, KnativeCRTrait::configDeploymentValues()).
    // Requests: 100m CPU / 100 MiB (100m: a 25m hard limit throttled every proxied request at
    // ~100–250 rps). Quota/capacity math (App\Services\Helpers\ResourceReservation) adds these
    // requests on top of the user container of every Knative Service pod (http / queue trigger).
    const QUEUE_PROXY_CPU_M = 100; // millicores

    const QUEUE_PROXY_MEMORY_MB = 100; // MiB

    // Queue-proxy CPU burst (RunR 0.4.2): every Knative Service RunR renders sets the queue-proxy's
    // CPU *limit* to 1 CPU through this revision annotation (KnativeManifestTrait); its request
    // stays QUEUE_PROXY_CPU_M and memory stays request == limit. The pod may then use
    // user limit + 1 CPU (+ RuntimeClass overhead) while it starts — a Kata VM boots inside that
    // limit, and queue-proxy itself is ready sooner — and proxies with more than 100m under load.
    // Measured on a single-node k3s test VM (scale from zero, Node.js 250m): runc 2.0 → 1.5 s, Kata CLH 3.9 → 2.7 s.
    // Trade: project pods are Burstable instead of Guaranteed (0.3.8 had pinned request == limit).
    // The burst only uses CPU the node has idle — under contention every pod gets CPU by its
    // request (cpu.weight); nothing more is reserved: the scheduler, ResourceReservation, tenant
    // admission and the organization ceiling (requests.cpu, NamespaceQuotaTrait) count requests.
    // Memory never bursts, so kubelet's eviction order is unchanged in practice; the node's OOM
    // killer ranks project pods like other Burstable pods. config-deployment keeps request ==
    // limit (100m): changing it would roll every Revision; Revisions deployed before 0.4.2 keep
    // that until their next deploy. CronJob pods (schedule trigger) have no queue-proxy.
    const QUEUE_PROXY_CPU_LIMIT_M = 1000; // millicores

    const QUEUE_PROXY_CPU_LIMIT_ANNOTATION = 'queue.sidecar.serving.knative.dev/cpu-resource-limit';

    // Queue consumer Deployment of a queue-trigger project (QueueConsumerManifests), one pod per
    // project, request == limit. Counted once per queue project in quota/capacity math.
    const QUEUE_CONSUMER_CPU_M = 50; // millicores

    const QUEUE_CONSUMER_MEMORY_MB = 64; // MiB

    // Build Jobs in the project namespace (FunctionBuildService, RuntimeImageBuildService): request == limit so
    // they fit a namespace ResourceQuota on limits.* (NamespaceQuotaTrait) without relying on the
    // LimitRange defaults. Kaniko does the build; copy-src is the init container that lays out
    // the build context.
    const BUILD_CPU_M = 1000; // millicores

    const BUILD_MEMORY_MB = 2048; // MiB

    const BUILD_INIT_CPU_M = 50;   // millicores

    const BUILD_INIT_MEMORY_MB = 64;   // MiB
}
