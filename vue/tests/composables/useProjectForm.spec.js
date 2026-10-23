import { describe, it, expect, beforeEach } from 'vitest'
import { reactive } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import {
  parseEnvVars, envVarsToString, isRegionReady, buildConfigPayload, buildCreatePayload, useRuntimeData,
} from '@/composables/useProjectForm'
import { useSettingsStore } from '@/stores/settings'

function formConfig(overrides = {}) {
  return {
    image: 'nginx:1.27',
    command: '',
    image_pull_policy: 'IfNotPresent',
    port: 80,
    cpu: 250,
    memory_mb: 256,
    timeout_seconds: 30,
    trigger_type: 'http',
    schedule: '',
    queue_stream: '',
    env_vars: '',
    scale_to_zero: false,
    min_scale: 2,
    max_scale: 5,
    handler: '',
    ...overrides,
  }
}

describe('parseEnvVars / envVarsToString', () => {
  it('returns undefined for empty input', () => {
    expect(parseEnvVars('')).toBeUndefined()
    expect(parseEnvVars('  \n ')).toBeUndefined()
    expect(parseEnvVars(null)).toBeUndefined()
  })

  it('parses KEY=value lines, keeping "=" inside values and trimming', () => {
    expect(parseEnvVars(' A = 1 \nURL=postgres://h/db?sslmode=require\r\nEMPTY=')).toEqual({
      A: '1',
      URL: 'postgres://h/db?sslmode=require',
      EMPTY: '',
    })
  })

  it('drops lines without "="', () => {
    expect(parseEnvVars('JUSTAKEY\nB=2')).toEqual({ B: '2' })
  })

  it('round-trips through envVarsToString', () => {
    const vars = { A: '1', B: 'x=y' }
    expect(parseEnvVars(envVarsToString(vars))).toEqual(vars)
    expect(envVarsToString(null)).toBe('')
    expect(envVarsToString('nope')).toBe('')
  })
})

describe('isRegionReady', () => {
  it.each([
    [{ is_active: true, is_synced: true }, true],
    [{ is_active: true, is_demo: true }, true],
    [{ is_active: true, has_kubeconfig: true }, true],
    [{ is_active: true }, false],
    [{ is_active: false, is_synced: true }, false],
  ])('%j -> %s', (region, ready) => {
    expect(!!isRegionReady(region)).toBe(ready)
  })
})

describe('buildConfigPayload', () => {
  it('builds a FaaS/XaaS config with CPU as a Kubernetes quantity', () => {
    const payload = buildConfigPayload(formConfig({ handler: 'main.handler', env_vars: 'A=1' }), 'faas')
    expect(payload).toEqual({
      handler: 'main.handler',
      env_vars: { A: '1' },
      cpu: '250m',
      memory_mb: 256,
      timeout_seconds: 30,
      trigger_type: 'http',
      schedule: undefined,
      queue_stream: undefined,
      scale_to_zero: false,
      min_scale: 2,
      max_scale: 5,
    })
  })

  it('omits an empty handler and CPU', () => {
    const payload = buildConfigPayload(formConfig({ cpu: 0 }), 'xaas')
    expect(payload.handler).toBeUndefined()
    expect(payload.cpu).toBeUndefined()
  })

  it('forces scale-to-zero and one replica for schedule triggers', () => {
    const payload = buildConfigPayload(formConfig({ trigger_type: 'schedule', schedule: '*/5 * * * *' }), 'faas')
    expect(payload).toMatchObject({ schedule: '*/5 * * * *', queue_stream: undefined, scale_to_zero: true, min_scale: 0, max_scale: 1 })
  })

  it('sends the stream only for queue triggers', () => {
    const payload = buildConfigPayload(formConfig({ trigger_type: 'queue', queue_stream: 'orders', schedule: 'stale' }), 'faas')
    expect(payload).toMatchObject({ trigger_type: 'queue', queue_stream: 'orders', schedule: undefined })
  })

  it('builds a CaaS config with image, ports and command', () => {
    const payload = buildConfigPayload(formConfig({ command: 'node server.js --port 80' }), 'caas')
    expect(payload).toMatchObject({
      image: 'nginx:1.27',
      ports: [80],
      command: ['node', 'server.js', '--port', '80'],
      image_pull_policy: 'IfNotPresent',
    })
    expect(payload).not.toHaveProperty('handler')
  })

  it('splits the CaaS command on any whitespace without empty arguments', () => {
    const payload = buildConfigPayload(formConfig({ command: '  sh  -c   run.sh ' }), 'caas')
    expect(payload.command).toEqual(['sh', '-c', 'run.sh'])
  })

  it('sends no command for a blank CaaS command', () => {
    expect(buildConfigPayload(formConfig({ command: '   ' }), 'caas').command).toBeNull()
  })

  it('exposes no port for scheduled containers', () => {
    const payload = buildConfigPayload(formConfig({ trigger_type: 'schedule' }), 'caas')
    expect(payload.ports).toBeNull()
  })

  it('lets extras override computed fields', () => {
    expect(buildConfigPayload(formConfig(), 'faas', { min_scale: 9 }).min_scale).toBe(9)
  })

  // #627: scale to zero keeps its max — the form's max_scale is sent as it is
  it('sends the max of a scale-to-zero project', () => {
    const payload = buildConfigPayload(formConfig({ scale_to_zero: true, min_scale: 0, max_scale: 20 }), 'caas')
    expect(payload).toMatchObject({ scale_to_zero: true, min_scale: 0, max_scale: 20 })
  })

  describe('timed warmer (scale_schedule)', () => {
    const rows = [{ cron: ' 0  9 * * 1-5', min_scale: '3' }, { cron: '0 18 * * 1-5', min_scale: 0 }]

    it('sends the rows trimmed, with whole counts', () => {
      expect(buildConfigPayload(formConfig({ scale_schedule: rows }), 'faas').scale_schedule)
        .toEqual([{ cron: '0 9 * * 1-5', min_scale: 3 }, { cron: '0 18 * * 1-5', min_scale: 0 }])
    })

    it('sends null when it is off or has no rows', () => {
      expect(buildConfigPayload(formConfig({ scale_schedule: null }), 'xaas').scale_schedule).toBeNull()
      expect(buildConfigPayload(formConfig({ scale_schedule: [] }), 'caas').scale_schedule).toBeNull()
    })

    it('turns it off for schedule triggers (a CronJob has nothing to keep warm)', () => {
      const payload = buildConfigPayload(formConfig({ trigger_type: 'schedule', schedule: '0 * * * *', scale_schedule: rows }), 'faas')
      expect(payload.scale_schedule).toBeNull()
    })

    it('leaves the field out for forms without it', () => {
      expect(buildConfigPayload(formConfig(), 'faas')).not.toHaveProperty('scale_schedule')
    })

    it('reaches the create payload', () => {
      const payload = buildCreatePayload({
        name: 'warm', service_type: 'caas', description: '', knative_region_id: 'r1',
        config: formConfig({ scale_schedule: rows }),
      })
      expect(payload.config.scale_schedule).toHaveLength(2)
    })
  })
})

