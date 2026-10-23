# Global projects

A global project runs in several regions behind one host name, `<slug>.<global zone>`. A request
to that name reaches a healthy copy of the project: in **HA** mode the primary region's while it is
healthy and the other regions' when it is not, in **proximity** mode the healthy region closest to
the client. RunR answers the global zone itself: every region that takes part runs a small
PowerDNS server for it, and the operator delegates the zone to them once.

The **primary** region builds and deploys first, runs the project's schedule, queue and event
triggers, and holds the primary of a dedicated Postgres binding, which every other region
replicates for local reads; managed KV, object storage and shared Postgres stay in their region
and are reached from the others through an encrypted data link. When the primary fails, RunR
fails over to the next healthy region — the replica there is promoted, the triggers and the DNS
answer move — and the old primary rejoins as a secondary once it is back.

```text
                       resolver ── NS g.example.com ──▶ ns-eu-1 / ns-us-1 (PowerDNS in each region)
                          │                                │ LUA record: health checks of every copy
client ── shop.g.example.com ──▶ 203.0.113.10 (eu-1 Traefik) ── shop copy (primary)
                                  198.51.100.10 (us-1 Traefik) ── shop copy
```

- [Requirements](#requirements)
- [Global DNS (operators)](#global-dns-operators)
- [Creating a global project](#creating-a-global-project)
- [Modes](#modes)
- [GeoIP database](#geoip-database)
- [Deploys](#deploys)
- [Triggers](#triggers)
- [Health checks](#health-checks)
- [Failover](#failover)
- [TLS](#tls)
- [Custom domains](#custom-domains)
- [Gateways](#gateways)
- [Data](#data)
- [Calls between projects](#calls-between-projects)
- [Usage, billing and capacity](#usage-billing-and-capacity)
- [What each region needs](#what-each-region-needs)
- [What a global project cannot use](#what-a-global-project-cannot-use)
- [API](#api)
- [CLI, MCP and console](#cli-mcp-and-console)
- [Helm values](#helm-values)
- [How it works](#how-it-works)
- [Audit](#audit)
- [Testing](#testing)
- [Troubleshooting](#troubleshooting)

## Requirements

| What | Why |
| --- | --- |
| A platform base domain (Administration → Regions & settings → Base Domain) | The global zone is `g.<base domain>` (Helm `globalDns.zone` overrides it) |
| Two or more **platform** regions with Knative Serving | A global project runs in 2 to 8 regions (`globalDns.maxRegions`) |
| A load balancer in each of those regions that gives a Service a public address for **UDP and TCP 53** | The region's PowerDNS server answers the zone there. A region without a public port 53 cannot take part |
| The zone delegated to the servers in its parent zone | Resolvers find the servers through it ([Delegation](#delegation)) |
| cert-manager in each region (the Serving install sets it up, [regions.md](regions.md)) | The wildcard certificate `*.<zone>` ([TLS](#tls)) and the custom domains' certificates; while it is not ready HTTPS answers with the region's default certificate |
| Each region's Traefik receives TLS on 443 itself (no load balancer in front that ends TLS) | The [data link](#the-data-link) of a binding: the other regions reach it with TLS by SNI on the region's Traefik address |

Global DNS runs in platform regions only. In the multi-tenant edition the organizations create
global projects in the platform regions they are allowed to use; an organization's own region
cannot run a server of the platform's zone.

## Global DNS (operators)

### Enabling a region

Region → General → **Global DNS** → Enable, or `POST /api/knative-regions/{id}/global-dns`
(`regions.manage`), or `runr regions global-dns enable <region>`. RunR:

1. installs PowerDNS Authoritative (`ghcr.io/brf-tech/runr/reqs/pdns-auth:5.0.8`, a mirror of
   `powerdns/pdns-auth-50`) in the namespace `runr-global-dns` of the region's cluster: a
   Deployment with a 1 GiB volume, a LoadBalancer Service `runr-pdns` on UDP and TCP 53 and a
   ClusterIP Service `runr-pdns-api` for its HTTP API;
2. deploys the region agent again: it gets its global-health listener (Service
   `runr-agent-health`, port 8086) and permission to read Knative Services;
3. waits for the pod and the load balancer address, creates the zone and RunR's TSIG key through
   the server's API, and asks the address for the zone's SOA on port 53 from the control plane;
4. syncs the zone to every server and gives the region its health route and certificate.

The server's status: `installing` → `ready`, or `error` with the reason. A load balancer that
gives the DNS Service no address within ten minutes leaves the server in `error`: the region has no
public port 53. **Reachable** says whether the address answered the zone from the control plane;
a server that is ready but not reachable has a load balancer that does not pass UDP or TCP 53.

Regions on one cluster share one server (one namespace per cluster). Disabling a region's server
(Region → General → Global DNS → Disable, `DELETE /api/knative-regions/{id}/global-dns`) is refused
while global projects run in the region; the server's objects stay while another region of the
same cluster uses them.

### Delegation

`GET /api/global-dns` (Administration → Regions & settings → Global DNS, `runr global-dns`) lists the records to
create in the parent zone, one NS record per ready server and the address of each name server
(glue):

```text
g.example.com.            NS    ns-eu-1.g.example.com.
g.example.com.            NS    ns-us-1.g.example.com.
ns-eu-1.g.example.com.    A     203.0.113.53
ns-us-1.g.example.com.    A     198.51.100.53
```

The name servers are `ns-<region name>.<zone>`; their address records are glue in the parent zone
and also part of the zone itself. A region that joins or leaves later adds or removes its two
records. The page shows whether public DNS delegates the zone yet (`delegation.status`: `ok`,
`partial` — some names missing — or `missing`), as the control plane's resolver sees it.

### Zone sync

RunR writes the same zone to every ready server through its HTTP API — every minute, and after
every change of a global project or a server (`POST /api/global-dns/sync` asks for one now). The
API is reached through the Kubernetes API server's service proxy with the server's own API key and
is never exposed outside the cluster. A server whose zone differed from what the others needed is
shown as **drift** (repaired by the same sync): a record changed by hand on a server does not
survive the next minute.

## Creating a global project

In the console: New project → **Global project** → choose the regions (only regions with global
DNS ready are offered), the primary region and the mode. With the API, `POST /api/projects` with
`placement: "global"`, `global_mode`, `region_ids` (all regions, the primary included) and
`knative_region_id` (the primary):

```json
{
  "name": "shop",
  "service_type": "caas",
  "knative_region_id": "01J…eu",
  "placement": "global",
  "global_mode": "ha",
  "region_ids": ["01J…eu", "01J…us"],
  "config": {"image": "ghcr.io/acme/shop:1.4", "trigger_type": "http", "ports": [8080]}
}
```

The project's host name is `https://shop.g.example.com` (`endpoint_url`); each region's copy also
answers on that region's own host name `shop.<region function domain>`. Data residency applies to
every region of the project.

The **primary** region is where the project lives like a regional project: its builds, logs,
metrics, custom domains and bindings are there, its deployments are the primary's. The regions,
the primary and the mode change under the project's **Regions** tab (`PUT
/api/projects/{project}/global`): a new region gets the active version, a region left out has its
copy removed, a region whose copy serves the active version can become the primary.

## Modes

| Mode | DNS answers | Use it when |
| --- | --- | --- |
| `ha` (default) | the primary region while its copy is healthy; when it is not, every other region whose copy is healthy | one region serves, the others stand by — data next to the primary |
| `proximity` | the healthy region closest to the client (EDNS Client Subnet, else the resolver's address) | clients everywhere, data reachable from every region |

When no region is healthy, every region is answered. Proximity uses a GeoIP database in each
server: by default DB-IP's "IP to City Lite", downloaded monthly at the server's start
([Helm values](#helm-values)); a cluster without internet access gets it another way
([GeoIP database](#geoip-database)). Without a GeoIP database proximity answers a healthy region
without preferring the closest.

### GeoIP licensing

| Database | License | How |
| --- | --- | --- |
| DB-IP IP to City Lite (default) | [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/): free, redistributable, attribution required — "IP Geolocation by DB-IP" (<https://db-ip.com>) | downloaded by each server from `download.db-ip.com` |
| MaxMind GeoLite2 City | MaxMind's GeoLite2 EULA: an account and a license key, not redistributable | `globalDns.geoipUrl` set to your download URL with your key (a gzipped `.mmdb`) |
| None | — | `globalDns.geoipUrl: "-"` |

RunR does not ship a GeoIP database; each server downloads the one configured, or reads the
operator's file ([GeoIP database](#geoip-database)).

## GeoIP database

Each region's server has a **GeoIP source** — Region → General → Global DNS → GeoIP database, `PUT
/api/knative-regions/{id}/global-dns/geoip` (`regions.manage`), `runr regions global-dns geoip`:

| Source | What the server does at start | Use it when |
| --- | --- | --- |
| `default` | downloads `globalDns.geoipUrl` (DB-IP's monthly file unless set), again when it is older than 35 days | the cluster reaches the internet |
| `url` | downloads the operator's URL (`{yyyy}` / `{mm}` allowed), again when it is older than 35 days or the URL changed | a cluster without internet access: an internal mirror, or the file in the region's object store — a presigned URL (`aws s3 presign`, `mc share download`) or a public-read object; for the region's managed store (`runr-objects.runr-data.svc…`) RunR lets the DNS namespace reach it (NetworkPolicy `runr-global-dns-geoip` in the data namespace) |
| `file` | downloads nothing: reads `geoip.mmdb` (or unpacks `geoip.mmdb.gz`) that the operator copied onto its volume | no URL at all: `kubectl -n runr-global-dns cp geoip.mmdb <pod>:/var/lib/powerdns/geoip.mmdb -c pdns`, then **Reload** (`POST …/global-dns/geoip/reload`) |
| `none` | removes the database | proximity without a preferred region |

The file is a MaxMind-format city database, gzipped (`.gz`) or not. A failed download keeps the
database the server had. Changing the source restarts the server (one pod; the other regions'
servers answer meanwhile). Regions on one cluster share one server, and the source applied last.

**Status** — the console shows what the server's init container reported at its last start
(`geoip` of the server object, read at most once a minute): `loaded` (source, size, date; a failed
refresh is named next to it), `missing` (the download error, or no file on the volume) or `off`. A
URL's query (a presigned signature) is never stored or shown.

## Deploys

A deploy of a global project is a deploy of its primary region, as for a regional project. When
the primary's deployment is ready, RunR rolls its version out to every other region:

| Region status | What happens |
| --- | --- |
| `building` | the version has a build image (a FaaS project with dependencies, a compiled runtime, a WebAssembly module): the same build runs in the region, into the region's own registry, from the same runtime base (the version's runtime patch, by digest) — no region pulls from another one's. The build is kept per version and region in the store [environments](environments.md#another-region) use (`version_region_images`): a region where an environment already built the version is not built again, and the other way round. An **uploaded CaaS image** is copied instead ([Uploaded images](#uploaded-images)) |
| `deploying` | the deploy's Knative Service (same name, size, environment, secrets) is applied in the region, then RunR waits for the new revision |
| `ready` | the region serves the version: its routes of the global host name are applied, the zone is synced |
| `failed` | the build or the revision failed there; the region keeps serving the version before |

The primary goes first: a version that does not start never leaves it. A region also runs the
project's [trigger workloads on standby](#triggers) and its [custom domains](#custom-domains).

### Uploaded images

An uploaded image ([images.md](images.md)) lives in one region's internal registry. Before a region
runs a CaaS project with one, RunR copies the image there: a transfer pod pulls it from the
primary's registry as an OCI layout, the control plane hands it on, another pushes it into the
region's registry under the same repository and tag. The copy is recorded as the organization's
uploaded image in that region, pinned by the same digest (note "Copied for the global project …"),
and the region's copy of the project runs it by digest. A region that holds that digest already
copies nothing. Every region needs an internal registry for this (`registry_mode: internal`);
a region without one is refused when it is added. Copies stay when the project leaves a region:
they are the organization's images there, removed like any other. A region keeps serving the
version before while it rolls a new one out, so it stays in the DNS answers. **Sync now** (`POST
/api/projects/{project}/global/sync`) rolls the active version out again to every region that is
not ready. Stopping the project stops every copy; resuming deploys the primary, and the other
regions follow.

### Secrets

The values of the project's secret variables live in the regions (pass-through,
[security.md](security.md)): the secrets API writes them into the primary region. Every rollout
copies them from the primary into the region it deploys to — into the same kind of store the
variable's source names: the region's Kubernetes Secret `<slug>-secret-env`, or its OpenBao (a
region without OpenBao fails the rollout of a project whose values live in OpenBao). RunR's own
keys of the workload (its token on the region's internal network and the like) are written for
each region. A changed value reaches the other regions with the next deploy. References to a
secret store become an ExternalSecret in each region. A region that leaves the project, and the
project's deletion, remove the copies (the Secret and the OpenBao path).

## Triggers

A global project may have any trigger list ([triggers.md](triggers.md)). Every region runs the
project's Knative Service, so **HTTP and workflow triggers** answer in every region. Everything
else runs in **one region only — the primary, the trigger region** — so each event is handled
once, not once per region:

| Workload | In the primary | In the other regions |
| --- | --- | --- |
| CronJob `<slug>-trigger` (a schedule alone) | active | applied with `suspend: true` |
| Event gateway / workflow queue bridge `<slug>-qc` (queue, topic, webhook, platform events, broker and external sources, schedules next to other triggers) | 1 replica | 0 replicas |

A [switchover or a failover](#failover) flips them: the new primary's run, the old primary's stop
(at once, or — a region that does not answer — as soon as its cluster answers again). The Regions
tab marks the region that runs them (`triggers_active`).

What follows from it:

- **Schedules, webhooks, platform events, broker and external sources** (Kafka, NATS, RabbitMQ,
  MQTT, S3, e-mail, CDC) move without losing anything: their source is outside the region (RunR's
  inbox, the broker, the bucket, the mailbox, the database), the gateway of the new primary goes on
  from where the consumer group or inbox lease stands.
- **Queue and topic triggers** read a stream of the trigger region's Redis. Producers write to the
  primary region's Redis; after a move the new primary reads its own region's stream — messages
  still in the old region's stream wait there and are delivered when the primary moves back. Every
  region of a project with such a trigger needs Redis (checked when the trigger or a region is
  added, and before a region rolls out). A topic trigger needs the topic in the primary's region
  ([triggers.md](triggers.md#topics)).
- A project whose only trigger is a schedule has no Knative Service: its health in the
  [failover](#failover) check is the region's cluster API.

## Health checks

Every server checks every region's copy of every global project: PowerDNS' `ifurlup()` asks
`http://runr-health.<zone>/global-health/<slug>` on the region's Traefik address every 5 seconds.
The region's health route sends it to its agent, which answers `ok` while the project's Knative
Service is ready in the region — without calling the function: a scaled-to-zero copy stays at zero.
Two failed checks in a row (`globalDns` defaults) take a region out of the answers; the TTL of the
project records is 30 seconds. A region whose cluster, Traefik or agent is down fails its check,
whatever its copy's state.

The project's **Regions** tab shows the same check from the control plane (`healthy`, refreshed at
most every 30 seconds, and every minute by the [failover](#failover) check) and whether the region
is in the DNS answers (`in_dns`): its copy serves a version, it is not [fenced](#failover), and its
[data](#data) is reachable from it (`data_status` ready, or no regional data). A region whose
replica cluster is still cloning or whose data link is down stays out of the answers until it is
ready.

## Failover

DNS leaves a region whose copy fails its checks out of the answers within seconds. The **primary**
— where deploys start, the triggers run and a dedicated Postgres binding writes — moves in one of
three ways:

| Move | When | What happens |
| --- | --- | --- |
| **Switchover** | `PUT /projects/{project}/global` with `primary_region_id` (Regions → Make primary); both regions answer | every dedicated Postgres binding is switched over first — CloudNativePG demotes the old primary, its last WAL reaches the replica, the replica is promoted with the demotion token: **nothing written is lost**; then the project's primary, its triggers and the DNS answer move. In the background when there are replicas (`moving_to` shows it) |
| **Failover** | `POST /projects/{project}/global/failover` (`region_id`, else the first healthy region in HA order; Regions → Fail over) | for a primary whose copy does not answer: when the old primary's cluster still answers, its Postgres data is handed over as in a switchover (up to two minutes, nothing lost); otherwise the target's replica clusters are promoted **at once** — what the old primary wrote after the target's last received WAL is lost (asynchronous replication, usually under a second). The primary, the triggers and the DNS answer move; the old primary is **fenced** |
| **Automatic failover** | the project's `failover` is `automatic` (the default) and the primary's check has failed for `globalDns.failover.afterSeconds` (180) | the same failover, to the first region in HA order that serves the active version, answers its check and reaches its data. `failover: manual` leaves it to an operator; DNS still answers the other regions meanwhile |

**Fencing and rejoin.** A fenced region is out of the DNS answers and its triggers are stopped (as
soon as its cluster answers). RunR checks every minute; when the region's cluster answers again
it **rejoins**: its old Postgres cluster — a primary of the old timeline — is deleted with its
volumes and cloned again from the new primary, the active version is rolled out to it, and it
serves as a secondary once its copy is ready and its replica streams. It becomes the primary again
only by a switchover.

**What RunR sees.** The automatic failover acts on RunR's view: the health route checked from the
control plane, the same check PowerDNS runs from every server. A primary that is cut off from
RunR but still reachable by clients keeps its own DNS server's old zone (RunR cannot write to it)
and keeps accepting writes until RunR reaches it; what it wrote meanwhile is gone when it rejoins.
Choose `failover: manual` where the regions' own network is less reliable than RunR's view of
them, and fail over by hand. The Regions tab names these limits — and the queue messages left in
the old primary's stream — where automatic failover is chosen.

Audit: `project.global.failover` (by request, or with actor `system` when automatic),
`project.global.rejoin` (system).

## TLS

With the regions' cert-manager — the Serving install sets it up, as for custom domains
(`globalDns.tls.mode: acme`, the default) — every region gets the wildcard certificate `*.<zone>` from an ACME CA (Let's Encrypt unless `globalDns.tls.acmeServer`)
with a DNS-01 challenge:

- an `Issuer` `runr-global-dns` in the region's project namespace sends RFC 2136 updates, signed
  with RunR's TSIG key (Secret `runr-global-dns-tsig`), to the **update server** — the ready
  server of the oldest region;
- the update server accepts nothing but TXT records under `_acme-challenge.` signed with that key
  (a DNS UPDATE policy script); RunR's zone sync copies them to the other servers, so whichever
  name server the CA asks has them;
- the `Certificate` `runr-global-wildcard` lands in a Secret of that name, which the HTTPS routes of
  every global project in the region use.

cert-manager in each region must reach the update server's address on port 53. The ACME account's
e-mail is `globalDns.tls.acmeEmail`, else the region's cert-manager ACME e-mail (region setting
`cert_manager_acme_email` or `knative.certManager.acmeEmail`): one address serves both. With
`globalDns.tls.mode: none`, or while the region's cert-manager is not ready, the routes answer HTTPS
with the region Traefik's default certificate; the server's TLS status says why. A regional
project's custom domains keep their HTTP-01-style certificates per host; a global project's are
issued through the zone ([Custom domains](#custom-domains)).

The same Issuer has a second solver, with `cnameStrategy: Follow` and no selector: it serves the
custom domains of global projects.

## Custom domains

A global project's custom domain is served by **every region whose copy serves**, answered by the
global DNS:

1. **Ownership:** the TXT record `_runr-challenge.<domain>` as for any domain.
2. **Routing:** `<domain>` is a CNAME of the project's global host name `<slug>.<zone>` — the
   answers then follow the health checks. At a zone apex (no CNAME) A / AAAA records of the
   regions' addresses are accepted, without failover.
3. **TLS** (`cert-manager`, the default): a CNAME `_acme-challenge.<domain>` →
   `_acme-challenge.d-<hash>.<zone>` (the console and the API's `acme_challenge` show it). Every
   region's cert-manager issues the domain's certificate itself through the zone's Issuer
   (DNS-01, following that CNAME into the global zone), whichever region the CA would reach over
   HTTP. Without the global TLS (`globalDns.tls.mode: none`, a region's cert-manager not ready)
   the region's ClusterIssuer is used (HTTP-01: it succeeds in the region the CA's request lands
   in). An uploaded certificate (`custom`) is copied from the primary into every region.

Each region gets the domain's Middleware, IngressRoutes and Certificate when its copy becomes
ready, and loses them when it leaves the project; `regions` of the domain lists every region's
state (`active` / `error`, the certificate's message and expiry).

## Gateways

A gateway ([api-product.md](api-product.md)) lives in one region. A route of a gateway in **any of
the project's regions** may lead to a global project: the gateway hands requests to that region's
copy. The gateway's host is regional — for failover clients use the global host name or several
gateways. Refused: a gateway of a region the project does not run in, and "only through the
gateway" (`gateway_only`), which would close the global host name. A region with a gateway route
to the project cannot leave it until the route is removed.

## Data

The copies run in different clusters. How each kind of binding ([bindings.md](bindings.md)) is
reached from every region:

| Binding | In a global project |
| --- | --- |
| External | as it is — every region reaches it over the network; regions far from it are slower (warning on the Regions tab) |
| Object storage on an external S3 endpoint | as it is (the endpoint is reached from every region) |
| KV (Redis), object storage on the managed store, Postgres `shared` | the data stays in the binding's region (its **home**); the other regions reach it through the binding's [data link](#the-data-link) with the same credentials — one keyspace, one bucket, one database for every region, slower from the other regions, **unavailable while the home region is down** |
| Postgres `dedicated` | a **replica cluster in every region** ([Postgres across regions](#postgres-across-regions)): reads stay in the region, writes go to the primary, a failover promotes a replica |
| Dynamic credentials | refused ([below](#what-a-global-project-cannot-use)) |
| Private database connections, model services | refused ([below](#what-a-global-project-cannot-use)) |

A binding of a global project must be in one of its regions; a dedicated Postgres binding in its
primary (its replicas follow the primary), and it serves one global project. A region that holds a
binding of the project cannot leave the project before the binding is detached.

### The data link

```text
 us-1 (data namespace runr-data)                  eu-1 — the binding's home
 ┌────────────────────────────────┐               ┌──────────────────────────────────────────┐
 │ Service runr-link-<binding>    │ TLS :443, SNI │ Traefik websecure: IngressRouteTCP       │
 │ nginx stream, 2 pods ──────────┼──────────────▶│ HostSNI(<binding>.data-link.runr.internal)│
 │ client certificate of the link │ mutual TLS    │ TLSOption: the link CA's clients only    │
 │   ▲ the copy connects in plain │               │   ──▶ runr-kv / runr-objects / pg-…-rw    │
 └───┼────────────────────────────┘               └──────────────────────────────────────────┘
     us-1's copy (REDIS_URL / S3_ENDPOINT / DATABASE_URL name runr-link-<binding>)
```

- Each binding has a CA of its own (EC P-256, ten years; the key encrypted in
  `bindings.replication`), a server certificate for its SNI name and a client certificate. The
  home's Traefik ends TLS only for clients of that CA, so a link reaches its own binding and
  nothing else; the forwarders verify the home's certificate against the same CA.
- Inside the link flows the data service's own protocol: Postgres negotiates its own TLS, Redis
  and S3 authenticate with the binding's credentials as before.
- Home: Secrets `runr-link-<binding>-server` / `-ca`, TLSOption and IngressRouteTCP
  `runr-link-<binding>` (data namespace), a NetworkPolicy that lets Traefik's pods reach the
  service. Other regions: Secret `-client`, ConfigMap, Deployment and Service `runr-link-<binding>`,
  a NetworkPolicy that admits the project's pods. The forwarder image is
  `globalDns.dataLink.image` (RunR's nginx mirror), the port `globalDns.dataLink.port` (443).
- The env of a region's copy names the link's host instead of the in-cluster one; everything else
  (user, password, bucket, prefix) is the binding's.

### Postgres across regions

A dedicated Postgres binding attached to a global project becomes a **CloudNativePG distributed
topology** (CloudNativePG 1.24 or later; RunR installs chart `0.26.0`, operator 1.27): every region
of the project runs a Cluster of the same name `pg-<binding>` in its data namespace.

- **The home** (the project's primary region) runs the primary. It names itself in
  `spec.replica.primary`; its bootstrap is the binding's own (`initdb`).
- **Every other region** runs a replica cluster, bootstrapped with `pg_basebackup` from the home
  and streaming from it through the data link as CloudNativePG's `streaming_replica`, with the
  home's client certificate (copied as `pg-<binding>-src-replication` / `-src-ca`;
  `sslmode=verify-ca`). The superuser and owner Secrets are the home's in every region, so a
  promoted replica keeps the binding's passwords. Instances and storage follow the binding's
  settings in every region.
- **Env** of each region's copy: `DATABASE_URL`, `PGHOST`, … — the **primary**: in the home its
  own `-rw` Service, elsewhere the data link (writes cross regions); `DATABASE_URL_RO`,
  `PGHOST_RO` — **the region's own replica** (`pg-<binding>-r`): reads stay in the region and see
  the primary's writes a moment later (asynchronous replication). Write through `DATABASE_URL`,
  read what must be current through it too, and everything else through `DATABASE_URL_RO`.
- **Status:** each region's replica — streaming, replay lag — on the Regions tab, in
  `GET …/global` (`bindings`) and in `binding_replicas`; a region whose replica does not stream yet
  is out of the DNS answers.
- **Switchover** (planned): CloudNativePG demotes the home (`spec.replica.primary` names the new
  primary), RunR waits for the demotion token and promotes the new primary with it
  (`promotionToken`) — it promotes once it replayed the old primary's last WAL; the other replicas
  and the old home follow the new primary. Writes stop for the seconds in between.
- **Failover** (the home does not answer): the replica is promoted without a token at once; RPO =
  what had not reached it. The old home is rebuilt as a replica when it rejoins
  ([Failover](#failover)).
- **Backups** (binding setting `backups`) run in the home, to the home region's object store; the
  ScheduledBackup moves with the home.
- **Rotation** changes the owner password in the home; it reaches the replicas through the WAL,
  their copy of the owner Secret follows.
- **Removal:** a region that leaves the project, a detach or the binding's purge delete the
  replica clusters (with their volumes) and the link objects there.

KV and object storage have no replicas: Redis replication and the managed object store copy a
whole instance — every binding's data on it — and both are single-node per region. Their data stays
in its home; use Postgres `dedicated` for data that must survive the loss of a region.

## Calls between projects

A global project is called by other projects through its global host name today. The internal
network (per-project internal names and an allow list of calling projects) resolves a global
project through one hook, `App\Services\GlobalProjects\GlobalInternalEndpoint::resolve()`: when the
target has a serving copy in the caller's region, the call goes to that copy cluster-locally
(`<slug>.<project namespace>.svc`) and never leaves the region; otherwise to the global host name.

## Usage, billing and capacity

- **Usage** is recorded per region: each region's agent reports the sessions of its copy with its
  region, and an always-on (minimum scale) project reserves its size once per region its copy runs
  in. A bill therefore prices each region's usage at that region's prices.
- **Capacity:** every copy counts in its region's capacity; a global project is admitted only when
  its size fits every region. Its GPUs count in every region's GPU quota.
- **Bindings:** a dedicated Postgres binding's replica clusters are metered with the binding — their
  running instances add to its instance hours, their copies of the data to its storage GB-hours —
  on the binding's record (its home region's prices, [bindings.md](bindings.md#usage-and-billing)).
  The data links' forwarders (2 × 20m CPU, 24 MiB) are not metered.

## What each region needs

A global project is admitted (created, a region added, its config changed, deployed) only when
**every one of its regions** offers what it uses; the error names the region:

| The project uses | Every region needs |
| --- | --- |
| A WebAssembly runtime | the region's WebAssembly support with the runtime's shim ([wasm.md](wasm.md)) — each region builds the module itself |
| A queue or topic trigger (also a workflow with a queue stream) | Redis — the triggers run in the primary, which can move to any region |
| GPUs | GPU nodes, product and memory as asked, the sandbox, and the organization's GPU quota there ([ai.md](ai.md)) |
| An uploaded CaaS image | an internal registry to copy it into |

## What a global project cannot use

| Refused | Why |
| --- | --- |
| Private database connections (Skupper) | the connection's Skupper link is made once, with a one-time token, in one region's namespace: the copies in the other regions have no link to the database. Reach the database with an external binding (its address and credentials) |
| Model services | a model service runs cluster-local in one region (its URL is that cluster's, its weights on that region's volume) and admits callers of that cluster only. Call a model over HTTP through an external binding |
| Dynamic credentials of a Postgres binding | OpenBao's database connection and the users it issues live in one region; the copies in the other regions could not read them. Attach with static credentials |
| A dedicated Postgres binding outside the primary, or one another global project replicates | its replicas follow one project's primary |
| A gateway route "only through the gateway", or from a gateway outside the project's regions | [Gateways](#gateways) |
| A move with `PUT /projects/{project}` (`knative_region_id`) | regions change under Regions (`PUT /projects/{project}/global`) |

## API

| Method and path | Permission | What it does |
| --- | --- | --- |
| `POST /api/projects` with `placement: "global"` | `projects.create` | creates a global project ([Creating](#creating-a-global-project)) |
| `GET /api/projects/{project}/global` | `projects.view` | mode, failover setting, a running move (`moving_to`), host name, primary, every region's copy (status — also `fenced` —, version, target version, health, `unhealthy_since`, in DNS, `triggers_active`, `data_status`), the bindings across the regions (`bindings`: access `direct` / `link` / `replicas`, each region's state and replay lag), warnings |
| `PUT /api/projects/{project}/global` | `projects.update` | `mode`, `failover` (`automatic` / `manual`), `primary_region_id` (a switchover), `region_ids` |
| `POST /api/projects/{project}/global/failover` | `projects.update` | a failover to `region_id` (else the first healthy region in HA order); 202 |
| `POST /api/projects/{project}/global/sync` | `projects.deploy` | rolls the active version out to the regions that are not ready |
| `GET /api/global-dns` | `regions.view` | zone, delegation records and status, servers, update server, last sync |
| `POST /api/global-dns/sync` | `regions.manage` | syncs the zone now |
| `GET /api/knative-regions/{id}/global-dns` | `regions.view` | the region's server, whether the region can run one |
| `POST /api/knative-regions/{id}/global-dns` | `regions.manage` | installs the region's server |
| `DELETE /api/knative-regions/{id}/global-dns` | `regions.manage` | removes it (422 while global projects run there) |
| `PUT /api/knative-regions/{id}/global-dns/geoip` | `regions.manage` | the server's GeoIP source: `source` (`default`, `url`, `file`, `none`), `url` |
| `POST /api/knative-regions/{id}/global-dns/geoip/reload` | `regions.manage` | the server reads its GeoIP source again (restarts) |

Projects carry `placement` (`regional` | `global`) and, for a global project, the `global` object;
regions carry `global_dns_ready` and `global_dns` (status, address, name server, `geoip`). A global
project's custom domain carries `acme_challenge` (the CNAME of its certificates) and `regions`.

## CLI, MCP and console

- **CLI** ([cli.md](cli.md)): `global:` in `runr.yaml` (`regions`, `mode`; `region` is the primary)
  or `runr deploy --global-regions eu-1,us-1 [--region eu-1] [--global-mode proximity]` creates a
  global project with its first deploy; `runr projects global <project>`, `runr projects global
  set` / `sync`; `runr regions global-dns [status | enable | disable] <region>`; `runr global-dns`
  and `runr global-dns sync`; `runr projects global set --failover automatic|manual`, `runr projects global failover
  <project> [--region R]`, `runr regions global-dns geoip <region> [--source
  default|url|file|none] [--url URL] [--reload]`; `runr projects global` shows the failover
  setting, where the triggers run, each region's data status and every binding's access.
- **MCP** ([mcp.md](mcp.md)): `project_create` with `regions` and `mode`, `project_global_get`,
  `project_global_update` (with `failover`), `project_global_failover`, `global_dns_status`.
- **Console:** the project type choice in New project, the project's Regions tab (failover,
  Fail over, the data across regions), Region → General → Global DNS (with the GeoIP source),
  Administration → Regions & settings → Global DNS, a custom domain's records and regions.

## Helm values

| Value | Default | Meaning |
| --- | --- | --- |
| `globalDns.zone` | `g.<base domain>` | the global zone |
| `globalDns.namespace` | `runr-global-dns` | namespace of the server in a region |
| `globalDns.image` | `ghcr.io/brf-tech/runr/reqs/pdns-auth:5.0.8` | PowerDNS Authoritative |
| `globalDns.storageClass` | the cluster default | the server's 1 GiB volume (zone database, GeoIP database) |
| `globalDns.serviceAnnotations` | `{}` | annotations of the DNS LoadBalancer Service (e.g. a fixed address) |
| `globalDns.ttl` | `30` | TTL of the project records (seconds) |
| `globalDns.maxRegions` | `8` | regions of one global project |
| `globalDns.geoipUrl` | DB-IP City Lite, `{yyyy}-{mm}` of the current month | GeoIP database of proximity mode; `-`: none |
| `globalDns.tls.mode` | `acme` | `acme`: DNS-01 wildcard per region; `none`: the region's default certificate |
| `globalDns.tls.acmeServer` | Let's Encrypt production | ACME directory |
| `globalDns.tls.acmeEmail` | — | contact address of the ACME account |
| `globalDns.failover.afterSeconds` | `180` | seconds of failed health checks before an automatic failover |
| `globalDns.dataLink.image` | `ghcr.io/brf-tech/runr/reqs/nginx:1.27-alpine` | the data link's forwarder (nginx stream) |
| `globalDns.dataLink.port` | `443` | port of the home region's Traefik the data link reaches (TLS with SNI) |

The images come from RunR's reqs mirror; air-gapped installs mirror them as the other `reqs/`
images. `globalDns.geoipUrl` is the platform default of every region's `default` GeoIP source.

## How it works

| Piece | Where |
| --- | --- |
| Regions of a project, state of each copy | `project_regions` (per organization), `App\Models\ProjectRegion` |
| A region's server: status, address, API key (encrypted), sync and TLS state | `region_global_dns` (central), `App\Models\RegionGlobalDns` |
| Server objects (Deployment, Services, config, DNS UPDATE policy, init script) | `App\Services\GlobalDns\PowerDnsManifests` |
| Install, readiness, removal | `GlobalDnsServerService`, `CheckGlobalDnsServerJob`, `RemoveGlobalDnsServerJob` |
| The zone: NS, glue, one LUA rrset per project | `GlobalZoneBuilder` (pure), `GlobalZoneSync`, `SyncGlobalZoneJob` (every minute) |
| Every organization's global projects in the zone | `GlobalZoneProjects` (+ the Tenancy module's `TenantGlobalZoneSource`) |
| Health route and wildcard certificate of a region | `GlobalRegionSetup`, `GlobalTlsManifests` |
| Routes of `<slug>.<zone>` in a region | `App\Services\GlobalProjects\GlobalRouteManifests`, `GlobalRouteService` |
| Rollout to the other regions | `GlobalReplicaDeployer`, `RolloutGlobalReplicaJob`, `RemoveGlobalReplicaJob` |
| The agent's health listener | `runtime/agent/internal/globalhealth` |
| Triggers in the primary only, standby elsewhere | `GlobalTriggers` |
| Copies of an uploaded image | `GlobalImageCopy`, `CopyGlobalImageJob` |
| Custom domains in every region | `GlobalDomains` (+ `ProjectDomainService`), `project_domains.region_states` |
| Switchover, failover, fencing, rejoin, the minute's health check | `GlobalFailover`, `MoveGlobalPrimaryJob`, `GlobalProjectsMaintenanceJob('failover')` |
| Bindings across regions: rows, data links, replica clusters, env per region | `App\Services\GlobalProjects\Data\RegionalData`, `DataLinkManifests`, `DataLinkCertificates`, `RegionalPostgresManifests`, `binding_replicas` (`App\Models\BindingReplica`), `GlobalProjectsMaintenanceJob('data')` |
| GeoIP source and status of a server | `GlobalDnsServerService::setGeoip()` / `refreshGeoip()`, `PowerDnsManifests::prepareScript()` |
| Upgrades | `region:reconcile` step `global-dns`: the server's image and configuration (restarts the server once after this release: its init script changed), the agent's Service |

A project record, HA, regions eu-1 (primary), us-1 and ap-1:

```text
shop.g.example.com. 30 IN LUA A "ifurlup('http://runr-health.g.example.com/global-health/shop',
    {{'203.0.113.10'}, {'198.51.100.10', '192.0.2.10'}},
    {selector='all', backupSelector='all', timeout=2, minimumFailures=2, stringmatch='ok'})"
```

In proximity mode the regions form one group and the selector is `pickclosest`. LUA records are
written by RunR's zone sync only; DNS UPDATE never accepts them.

## Audit

| Action | When |
| --- | --- |
| `project.global.update` | mode, primary or regions changed (with the new values) |
| `project.global.sync` | a sync of a project's regions |
| `project.global.failover` | a failover (request; actor `system` when automatic) |
| `project.global.rejoin` | a fenced region rejoined (actor `system`) |
| `region.global_dns.geoip` / `region.global_dns.geoip_reload` | a server's GeoIP source changed / read again |
| `region.global_dns.enable` / `region.global_dns.disable` | a region's server installed / removed |
| `global_dns.sync` | a zone sync asked for |

Creating a global project is `project.create`; changes of its regions' rows are part of the
change record of the request.

## Testing

- PHPUnit: `tests/Unit/GlobalDns` (zone builder, PowerDNS and certificate manifests, the DNS
  client, routes), `tests/Unit/GlobalProjects` (data link, replica topology, link certificates,
  standby triggers, image copies) and `tests/Feature/GlobalProjects` (API, rollout, zone sync,
  global DNS API, reconcile step, regional data, failover, GeoIP and custom domains).
- Go: `runtime/agent/internal/globalhealth` (the health listener); the CLI's `global_test.go`.
- Console: Vitest under `vue/tests` and `vue/e2e/global-projects.spec.ts` (mocked API).
- Kind: `bash runtime/ci.sh test-global-projects` creates a second Kind cluster next to the e2e
  region's and makes it a second region (two clusters, not two regions on one: each region needs
  its own Traefik address, agent and DNS server). It enables global DNS on both, deploys a global
  nginx project, checks the rollout, both Traefik addresses, the health routes, identical zones,
  the HA answer of both servers (over TCP), the failover when the primary's copy goes, proximity
  mode and the deletion. Then the phase-2 cases: the triggers on standby, a gateway of region B,
  a custom domain in both regions, an uploaded image copied into B, the data services in both
  regions, a KV binding through its data link, a dedicated Postgres binding streaming into B's
  replica (written in A, read in B, written through B's link), a switchover to B, a failover back
  to A with the rejoin of B, and the GeoIP sources. The delegation and the ACME certificates (the
  wildcard and custom domains) cannot be tested on Kind.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| The region's server stays `installing` with "Waiting for the load balancer address" | the cluster's load balancer gives the Service `runr-global-dns/runr-pdns` no address; it needs one with UDP and TCP 53 |
| Ready but not reachable | the load balancer or a firewall does not pass port 53; `dig @<address> <zone> SOA` from outside |
| The global host name does not resolve | `GET /api/global-dns`: delegation `missing` / `partial` — the NS and glue records in the parent zone |
| It resolves to a region whose copy is down | the TTL (30 s) and two failed checks (10 s); the copy's Knative Service and the region's health route (`curl -H 'Host: runr-health.<zone>' http://<region address>/global-health/<slug>`) |
| A region stays `failed` | its status message; Sync now after fixing the cause |
| TLS status `error`: the region's cert-manager is not ready | the region's General tab → cert-manager (Install again) or `php artisan region:reconcile`, or set `globalDns.tls.mode: none` |
| TLS `pending` for long | the zone must be delegated (the CA asks public DNS), and the region's cert-manager must reach the update server on port 53 |
| The zone shows drift every minute | something else writes to a server; only RunR may |
| GeoIP `missing` | the message names the download error: a cluster without internet access needs a URL it reaches or the file on the volume ([GeoIP database](#geoip-database)) |
| A region's `data_status` stays `syncing` | `bindings` of `GET /global`: a link's forwarder not available (`kubectl -n runr-data get deploy runr-link-<binding>`), a replica not streaming — the home's Traefik must receive TLS on 443 itself, and the home region's address must be reachable from the region |
| A region is `fenced` | it lost the primary in a failover; it rejoins by itself once its cluster answers (`project.global.rejoin` in the audit log) |
| A switchover stopped | the region's status message names the binding and why; the project stays where it was |
| A custom domain's certificate stays pending in every region | the CNAME `_acme-challenge.<domain>` (the domain's `acme_challenge`) is missing, or the zone is not delegated |
