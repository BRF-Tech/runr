import type { Json, MockDb } from '../e2e/fixtures/data'
import { READY_REGION } from '../e2e/fixtures/data'

// The dataset of the showcase screenshots: the fictional organization "Northwind" on the
// regions eu-1 and us-1. Every name, address and host name is made up (RFC 5737 addresses,
// *.example / *.runr.example.com host names). Applied over the e2e default dataset
// (e2e/fixtures/data.ts) by applyShotsDb(); a scene adds what it needs on top.

export const NOW = Date.now()
/** An ISO timestamp `minutes` ago. */
export const ago = (minutes: number): string => new Date(NOW - minutes * 60_000).toISOString()
export const DAY = 24 * 60
export const DOMAIN = 'runr.example.com'

export const USER: Json = {
  id: 1,
  name: 'Alex Chen',
  username: 'alex',
  email: 'alex.chen@northwind.example',
  is_admin: true,
  avatar_url: null,
  two_factor_confirmed_at: ago(30 * DAY),
  tutorial_completed_at: ago(40 * DAY),
  created_at: ago(90 * DAY),
}

export const PEOPLE = {
  alex:  { id: 1, name: 'Alex Chen' },
  priya: { id: 2, name: 'Priya Patel' },
  jonas: { id: 3, name: 'Jonas Weber' },
  mei:   { id: 4, name: 'Mei Lin' },
}

export const EU: Json = {
  ...READY_REGION,
  id: 'r-eu',
  name: 'eu-1',
  project_namespace: 'eu-1',
  server_url: 'https://10.20.0.10:6443',
  external_ip: '203.0.113.10',
  knative_serving_version: '1.17.0',
  selected_workers: ['eu-1-worker-1', 'eu-1-worker-2', 'eu-1-worker-3'],
  total_cpu_m: 48000,
  total_memory_mb: 98304,
  used_cpu_m: 13750,
  used_memory_mb: 29696,
  knative_settings: { request_timeout: 300, knative_domain: `eu-1.${DOMAIN}` },
}

export const US: Json = {
  ...EU,
  id: 'r-us',
  name: 'us-1',
  project_namespace: 'us-1',
  server_url: 'https://10.30.0.10:6443',
  external_ip: '198.51.100.20',
  selected_workers: ['us-1-worker-1', 'us-1-worker-2'],
  total_cpu_m: 32000,
  total_memory_mb: 65536,
  used_cpu_m: 5250,
  used_memory_mb: 12288,
  knative_settings: { request_timeout: 300, knative_domain: `us-1.${DOMAIN}` },
}

export const EXECUTOR_IMAGES: Json[] = [
  { id: 1, runtime: 'python', version: '3.12', is_default: true, docker_image: 'ghcr.io/brf-tech/runr/exec-python:3.12', eol_at: null },
  { id: 2, runtime: 'python', version: '3.11', is_default: false, docker_image: 'ghcr.io/brf-tech/runr/exec-python:3.11', eol_at: null },
  { id: 3, runtime: 'nodejs', version: '22', is_default: true, docker_image: 'ghcr.io/brf-tech/runr/exec-nodejs:22', eol_at: null },
  { id: 4, runtime: 'nodejs', version: '20', is_default: false, docker_image: 'ghcr.io/brf-tech/runr/exec-nodejs:20', eol_at: null },
  { id: 5, runtime: 'go', version: '1.23', is_default: true, docker_image: 'ghcr.io/brf-tech/runr/exec-go:1.23', eol_at: null },
  { id: 6, runtime: 'php', version: '8.3', is_default: true, docker_image: 'ghcr.io/brf-tech/runr/exec-php:8.3', eol_at: null },
]

const baseConfig: Json = {
  cpu: '250m',
  memory_mb: 256,
  timeout_seconds: 30,
  trigger_type: 'http',
  schedule: null,
  queue_stream: null,
  scale_to_zero: true,
  min_scale: 0,
  max_scale: 5,
  scale_schedule: null,
  env_vars: {},
}

export interface ProjectSpec {
  id: string
  name: string
  description: string
  type: 'xaas' | 'faas' | 'caas'
  runtime?: string | null
  version?: string | null
  region?: string
  config?: Json
  createdBy?: Json
  createdDaysAgo?: number
  extra?: Json
}