describe('buildCreatePayload', () => {
  function form(overrides = {}) {
    return {
      name: '  Orders API ',
      service_type: 'faas',
      runtime: 'python',
      runtime_version: '3.12',
      description: '  ',
      knative_region_id: 'r-1',
      config: formConfig(),
      endpoint_type: 'subdomain',
      gateway_path: '',
      gateway_methods: [],
      ...overrides,
    }
  }

  it('trims the name and drops an empty description', () => {
    const payload = buildCreatePayload(form())
    expect(payload).toMatchObject({ name: 'Orders API', service_type: 'faas', runtime: 'python', runtime_version: '3.12', knative_region_id: 'r-1' })
    expect(payload.description).toBeUndefined()
    expect(payload).not.toHaveProperty('endpoint_type')
  })

  it('sends no runtime for containers', () => {
    const payload = buildCreatePayload(form({ service_type: 'caas' }))
    expect(payload).not.toHaveProperty('runtime')
    expect(payload).not.toHaveProperty('runtime_version')
    expect(payload.config.image).toBe('nginx:1.27')
  })

  it('adds gateway routing only for HTTP projects behind the gateway', () => {
    const gw = buildCreatePayload(form({ endpoint_type: 'gateway', gateway_path: '/orders', gateway_methods: ['GET', 'POST'] }))
    expect(gw).toMatchObject({ endpoint_type: 'gateway', gateway_path: '/orders', gateway_methods: ['GET', 'POST'] })

    const noMethods = buildCreatePayload(form({ endpoint_type: 'gateway', gateway_path: '/orders' }))
    expect(noMethods).not.toHaveProperty('gateway_methods')

    const scheduled = buildCreatePayload(form({
      endpoint_type: 'gateway', gateway_path: '/orders', config: formConfig({ trigger_type: 'schedule' }),
    }))
    expect(scheduled).not.toHaveProperty('endpoint_type')
  })
})

describe('useRuntimeData', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('lists each runtime once and hides end-of-life versions', () => {
    const store = useSettingsStore()
    store.data.executor_images = [
      { runtime: 'python', version: '3.12', is_default: true },
      { runtime: 'python', version: '3.8', eol_at: '2020-01-01T00:00:00Z' },
      { runtime: 'python', version: '3.13', eol_at: '2999-01-01T00:00:00Z' },
      { runtime: 'nodejs', version: '22' },
    ]
    const form = reactive({ runtime: 'python' })
    const { runtimes, runtimeVersions } = useRuntimeData(form)

    expect(runtimes.value.map(r => r.id)).toEqual(['python', 'nodejs'])
    expect(runtimeVersions.value.map(v => v.version)).toEqual(['3.12', '3.13'])

    form.runtime = 'nodejs'
    expect(runtimeVersions.value.map(v => v.version)).toEqual(['22'])
  })
})
