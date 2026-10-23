<?php

use App\Support\Images\RegistryMove;

/*
|--------------------------------------------------------------------------
| Global projects and the global DNS zone (docs/global-projects.md)
|--------------------------------------------------------------------------
|
| A global project runs in several regions behind <slug>.<zone>. RunR answers the zone itself:
| every region that takes part runs a small PowerDNS Authoritative server (namespace below, a
| LoadBalancer Service on UDP + TCP 53) and RunR writes the same zone to each of them through
| their HTTP API (reached through the Kubernetes API server's service proxy, never exposed). The
| operator delegates the zone once: NS records of <zone> in the parent zone, pointing at the
| servers (GET /api/global-dns lists the records).
|
| The project records are PowerDNS LUA records: ifurlup() health checks of every region's copy
| (the region agent's /global-health/<slug> behind the region's Traefik), ha — the primary region
| first, the others when it is down — or proximity — pickclosest() over the healthy regions with a
| GeoIP database.
|
*/

return [
    // The global zone. Empty: g.<base domain> (Settings → Platform → Base Domain)
    'zone' => env('RUNR_GLOBAL_DNS_ZONE'),

    // The label in front of the base domain when the zone is not set
    'zone_label' => env('RUNR_GLOBAL_DNS_ZONE_LABEL', 'g'),

    // Namespace of the PowerDNS server in a region cluster (one server per cluster)
    'namespace' => env('RUNR_GLOBAL_DNS_NAMESPACE', 'runr-global-dns'),

    // PowerDNS Authoritative, from the reqs mirror (powerdns/pdns-auth-50)
    'image' => RegistryMove::ref(env('RUNR_GLOBAL_DNS_IMAGE', 'ghcr.io/brf-tech/runr/reqs/pdns-auth:5.0.8')),

    // The server's volume (SQLite zone database, the GeoIP database); empty class: the default one
    'storage_class' => env('RUNR_GLOBAL_DNS_STORAGE_CLASS'),
    'storage_size' => env('RUNR_GLOBAL_DNS_STORAGE_SIZE', '1Gi'),

    // Annotations of the DNS LoadBalancer Service (e.g. a fixed address of the load balancer),
    // JSON object
    'service_annotations' => json_decode((string) env('RUNR_GLOBAL_DNS_SERVICE_ANNOTATIONS', '{}'), true) ?: [],

    // Regions of one global project
    'max_regions' => (int) env('RUNR_GLOBAL_DNS_MAX_REGIONS', 8),

    // TTL of the project records (seconds): how long resolvers keep an answer after a failover
    'ttl' => (int) env('RUNR_GLOBAL_DNS_TTL', 30),

    // ifurlup() health checks of the regions' copies, from every server
    'health' => [
        'interval' => (int) env('RUNR_GLOBAL_DNS_HEALTH_INTERVAL', 5),
        'timeout' => (int) env('RUNR_GLOBAL_DNS_HEALTH_TIMEOUT', 2),
        // consecutive failures before a region leaves the answers
        'minimum_failures' => (int) env('RUNR_GLOBAL_DNS_HEALTH_MINIMUM_FAILURES', 2),
        // the host the health route of every region answers (runr-health.<zone>)
        'host_label' => 'runr-health',
        // the region agent's health port (Service runr-agent-health next to the agent)
        'agent_port' => 8086,
    ],

    // GeoIP database of proximity mode (pickclosest): a gzipped MaxMind-format city database
    // the server downloads at start and refreshes monthly. {yyyy} / {mm}: the current year and
    // month. The default is DB-IP's "IP to City Lite" (CC BY 4.0, redistributable with
    // attribution); empty: no GeoIP — proximity answers like ha without a preferred region.
    'geoip' => [
        'url' => env('RUNR_GLOBAL_DNS_GEOIP_URL', 'https://download.db-ip.com/free/dbip-city-lite-{yyyy}-{mm}.mmdb.gz'),
    ],

    // Failover of a global project's primary (docs/global-projects.md, "Failover"): a primary whose
    // health check failed this long (control plane, every minute) is moved to the next healthy
    // region when the project's failover is automatic
    'failover' => [
        'after_seconds' => (int) env('RUNR_GLOBAL_FAILOVER_AFTER_SECONDS', 180),
    ],

    // The data link of a binding of a global project (docs/global-projects.md, "Data"): the
    // forwarder the other regions connect to (nginx stream, mirrored in reqs) and the port of the
    // home region's Traefik it reaches (TLS with SNI on websecure)
    'data_link' => [
        'image' => RegistryMove::ref(env('RUNR_GLOBAL_DATA_LINK_IMAGE', 'ghcr.io/brf-tech/runr/reqs/nginx:1.27-alpine')),
        'port' => (int) env('RUNR_GLOBAL_DATA_LINK_PORT', 443),
    ],

    // Seconds the copy of an uploaded CaaS image into a region's registry may take
    'image_copy_timeout' => (int) env('RUNR_GLOBAL_IMAGE_COPY_TIMEOUT', 3600),

    // The zone's wildcard certificate in every region: acme — the region's cert-manager (the
    // Serving install sets it up) issues *.<zone> with DNS-01 through RFC 2136 updates to RunR's
    // server, signed with RunR's TSIG key; none — the routes answer with the region Traefik's
    // default certificate. acme_email: the ACME account's e-mail, else the region's cert-manager
    // ACME e-mail (cert_manager_acme_email / knative.certManager.acmeEmail)
    'tls' => [
        'mode' => env('RUNR_GLOBAL_DNS_TLS', 'acme'),
        'acme_server' => env('RUNR_GLOBAL_DNS_ACME_SERVER', 'https://acme-v02.api.letsencrypt.org/directory'),
        'acme_email' => env('RUNR_GLOBAL_DNS_ACME_EMAIL'),
        'tsig_key_name' => 'runr-acme',
    ],
];