export function makeProject(spec: ProjectSpec): Json {
  const region = spec.region ?? 'r-eu'
  const regionName = region === 'r-us' ? 'us-1' : 'eu-1'
  const config = { ...baseConfig, ...(spec.config ?? {}) }
  if (spec.type === 'xaas') config.filename = config.filename ?? 'main.py'
  const slug = spec.name
  const http = config.trigger_type === 'http' || config.trigger_type === 'workflow'
  return {
    id: spec.id,
    name: spec.name,
    slug,
    description: spec.description,
    service_type: spec.type,
    runtime: spec.type === 'caas' ? null : (spec.runtime ?? 'python'),
    runtime_version: spec.type === 'caas' ? null : (spec.version ?? '3.12'),
    runtime_eol: null,
    knative_region_id: region,
    region: { id: region, name: regionName },
    endpoint_type: 'subdomain',
    endpoint_url: http ? `https://${slug}.${regionName}.${DOMAIN}` : null,
    gateway_route: null,
    placement: 'regional',
    global: null,
    deploy_strategy: 'direct',
    config,
    created_by: spec.createdBy ?? PEOPLE.alex,
    created_at: ago((spec.createdDaysAgo ?? 30) * DAY),
    updated_at: ago(60),
    ...(spec.extra ?? {}),
  }
}

export const version = (id: string, label: string, message: string, minutesAgo: number, extra: Json = {}): Json => ({
  id, label, number: Number(label.replace(/^v/, '')), commit_message: message, build_status: 'ready', created_at: ago(minutesAgo),
  created_by: PEOPLE.alex, ...extra,
})

export const deployment = (id: string, versionId: string, label: string, status: string, job: string, by: string, minutesAgo: number, extra: Json = {}): Json => ({
  id, status, version: { id: versionId, label }, version_id: versionId, k8s_job_name: job, deployed_by: by, created_at: ago(minutesAgo), ...extra,
})

