import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSettingsStore } from '@/stores/settings'
import { useRuntimesStore } from '@/stores/runtimes'
import { wasmRuntimeAvailableIn } from '@/utils/wasm'
import { gpuPayload } from '@/utils/ai'

export function parseEnvVars(raw) {
  if (!raw?.trim()) return undefined
  return Object.fromEntries(
    raw.trim().split('\n')
      .map((l) => l.split('='))
      .filter((p) => p.length >= 2)
      .map(([k, ...v]) => [k.trim(), v.join('=').trim()]),
  )
}

export function envVarsToString(obj) {
  if (!obj || typeof obj !== 'object') return ''
  return Object.entries(obj).map(([k, v]) => `${k}=${v}`).join('\n')
}

export function isRegionReady(r) {
  return r.is_active && (r.is_synced || r.is_demo || r.has_kubeconfig)
}

export function buildConfigPayload(formConfig, serviceType, extras = {}) {
  const c = formConfig
  const envVars = parseEnvVars(c.env_vars)
  const resources = {
    cpu:             c.cpu ? `${c.cpu}m` : undefined,
    memory_mb:       c.memory_mb,
    timeout_seconds: c.timeout_seconds,
  }
  const trigger = {
    trigger_type: c.trigger_type,
    schedule:     c.trigger_type === 'schedule' ? c.schedule : undefined,
    queue_stream: c.trigger_type === 'queue' || (c.trigger_type === 'workflow' && c.queue_stream?.trim()) ? c.queue_stream : undefined,
  }
  const scale = c.trigger_type === 'schedule'
    ? { scale_to_zero: true, min_scale: 0, max_scale: 1 }
    : { scale_to_zero: c.scale_to_zero, min_scale: c.min_scale, max_scale: c.max_scale }
  // config.gpu: sent when on; null removes the GPU of a project that had one (GpuConfig)
  const gpu = c.gpu?.enabled ? { gpu: gpuPayload(c.gpu) } : (c.gpu?.stored ? { gpu: null } : {})

  if (serviceType === 'caas') {
    return {
      image:             c.image,
      ports:             c.trigger_type !== 'schedule' && c.port ? [c.port] : null,
      command:           c.command?.trim() ? c.command.trim().split(/\s+/) : null,
      image_pull_policy: c.image_pull_policy,
      env_vars:          envVars,
      ...resources,
      ...trigger,
      ...scale,
      ...gpu,
      ...extras,
    }
  }

  return {
    handler:  c.handler || undefined,
    env_vars: envVars,
    ...resources,
    ...trigger,
    ...scale,
    ...gpu,
    ...extras,
  }
}

export function useTypeCards() {
  const { t } = useI18n()

  return computed(() => [
    {
      id: 'xaas',
      label:       t('create.step2.xaas.label'),
      subtitle:    t('create.step2.xaas.subtitle'),
      description: t('create.step2.xaas.description'),
      icon: '⚡',
      active: 'border-blue-500 ring-2 ring-blue-500/30 bg-blue-950/30',
    },
    {
      id: 'faas',
      label:       t('create.step2.faas.label'),
      subtitle:    t('create.step2.faas.subtitle'),
      description: t('create.step2.faas.description'),
      icon: '📦',
      active: 'border-purple-500 ring-2 ring-purple-500/30 bg-purple-950/30',
    },
    {
      id: 'caas',
      label:       t('create.step2.caas.label'),
      subtitle:    t('create.step2.caas.subtitle'),
      description: t('create.step2.caas.description'),
      icon: '🐳',
      active: 'border-emerald-500 ring-2 ring-emerald-500/30 bg-emerald-950/30',
    },
  ])
}

export function buildCreatePayload(form) {
  const base = {
    name:              form.name.trim(),
    service_type:      form.service_type,
    description:       form.description.trim() || undefined,
    knative_region_id: form.knative_region_id || undefined,
  }
  if (form.service_type !== 'caas') {
    base.runtime = form.runtime
    base.runtime_version = form.runtime_version || undefined
  }
  const payload = { ...base, config: buildConfigPayload(form.config, form.service_type) }
  if (form.config.trigger_type === 'http' && form.endpoint_type === 'gateway') {
    payload.endpoint_type = 'gateway'
    payload.gateway_path = form.gateway_path
    if (form.gateway_methods?.length) payload.gateway_methods = form.gateway_methods
  }
  return payload
}

/** Whether a runtime of the catalog may run in a region (regions null = every region). */
export function runtimeAvailableIn(runtime, regionId) {
  return !regionId || !Array.isArray(runtime?.regions) || runtime.regions.includes(regionId)
}

/** Whether a runtime supports a trigger type (manifest triggers). */
export function runtimeSupportsTrigger(runtime, triggerType) {
  return !triggerType || !Array.isArray(runtime?.triggers) || runtime.triggers.includes(triggerType)
}

/** Default pick of a runtime list: python when offered, else the first one. */
export function defaultRuntimeId(list) {
  return (list.find(r => r.id === 'python') ?? list[0])?.id ?? null
}

/**
 * Runtime picker data from the runtime catalog (GET /runtimes, stores/runtimes.js):
 *  - runtimes:           enabled runtimes available in the form's region; `unsupported` marks the
 *                        ones that do not support the form's trigger type
 *  - selectableRuntimes: runtimes without the unsupported ones
 *  - runtimeVersions:    versions of form.runtime that are not past their EOL
 * includeCurrent keeps form.runtime in the list even when it is filtered out (edit: read-only pick).
 */
export function useRuntimeData(form, { includeCurrent = false } = {}) {
  const settingsStore = useSettingsStore()
  const runtimesStore = useRuntimesStore()
  runtimesStore.fetch()

  const executorImages = computed(() => settingsStore.data.executor_images ?? [])

  const runtimes = computed(() => {
    const regionId = form.knative_region_id
    const trigger  = form.config?.trigger_type
    // WebAssembly runtimes only where the region's WebAssembly support has their shim
    const region = (settingsStore.data.regions ?? []).find(r => r.id === regionId) ?? null
    const list = runtimesStore.runtimes
      .filter(r => (runtimeAvailableIn(r, regionId) && wasmRuntimeAvailableIn(r, region)) || (includeCurrent && r.id === form.runtime))
    if (includeCurrent && form.runtime && !list.some(r => r.id === form.runtime)) {
      const current = runtimesStore.byId(form.runtime)
      list.push(current ?? { id: form.runtime, name: form.runtime, versions: [], triggers: null })
    }
    return list.map(r => ({ ...r, unsupported: !runtimeSupportsTrigger(r, trigger) }))
  })

  const selectableRuntimes = computed(() => runtimes.value.filter(r => !r.unsupported))

  const runtimeVersions = computed(() => {
    const entry = runtimes.value.find(r => r.id === form.runtime) ?? runtimesStore.byId(form.runtime)
    return (entry?.versions ?? []).filter(v => !(v.eol_at && new Date(v.eol_at) <= new Date()))
  })

  return { executorImages, runtimes, selectableRuntimes, runtimeVersions }
}
