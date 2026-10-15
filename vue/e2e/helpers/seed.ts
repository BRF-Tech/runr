import type { APIRequestContext } from '@playwright/test'
import type { Json } from '../fixtures/data'
import { CAAS_PROJECT, FAAS_PROJECT, XAAS_PROJECT } from '../fixtures/data'
import type { MockApi } from '../fixtures/mock-api'
import type { Session } from './auth'

// Seeded data for a spec, the same call in both modes:
//   mock  → rows are added to the in-memory fake (mockApi.db)
//   stack → rows are created through the real REST API and removed after the test
//
//   const project = await seed.project('xaas')
//   const region = await seed.readyRegion()      // null: the stack has no usable region

export type ServiceType = 'xaas' | 'faas' | 'caas'
export type HttpMethod = 'get' | 'post' | 'put' | 'patch' | 'delete'

/** Unique, Kubernetes-safe names (letter first, short enough for the 44-char slug limit). */
export function uniqueName(prefix = 'e2e'): string {
  return `${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`
}

const TEMPLATES: Record<ServiceType, Json> = { xaas: XAAS_PROJECT, faas: FAAS_PROJECT, caas: CAAS_PROJECT }

export interface Seeder {
  /** A region that accepts new projects (active + synced/demo/kubeconfig), or null. */
  readyRegion(): Promise<Json | null>
  /** Creates a project of the given type; `overrides` are merged into the create payload / row. */
  project(type: ServiceType, overrides?: Json): Promise<Json>
  /** Registers extra cleanup (stack mode), run after the test in reverse order. */
  onCleanup(fn: () => Promise<unknown>): void
  /** Stack mode: deletes, after the test, the project the UI created under this name. */
  removeProjectAfterTest(name: string): void
  /** Authenticated API context (stack mode only). */
  api(): APIRequestContext
  /**
   * Authenticated JSON call to the real API, e.g. call('patch', '/api/settings', {...}) (stack mode only);
   * `headers` are added, e.g. an X-Tenant-ID for a call in a tenant.
   */
  call(method: HttpMethod, url: string, data?: Json, headers?: Record<string, string>): Promise<Json>
  cleanup(): Promise<void>
}

export class MockSeeder implements Seeder {
  constructor(private readonly mock: MockApi) {}

  async readyRegion(): Promise<Json | null> {
    return this.mock.db.regions.find(r => r.is_active && (r.is_synced || r.is_demo || r.has_kubeconfig)) ?? null
  }

  async project(type: ServiceType, overrides: Json = {}): Promise<Json> {
    const db = this.mock.db
    const id = this.mock.nextId('p')
    const name = overrides.name ?? uniqueName(`e2e-${type}`)
    const row: Json = {
      ...structuredClone(TEMPLATES[type]),
      ...overrides,
      id,
      name,
      slug: name.toLowerCase().replace(/[^a-z0-9]+/g, '-'),
      config: { ...structuredClone(TEMPLATES[type].config), ...(overrides.config ?? {}) },
    }
    db.projects.unshift(row)
    const vid = this.mock.nextId('v')
    db.versions[id] = [{ id: vid, label: 'v1', commit_message: 'Initial version', build_status: 'ready', created_at: new Date().toISOString() }]
    db.deployments[id] = []
    db.domains[id] = []
    if (type === 'xaas') {
      db.code[id] = 'def handler(event, context):\n    return {"seeded": True}\n'
      db.versionFiles[vid] = { [row.config.filename ?? 'main.py']: db.code[id] }
    }
    if (type === 'faas') {
      db.files[id] = { 'index.js': 'exports.handler = async () => ({ statusCode: 200 })\n' }
      db.versionFiles[vid] = { ...db.files[id] }
    }
    return row
  }

  onCleanup(): void {}

  removeProjectAfterTest(): void {}

  api(): APIRequestContext {
    throw new Error('seed.api() is only available in stack mode')
  }

  call(): Promise<Json> {
    throw new Error('seed.call() is only available in stack mode')
  }

  async cleanup(): Promise<void> {}
}

export class StackSeeder implements Seeder {
  private readonly cleanups: Array<() => Promise<unknown>> = []
  private settings: Json | null = null