/** The showcase projects, in the order of the projects page (the newest first). */
export const PROJECTS: Json[] = [
  makeProject({
    id: 'p-image-resizer', name: 'image-resizer', type: 'xaas', runtime: 'python', version: '3.12',
    description: 'Resizes uploaded product photos into thumbnails and WebP variants, stores them in the media bucket.',
    config: { memory_mb: 512, timeout_seconds: 60, max_scale: 20, env_vars: { OUTPUT_BUCKET: 'media', MAX_EDGE: '1600' } },
    createdDaysAgo: 74, extra: { latest_version: { id: 'v-ir-5', label: 'v5', build_status: 'ready' }, latest_deployment: { id: 'd-ir-5', status: 'active', version: { id: 'v-ir-5', label: 'v5' } } },
  }),
  makeProject({
    id: 'p-order-webhooks', name: 'order-webhooks', type: 'faas', runtime: 'nodejs', version: '22',
    description: 'Receives shop webhooks, verifies their signatures and fans the orders out to the fulfilment topics.',
    config: { handler: 'index.handler', scale_to_zero: false, min_scale: 1, max_scale: 8, memory_mb: 512, env_vars: { LEDGER_URL: 'http://ledger.eu-1.svc' } },
    createdBy: PEOPLE.priya, createdDaysAgo: 61,
    extra: { latest_version: { id: 'v-ow-8', label: 'v8', build_status: 'ready' }, latest_deployment: { id: 'd-ow-7', status: 'active', version: { id: 'v-ow-7', label: 'v7' } } },
  }),
  makeProject({
    id: 'p-checkout-api', name: 'checkout-api', type: 'faas', runtime: 'go', version: '1.23',
    description: 'Checkout and payment orchestration for the storefront; rolls out as a canary.',
    config: { handler: 'main', cpu: '500m', memory_mb: 512, scale_to_zero: false, min_scale: 2, max_scale: 12, timeout_seconds: 20 },
    createdBy: PEOPLE.jonas, createdDaysAgo: 48,
    extra: { deploy_strategy: 'canary', latest_version: { id: 'v-ca-12', label: 'v12', build_status: 'ready' }, latest_deployment: { id: 'd-ca-12', status: 'running', version: { id: 'v-ca-12', label: 'v12' } } },
  }),
  makeProject({
    id: 'p-nightly-report', name: 'nightly-report', type: 'xaas', runtime: 'python', version: '3.12',
    description: 'Builds the daily sales report from the warehouse and mails it to finance at 02:00.',
    config: { trigger_type: 'schedule', schedule: '0 2 * * *', timeout_seconds: 300, max_scale: 1, memory_mb: 1024 },
    createdBy: PEOPLE.mei, createdDaysAgo: 40,
    extra: { latest_version: { id: 'v-nr-3', label: 'v3', build_status: 'ready' }, latest_deployment: { id: 'd-nr-3', status: 'active', version: { id: 'v-nr-3', label: 'v3' } } },
  }),
  makeProject({
    id: 'p-inference-api', name: 'inference-api', type: 'caas',
    description: 'ONNX Runtime serving the recommendation model on the GPU workers.',
    config: { image: 'ghcr.io/northwind/inference-api:2.4.1', ports: [8000], command: null, image_pull_policy: 'IfNotPresent', cpu: '2', memory_mb: 8192, timeout_seconds: 120, scale_to_zero: true, min_scale: 0, max_scale: 2 },
    createdBy: PEOPLE.mei, createdDaysAgo: 22,
    extra: { latest_version: { id: 'v-ia-4', label: 'v4', build_status: 'ready' }, latest_deployment: { id: 'd-ia-4', status: 'active', version: { id: 'v-ia-4', label: 'v4' } } },
  }),
  makeProject({
    id: 'p-payment-events', name: 'payment-events', type: 'faas', runtime: 'nodejs', version: '22',
    description: 'Consumes payment events from the stream and keeps the ledger in step.',
    config: { handler: 'consumer.handler', trigger_type: 'queue', queue_stream: 'payment-events', max_scale: 4 },
    createdBy: PEOPLE.priya, createdDaysAgo: 35,
    extra: { latest_version: { id: 'v-pe-6', label: 'v6', build_status: 'ready' }, latest_deployment: { id: 'd-pe-6', status: 'active', version: { id: 'v-pe-6', label: 'v6' } } },
  }),
  makeProject({
    id: 'p-weather-tools', name: 'weather-tools', type: 'faas', runtime: 'python', version: '3.12',
    description: 'Hosted MCP server: forecast and alert tools for the support agents.',
    config: { handler: 'server.handler', max_scale: 3 },
    createdDaysAgo: 12,
    extra: {
      mcp_server: { handle: 'weather-tools', url: `https://console.${DOMAIN}/mcp/servers/weather-tools`, access_mode: 'oauth' },
      latest_version: { id: 'v-wt-3', label: 'v3', build_status: 'ready' }, latest_deployment: { id: 'd-wt-3', status: 'active', version: { id: 'v-wt-3', label: 'v3' } },
    },
  }),
  makeProject({
    id: 'p-shop', name: 'shop', type: 'caas',
    description: 'Storefront served from eu-1 and us-1 behind one global host name.',
    config: { image: 'ghcr.io/northwind/storefront:5.2.0', ports: [3000], command: null, image_pull_policy: 'IfNotPresent', cpu: '500m', memory_mb: 1024, scale_to_zero: false, min_scale: 2, max_scale: 10 },
    createdBy: PEOPLE.jonas, createdDaysAgo: 90,
    extra: { placement: 'global', endpoint_url: `https://shop.g.${DOMAIN}`, latest_version: { id: 'v-sh-9', label: 'v9', build_status: 'ready' }, latest_deployment: { id: 'd-sh-9', status: 'active', version: { id: 'v-sh-9', label: 'v9' } } },
  }),
  makeProject({
    id: 'p-invoice-pdf', name: 'invoice-pdf', type: 'faas', runtime: 'php', version: '8.3',
    description: 'Renders invoices to PDF for the billing portal.',
    config: { handler: 'index.php', memory_mb: 512, max_scale: 6 },
    createdDaysAgo: 55,
    extra: { latest_version: { id: 'v-ip-2', label: 'v2', build_status: 'ready' }, latest_deployment: { id: 'd-ip-2', status: 'active', version: { id: 'v-ip-2', label: 'v2' } } },
  }),
]

