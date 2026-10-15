import { describe, it, expect } from 'vitest'
import {
  INSTANCE_SIZES, DEFAULT_SIZE_BY_TYPE, toMillicores, formatVcpu, findInstanceSize,
  defaultSizeFor, projectReservation, matchInstanceSize,
} from '@/utils/instanceSizes'
import { parseCpuMillicores, isLegacyServiceType, SERVICE_TYPES } from '@/utils/projectHelpers'

describe('CPU quantities', () => {
  it.each([
    ['250m', 250],
    ['1', 1000],
    ['0.5', 500],
    ['1.25', 1250],
    [' 2000m ', 2000],
    ['', 0],
    [null, 0],
    ['abc', 0],
  ])('parseCpuMillicores(%j) = %d', (input, m) => {
    expect(parseCpuMillicores(input)).toBe(m)
  })

  it('treats numbers as millicores already', () => {
    expect(toMillicores(250)).toBe(250)
    expect(toMillicores(249.6)).toBe(250)
    expect(toMillicores(Number.NaN)).toBe(0)
    expect(toMillicores(Infinity)).toBe(0)
    expect(toMillicores('1')).toBe(1000)
  })

  it('formats vCPU for display', () => {
    expect(formatVcpu('125m')).toBe('0.125')
    expect(formatVcpu('1000m')).toBe('1')
    expect(formatVcpu(4000)).toBe('4')
  })
})

describe('instance sizes', () => {
  it('are ordered from small to large', () => {
    const cpus = INSTANCE_SIZES.map(s => toMillicores(s.cpu))
    const mems = INSTANCE_SIZES.map(s => s.memory_mb)
    expect([...cpus].sort((a, b) => a - b)).toEqual(cpus)
    expect([...mems].sort((a, b) => a - b)).toEqual(mems)
  })

  it('every service type default points at an existing size', () => {
    for (const id of Object.values(DEFAULT_SIZE_BY_TYPE)) {
      expect(findInstanceSize(id)).not.toBeNull()
    }
  })

  it('defaults per type, with a fallback for unknown types', () => {
    expect(defaultSizeFor('caas').id).toBe('small')
    expect(defaultSizeFor('faas').id).toBe('micro')
    expect(defaultSizeFor(null).id).toBe('micro')
    expect(findInstanceSize('huge')).toBeNull()
  })

  it('matches a preset only when CPU and memory both match', () => {
    expect(matchInstanceSize(500, 512)?.id).toBe('small')
    expect(matchInstanceSize('0.5', '512')?.id).toBe('small')
    expect(matchInstanceSize(500, 1024)).toBeNull()
  })
})

describe('projectReservation (mirror of the API quota formula)', () => {
  it('reserves size + queue-proxy for at least one pod', () => {
    expect(projectReservation({ service_type: 'faas', config: { cpu: '250m', memory_mb: 256, min_scale: 0 } }))
      .toEqual({ cpu_m: 350, memory_mb: 356 })
  })

  it('multiplies by min_scale', () => {
    expect(projectReservation({ service_type: 'caas', config: { cpu: '500m', memory_mb: 512, min_scale: 3 } }))
      .toEqual({ cpu_m: 1800, memory_mb: 1836 })
  })

  it('adds one queue consumer for queue projects', () => {
    expect(projectReservation({ service_type: 'xaas', config: { cpu: '1', memory_mb: 1024, trigger_type: 'queue' } }))
      .toEqual({ cpu_m: 1150, memory_mb: 1188 })
  })

  it('runs scheduled projects as a single pod without queue-proxy', () => {
    expect(projectReservation({ service_type: 'faas', config: { cpu: '250m', memory_mb: 256, trigger_type: 'schedule', min_scale: 4 } }))
      .toEqual({ cpu_m: 250, memory_mb: 256 })
  })

  it('falls back to the type default size', () => {
    expect(projectReservation({ service_type: 'caas' })).toEqual({ cpu_m: 600, memory_mb: 612 })
  })

  it('counts legacy and unknown types as zero', () => {
    expect(projectReservation({ service_type: 'aaas', config: { cpu: '4' } })).toEqual({ cpu_m: 0, memory_mb: 0 })
    expect(projectReservation(null)).toEqual({ cpu_m: 0, memory_mb: 0 })
  })
})

describe('service types', () => {
  it('flags only types outside the supported list as legacy', () => {
    for (const t of SERVICE_TYPES) expect(isLegacyServiceType(t)).toBe(false)
    expect(isLegacyServiceType('aaas')).toBe(true)
    expect(isLegacyServiceType(null)).toBe(false)
    expect(isLegacyServiceType('')).toBe(false)
  })
})