  constructor(private readonly request: APIRequestContext, private readonly session: Session) {}

  api(): APIRequestContext {
    return this.request
  }

  private headers(): Record<string, string> {
    const h: Record<string, string> = { Accept: 'application/json', Authorization: `Bearer ${this.session.token}` }
    if (this.session.activeTenant?.id) h['X-Tenant-ID'] = String(this.session.activeTenant.id)
    return h
  }

  async call(method: HttpMethod, url: string, data?: Json, headers: Record<string, string> = {}): Promise<Json> {
    let res = await this.request[method](url, { headers: { ...this.headers(), ...headers }, data })
    // Every worker uses the one stack account, so the suite can run into the per-user API rate
    // limit (API_USER_RATE_LIMIT_PER_MINUTE): wait as the API asks, then try again
    for (let attempt = 0; res.status() === 429 && attempt < 3; attempt++) {
      const wait = Number(res.headers()['retry-after'] ?? 5)
      await new Promise(resolve => setTimeout(resolve, Math.min(Math.max(wait, 1), 30) * 1000))
      res = await this.request[method](url, { headers: { ...this.headers(), ...headers }, data })
    }
    const body = await res.json().catch(() => ({}))
    if (!res.ok()) throw new Error(`${method.toUpperCase()} ${url} → ${res.status()}: ${body.message ?? JSON.stringify(body).slice(0, 300)}`)
    return body
  }

  private async loadSettings(): Promise<Json> {
    this.settings ??= await this.call('get', '/api/settings')
    return this.settings
  }

  async readyRegion(): Promise<Json | null> {
    const s = await this.loadSettings()
    return (s.regions ?? []).find((r: Json) => r.is_active && (r.is_synced || r.is_demo || r.has_kubeconfig)) ?? null
  }

  async project(type: ServiceType, overrides: Json = {}): Promise<Json> {
    const region = await this.readyRegion()
    if (!region) throw new Error('The stack has no ready region; add one (or seed the demo region) before running project specs')
    const s = await this.loadSettings()
    const images: Json[] = s.executor_images ?? []
    const image = images.find(i => i.runtime === 'python' && i.is_default) ?? images.find(i => i.runtime === 'python') ?? images[0]
    const t = TEMPLATES[type].config
    const config: Json = {
      cpu: t.cpu, memory_mb: t.memory_mb, timeout_seconds: t.timeout_seconds,
      trigger_type: 'http', scale_to_zero: true, min_scale: 0, max_scale: 1,
      ...(type === 'caas' ? { image: 'nginx:1.27', ports: [80], image_pull_policy: 'IfNotPresent' } : {}),
      ...(type === 'faas' ? { handler: 'main.handler' } : {}),
      ...(overrides.config ?? {}),
    }
    const payload: Json = {
      name: uniqueName(`e2e-${type}`),
      service_type: type,
      knative_region_id: region.id,
      ...(type === 'caas' ? {} : { runtime: image?.runtime ?? 'python', runtime_version: image?.version }),
      ...overrides,
      config,
    }
    const body = await this.call('post', '/api/projects', payload)
    const project = body.data ?? body
    this.onCleanup(() => this.call('delete', `/api/projects/${project.id}`).catch(() => {}))
    // A snippet starts with a saved version, as the mock seed does (v1 "Initial version"): the
    // API creates the first code version only when code is saved
    if (type === 'xaas' && (project.runtime ?? 'python') === 'python') {
      await this.call('post', `/api/projects/${project.id}/code`, {
        code: 'def handler(event, context):\n    return {"seeded": True}\n',
        message: 'Initial version',
      })
    }
    return project
  }

  onCleanup(fn: () => Promise<unknown>): void {
    this.cleanups.push(fn)
  }

  removeProjectAfterTest(name: string): void {
    this.onCleanup(async () => {
      const body = await this.call('get', '/api/projects')
      for (const p of (body.data ?? body) as Json[]) {
        if (p.name === name) await this.call('delete', `/api/projects/${p.id}`)
      }
    })
  }

  async cleanup(): Promise<void> {
    for (const fn of this.cleanups.reverse()) {
      try { await fn() } catch { /* best effort: leftovers carry the e2e- prefix */ }
    }
    this.cleanups.length = 0
  }
}
