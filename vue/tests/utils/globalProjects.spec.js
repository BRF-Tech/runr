import { describe, it, expect } from 'vitest'
import {
  GEOIP_COPY_COMMAND, GEOIP_SOURCES, GLOBAL_FAILOVERS, GLOBAL_MAX_REGIONS, apiErrorMessage, canFailoverTo, dataStatusClass,
  formatLag, geoipStatusColors, globalCreateFields, globalRegionUnavailableKey, globalSelectionValid,
  isGlobalProject, isGlobalRegion, regionStatusClass, rolloutBusy, serverBusy, stateTextClass, stepOneError,
  toggleRegion, unwrap,
} from '@/utils/globalProjects'
import { buildCreatePayload } from '@/composables/useProjectForm'

const eu = { id: 'r-eu', name: 'eu-1', is_active: true, is_synced: true, global_dns_ready: true }

describe('utils/globalProjects', () => {
  it('knows a global project and a region that can take part', () => {
    expect(isGlobalProject({ placement: 'global' })).toBe(true)
    expect(isGlobalProject({ placement: 'regional' })).toBe(false)
    expect(isGlobalProject({})).toBe(false)
    expect(isGlobalProject(null)).toBe(false)
    expect(isGlobalRegion(eu)).toBe(true)
    expect(isGlobalRegion({ ...eu, global_dns_ready: false })).toBe(false)
    expect(isGlobalRegion({ id: 'x' })).toBe(false)
  })

  it('names why a region cannot be picked, in order: demo, not ready, residency, no global DNS', () => {
    expect(globalRegionUnavailableKey(eu)).toBeNull()
    expect(globalRegionUnavailableKey(null)).toBe('globalProjects.create.unavailable.missing')
    expect(globalRegionUnavailableKey({ ...eu, is_demo: true })).toBe('globalProjects.create.unavailable.demo')
    expect(globalRegionUnavailableKey(eu, { ready: false })).toBe('globalProjects.create.unavailable.notReady')
    expect(globalRegionUnavailableKey(eu, { allowed: false })).toBe('globalProjects.create.unavailable.residency')
    expect(globalRegionUnavailableKey({ ...eu, global_dns_ready: false })).toBe('globalProjects.create.unavailable.noGlobalDns')
  })

  it('toggles a region and keeps the primary among the picked ones', () => {
    expect(toggleRegion([], null, 'a')).toEqual({ regionIds: ['a'], primaryId: 'a' })
    expect(toggleRegion(['a'], 'a', 'b')).toEqual({ regionIds: ['a', 'b'], primaryId: 'a' })
    expect(toggleRegion(['a', 'b'], 'a', 'a')).toEqual({ regionIds: ['b'], primaryId: 'b' })
    expect(toggleRegion(['a'], 'a', 'a')).toEqual({ regionIds: [], primaryId: null })
  })

  it('needs 2..8 regions with the primary among them', () => {
    expect(globalSelectionValid(['a'], 'a')).toBe(false)
    expect(globalSelectionValid(['a', 'b'], 'a')).toBe(true)
    expect(globalSelectionValid(['a', 'b'], 'c')).toBe(false)
    expect(globalSelectionValid(['a', 'b'], null)).toBe(false)
    const many = Array.from({ length: GLOBAL_MAX_REGIONS + 1 }, (_, i) => `r${i}`)
    expect(globalSelectionValid(many, 'r0')).toBe(false)
    expect(globalSelectionValid(many.slice(0, GLOBAL_MAX_REGIONS), 'r0')).toBe(true)
  })

  it('sends the global fields with the primary first', () => {
    expect(globalCreateFields({ placement: 'regional' })).toEqual({})
    expect(globalCreateFields({ placement: 'global', knative_region_id: 'b', region_ids: ['a', 'b', 'c'], global_mode: 'proximity' }))
      .toEqual({ placement: 'global', global_mode: 'proximity', region_ids: ['b', 'a', 'c'], knative_region_id: 'b' })
    expect(globalCreateFields({ placement: 'global', knative_region_id: 'a', region_ids: ['a', 'b'], global_mode: 'nope' }).global_mode).toBe('ha')
  })

  it('buildCreatePayload carries the global fields and never the gateway of a global project', () => {
    const form = {
      name: ' Shop ', service_type: 'faas', description: '', runtime: 'python', runtime_version: '3.12',
      knative_region_id: 'r-eu', placement: 'global', global_mode: 'ha', region_ids: ['r-us', 'r-eu'],
      config: { trigger_type: 'http', cpu: 250, memory_mb: 256, timeout_seconds: 30, env_vars: '', scale_to_zero: true, min_scale: 0, max_scale: 1 },
      endpoint_type: 'gateway', gateway_path: '/shop', gateway_methods: [],
    }
    const payload = buildCreatePayload(form)
    expect(payload).toMatchObject({
      name: 'Shop', service_type: 'faas', placement: 'global', global_mode: 'ha',
      region_ids: ['r-eu', 'r-us'], knative_region_id: 'r-eu',
      config: { trigger_type: 'http' },
    })
    expect(payload).not.toHaveProperty('endpoint_type')
    expect(payload).not.toHaveProperty('gateway_path')

    const regional = buildCreatePayload({ ...form, placement: 'regional', region_ids: [] })
    expect(regional).not.toHaveProperty('placement')
    expect(regional).not.toHaveProperty('region_ids')
    expect(regional).toMatchObject({ endpoint_type: 'gateway', gateway_path: '/shop' })
  })

  it('finds the step-1 error of a create 422', () => {
    expect(stepOneError({ name: ['taken'] })).toBe('taken')
    expect(stepOneError({ 'region_ids.1': ['no global DNS'] })).toBe('no global DNS')
    expect(stepOneError({ region_ids: ['at least 2'] })).toBe('at least 2')
    expect(stepOneError({ global_mode: ['invalid'] })).toBe('invalid')
    expect(stepOneError({ 'config.image': ['uploaded'] })).toBeNull()
    expect(stepOneError(undefined)).toBeNull()
  })

  it('styles statuses and knows when something is still moving', () => {
    expect(regionStatusClass('ready')).toContain('emerald')
    expect(regionStatusClass('failed')).toContain('red')
    expect(regionStatusClass('deploying')).toContain('blue')
    expect(regionStatusClass('whatever')).toContain('gray')
    expect(stateTextClass('in_sync')).toBe('text-emerald-400')
    expect(stateTextClass('drift')).toBe('text-yellow-400')
    expect(stateTextClass('missing')).toBe('text-red-400')
    expect(stateTextClass(null)).toBe('text-gray-400')
    expect(serverBusy({ status: 'installing' })).toBe(true)
    expect(serverBusy({ status: 'ready' })).toBe(false)
    expect(serverBusy(null)).toBe(false)
    expect(rolloutBusy({ regions: [{ status: 'ready' }, { status: 'building' }] })).toBe(true)
    expect(rolloutBusy({ regions: [{ status: 'ready' }, { status: 'failed' }] })).toBe(false)
    expect(rolloutBusy(null)).toBe(false)
  })

  it('unwraps responses and reads API errors', () => {
    expect(unwrap({ data: { mode: 'ha' } })).toEqual({ mode: 'ha' })
    expect(unwrap({ mode: 'ha', regions: [] })).toEqual({ mode: 'ha', regions: [] })
    expect(unwrap({ data: [] })).toEqual({ data: [] })
    expect(apiErrorMessage({ response: { data: { errors: { region_ids: ['bad'] }, message: 'x' } } }, 'f')).toBe('bad')
    expect(apiErrorMessage({ response: { data: { message: 'Busy' } } }, 'f')).toBe('Busy')
    expect(apiErrorMessage({ response: { data: { reason: 'Tenant region' } } }, 'f')).toBe('Tenant region')
    expect(apiErrorMessage(new Error('x'), 'f')).toBe('f')
  })

  it('colours a fenced region, the data states and the replica states', () => {
    expect(regionStatusClass('fenced')).toContain('orange')
    expect(dataStatusClass('ready')).toContain('emerald')
    expect(dataStatusClass('syncing')).toContain('blue')
    expect(dataStatusClass('provisioning')).toContain('blue')
    expect(dataStatusClass('promoting')).toContain('orange')
    expect(dataStatusClass('recloning')).toContain('orange')
    expect(dataStatusClass('failed')).toContain('red')
    expect(dataStatusClass(null)).toContain('gray')
  })

  it('fails over only to a ready secondary while no move runs', () => {
    expect(GLOBAL_FAILOVERS).toEqual(['automatic', 'manual'])
    const global = { moving_to: null }
    expect(canFailoverTo(global, { role: 'secondary', status: 'ready' })).toBe(true)
    expect(canFailoverTo(global, { role: 'primary', status: 'ready' })).toBe(false)
    expect(canFailoverTo(global, { role: 'secondary', status: 'deploying' })).toBe(false)
    expect(canFailoverTo({ moving_to: 'r-us' }, { role: 'secondary', status: 'ready' })).toBe(false)
    expect(canFailoverTo(null, { role: 'secondary', status: 'ready' })).toBe(false)
  })

  it('formats a replay lag', () => {
    expect(formatLag(null)).toBeNull()
    expect(formatLag(undefined)).toBeNull()
    expect(formatLag(0)).toBe('0 s')
    expect(formatLag(42)).toBe('42 s')
    expect(formatLag(180)).toBe('3 min')
    expect(formatLag(7200)).toBe('2 h')
  })

  it('keeps polling while the primary moves or the data syncs', () => {
    expect(rolloutBusy({ moving_to: 'r-us', regions: [] })).toBe(true)
    expect(rolloutBusy({ regions: [{ status: 'ready', data_status: 'syncing' }] })).toBe(true)
    expect(rolloutBusy({ regions: [{ status: 'ready', data_status: 'ready' }], bindings: [{ regions: [{ status: 'promoting' }] }] })).toBe(true)
    expect(rolloutBusy({ regions: [{ status: 'fenced', data_status: 'ready' }], bindings: [{ regions: [{ status: 'ready' }] }] })).toBe(false)
  })

  it('knows the GeoIP sources and colours their states', () => {
    expect(GEOIP_SOURCES).toEqual(['default', 'url', 'file', 'none'])
    expect(GEOIP_COPY_COMMAND).toContain('/var/lib/powerdns/geoip.mmdb')
    expect(geoipStatusColors('loaded').text).toBe('text-emerald-400')
    expect(geoipStatusColors('missing').text).toBe('text-red-400')
    expect(geoipStatusColors('off').text).toBe('text-gray-400')
    expect(geoipStatusColors('unknown').text).toBe('text-yellow-400')
  })
})