export const RESIZER_CODE = `import io
import os
import boto3
from PIL import Image, ImageOps

# Bindings inject the bucket credentials as environment variables.
s3 = boto3.client(
    "s3",
    endpoint_url=os.environ["RUNR_S3_MEDIA_ENDPOINT"],
    aws_access_key_id=os.environ["RUNR_S3_MEDIA_ACCESS_KEY"],
    aws_secret_access_key=os.environ["RUNR_S3_MEDIA_SECRET_KEY"],
)
BUCKET = os.environ.get("OUTPUT_BUCKET", "media")
SIZES = {"thumb": 240, "card": 640, "zoom": int(os.environ.get("MAX_EDGE", "1600"))}


def handler(event, context):
    """POST /resize  { "key": "uploads/sku-4821.jpg" }"""
    key = event["body"]["key"]
    source = s3.get_object(Bucket=BUCKET, Key=key)["Body"].read()
    image = ImageOps.exif_transpose(Image.open(io.BytesIO(source)))

    variants = {}
    for name, edge in SIZES.items():
        copy = image.copy()
        copy.thumbnail((edge, edge), Image.LANCZOS)
        buffer = io.BytesIO()
        copy.save(buffer, format="WEBP", quality=82, method=6)
        target = f"derived/{name}/{key.rsplit('/', 1)[-1]}.webp"
        s3.put_object(Bucket=BUCKET, Key=target, Body=buffer.getvalue(), ContentType="image/webp")
        variants[name] = {"key": target, "width": copy.width, "height": copy.height}

    print(f"resized {key} into {len(variants)} variants")
    return {"statusCode": 200, "body": {"source": key, "variants": variants}}
`

export const RESIZER_VERSIONS: Json[] = [
  version('v-ir-5', 'v5', 'Read the output bucket from the binding', 3 * 60),
  version('v-ir-4', 'v4', 'Respect EXIF orientation before resizing', 2 * DAY),
  version('v-ir-3', 'v3', 'Add the zoom variant, WebP method 6', 6 * DAY),
  version('v-ir-2', 'v2', 'Lanczos resampling, quality 82', 11 * DAY),
  version('v-ir-1', 'v1', 'Initial version', 74 * DAY),
]

export const WEBHOOKS_INDEX = `import { verifySignature } from './signature.js'
import { publish } from './topics.js'

export const handler = async (event) => {
  const ok = verifySignature(event.headers, event.rawBody, process.env.SHOP_WEBHOOK_SECRET)
  if (!ok) return { statusCode: 401, body: { error: 'bad signature' } }

  const order = JSON.parse(event.rawBody)
  await publish('order.events', { type: 'order.created', orderId: order.id, total: order.total })
  return { statusCode: 202, body: { received: order.id } }
}
`

export const WEBHOOKS_VERSIONS: Json[] = [
  version('v-ow-8', 'v8', 'Fan out refunds to the ledger topic', 40, { created_by: PEOPLE.priya }),
  version('v-ow-7', 'v7', 'Verify Stripe and Shopify signatures', 2 * DAY + 60, { created_by: PEOPLE.priya }),
  version('v-ow-6', 'v6', 'Retry topic publish with backoff', 5 * DAY),
  version('v-ow-5', 'v5', 'Add order.cancelled event', 9 * DAY),
  version('v-ow-4', 'v4', 'Structured JSON logs', 16 * DAY),
  version('v-ow-3', 'v3', 'Move secrets to env', 24 * DAY),
  version('v-ow-2', 'v2', 'Signature verification', 40 * DAY),
  version('v-ow-1', 'v1', 'Initial version', 61 * DAY),
]

export const WEBHOOKS_DEPLOYMENTS: Json[] = [
  deployment('d-ow-7', 'v-ow-7', 'v7', 'active', 'order-webhooks', 'Priya Patel', 2 * DAY + 20),
  deployment('d-ow-6', 'v-ow-6', 'v6', 'shut_down', 'order-webhooks', 'Alex Chen', 5 * DAY - 30),
  deployment('d-ow-5b', 'v-ow-5', 'v5', 'shut_down', 'order-webhooks', 'Alex Chen', 7 * DAY, { config_source: 'restored' }),
  deployment('d-ow-6a', 'v-ow-6', 'v6', 'failed', 'order-webhooks', 'Jonas Weber', 7 * DAY + 25),
  deployment('d-ow-5', 'v-ow-5', 'v5', 'shut_down', 'order-webhooks', 'Alex Chen', 9 * DAY - 15),
  deployment('d-ow-4', 'v-ow-4', 'v4', 'shut_down', 'order-webhooks', 'Mei Lin', 16 * DAY - 10),
]

export const CHECKOUT_VERSIONS: Json[] = [
  version('v-ca-12', 'v12', 'Idempotent payment capture', 25, { created_by: PEOPLE.jonas }),
  version('v-ca-11', 'v11', 'Tax rules for CH and NO', 3 * DAY, { created_by: PEOPLE.jonas }),
  version('v-ca-10', 'v10', 'Gift card balance check', 8 * DAY),
  version('v-ca-9', 'v9', 'Address validation', 15 * DAY),
]

/** Replaces the e2e defaults with the Northwind dataset. */
export function applyShotsDb(db: MockDb): MockDb {
  db.user = { ...USER }
  db.settings = {
    ...db.settings,
    base_domain: DOMAIN,
    registration_enabled: false,
    custom_runtimes_enabled: true,
    total_cpu_m: EU.total_cpu_m + US.total_cpu_m,
    total_memory_mb: EU.total_memory_mb + US.total_memory_mb,
    timezone: 'Europe/Berlin',
  }
  db.regions = [{ ...EU }, { ...US }]
  db.executorImages = structuredClone(EXECUTOR_IMAGES)
  db.projects = structuredClone(PROJECTS)
  db.versions = {
    'p-image-resizer': structuredClone(RESIZER_VERSIONS),
    'p-order-webhooks': structuredClone(WEBHOOKS_VERSIONS),
    'p-checkout-api': structuredClone(CHECKOUT_VERSIONS),
    'p-nightly-report': [version('v-nr-3', 'v3', 'Attach the CSV export', 12 * DAY), version('v-nr-2', 'v2', 'Group by region', 20 * DAY), version('v-nr-1', 'v1', 'Initial version', 40 * DAY)],
    'p-inference-api': [version('v-ia-4', 'v4', 'inference-api 2.4.1, 1 GPU', 4 * DAY, { config_snapshot: { image: 'ghcr.io/northwind/inference-api:2.4.1', knative_region_id: 'r-eu' } })],
    'p-payment-events': [version('v-pe-6', 'v6', 'Handle partial refunds', 6 * DAY)],
    'p-weather-tools': [version('v-wt-3', 'v3', 'Add the alerts tool', 2 * DAY), version('v-wt-2', 'v2', 'Hourly forecast', 7 * DAY), version('v-wt-1', 'v1', 'MCP Python starter', 12 * DAY)],
    'p-shop': [version('v-sh-9', 'v9', 'storefront 5.2.0', 3 * DAY, { config_snapshot: { image: 'ghcr.io/northwind/storefront:5.2.0', knative_region_id: 'r-eu' } })],
    'p-invoice-pdf': [version('v-ip-2', 'v2', 'Embed fonts', 20 * DAY), version('v-ip-1', 'v1', 'Initial version', 55 * DAY)],
  }
  db.code = { 'p-image-resizer': RESIZER_CODE, 'p-nightly-report': 'def handler(event, context):\n    return {"ok": True}\n' }
  db.files = {
    'p-order-webhooks': { 'index.js': WEBHOOKS_INDEX, 'signature.js': '// HMAC helpers\n', 'topics.js': '// topic client\n', 'package.json': '{ "name": "order-webhooks", "type": "module" }\n' },
  }
  db.versionFiles = Object.fromEntries(RESIZER_VERSIONS.map(v => [v.id, { 'main.py': RESIZER_CODE }]))
  for (const v of WEBHOOKS_VERSIONS) db.versionFiles[v.id] = { ...db.files['p-order-webhooks'] }
  db.deployments = {
    'p-image-resizer': [deployment('d-ir-5', 'v-ir-5', 'v5', 'active', 'image-resizer', 'Alex Chen', 170), deployment('d-ir-4', 'v-ir-4', 'v4', 'shut_down', 'image-resizer', 'Alex Chen', 2 * DAY - 20)],
    'p-order-webhooks': structuredClone(WEBHOOKS_DEPLOYMENTS),
    'p-checkout-api': [deployment('d-ca-11', 'v-ca-11', 'v11', 'active', 'checkout-api', 'Jonas Weber', 3 * DAY - 40)],
    'p-nightly-report': [deployment('d-nr-3', 'v-nr-3', 'v3', 'active', 'nightly-report', 'Mei Lin', 12 * DAY - 30)],
    'p-inference-api': [deployment('d-ia-4', 'v-ia-4', 'v4', 'active', 'inference-api', 'Mei Lin', 4 * DAY - 10)],
    'p-payment-events': [deployment('d-pe-6', 'v-pe-6', 'v6', 'active', 'payment-events', 'Priya Patel', 6 * DAY - 5)],
    'p-weather-tools': [deployment('d-wt-3', 'v-wt-3', 'v3', 'active', 'weather-tools', 'Alex Chen', 2 * DAY - 15)],
    'p-shop': [deployment('d-sh-9', 'v-sh-9', 'v9', 'active', 'shop', 'Jonas Weber', 3 * DAY - 50)],
    'p-invoice-pdf': [deployment('d-ip-2', 'v-ip-2', 'v2', 'active', 'invoice-pdf', 'Alex Chen', 20 * DAY - 5)],
  }
  db.domains = Object.fromEntries(db.projects.map(p => [p.id, []]))
  db.domains['p-shop'] = [{ id: 'dom-1', domain: 'shop.northwind.example', status: 'active', tls: 'issued', created_at: ago(80 * DAY) }]
  db.users = [
    { ...USER, role: 'owner' },
    { id: 2, name: 'Priya Patel', username: 'priya', email: 'priya.patel@northwind.example', is_admin: false, role: 'developer', two_factor_confirmed_at: ago(50 * DAY), created_at: ago(80 * DAY) },
    { id: 3, name: 'Jonas Weber', username: 'jonas', email: 'jonas.weber@northwind.example', is_admin: false, role: 'developer', two_factor_confirmed_at: null, created_at: ago(70 * DAY) },
    { id: 4, name: 'Mei Lin', username: 'mei', email: 'mei.lin@northwind.example', is_admin: false, role: 'release-manager', two_factor_confirmed_at: ago(20 * DAY), created_at: ago(45 * DAY) },
    { id: 5, name: 'Sam Okafor', username: 'sam', email: 'sam.okafor@northwind.example', is_admin: false, role: 'viewer', two_factor_confirmed_at: null, created_at: ago(10 * DAY) },
  ]
  db.tokens = [
    { id: 11, name: 'GitHub Actions deploy', abilities: ['projects.view', 'projects.deploy', 'git.push'], created_at: ago(60 * DAY), expires_at: ago(-300 * DAY), last_used_at: ago(35), created_by: PEOPLE.alex },
    { id: 12, name: 'Terraform', abilities: ['*'], created_at: ago(45 * DAY), expires_at: null, last_used_at: ago(3 * DAY), created_by: PEOPLE.jonas },
  ]
  db.notifications = [
    { id: 'n-1', type: 'deployment.finished', level: 'info', title: 'Deployment finished', data: { project_name: 'order-webhooks', version: 'v7' }, read_at: null, created_at: ago(2 * DAY + 18) },
    { id: 'n-2', type: 'rollout.step', level: 'info', title: 'Canary at 50 %', data: { project_name: 'checkout-api', version: 'v12' }, read_at: null, created_at: ago(6) },
  ]
  db.nodes = [
    { name: 'eu-2-control-plane', is_selected: false, cpu_m: 8000, memory_mb: 16384, roles: ['control-plane'] },
    { name: 'eu-2-worker-1', is_selected: true, cpu_m: 16000, memory_mb: 65536, roles: ['worker'] },
    { name: 'eu-2-worker-2', is_selected: true, cpu_m: 16000, memory_mb: 65536, roles: ['worker'] },
    { name: 'eu-2-worker-3', is_selected: false, cpu_m: 16000, memory_mb: 65536, roles: ['worker'] },
    { name: 'eu-2-gpu-1', is_selected: false, cpu_m: 32000, memory_mb: 131072, roles: ['worker', 'gpu'] },
  ]
  db.storageClasses = [
    { name: 'longhorn', is_default: true },
    { name: 'local-path', is_default: false },
    { name: 'nfs-client', is_default: false },
  ]
  db.globalDns = {
    zone: `g.${DOMAIN}`,
    base_domain: DOMAIN,
    tls: 'acme',
    update_region_id: 'r-eu',
    delegation: { records: [{ type: 'NS', name: `g.${DOMAIN}`, value: `ns-eu-1.g.${DOMAIN}` }], status: 'ok', message: null },
    last_sync_at: ago(4),
  }
  return db
}
