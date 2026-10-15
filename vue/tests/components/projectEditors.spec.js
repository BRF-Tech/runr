import { describe, it, expect, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { moduleRegistry } from '@/modules/index'
import EnvVarsEditor from '@/components/project/EnvVarsEditor.vue'
import TriggerConfig from '@/components/project/TriggerConfig.vue'
import ScaleConfig from '@/components/project/ScaleConfig.vue'
import ResourcesEditor from '@/components/project/ResourcesEditor.vue'
import { withPlugins } from '../helpers'

function mountWith(component, props) {
  return mount(component, { props, global: withPlugins('en').global })
}

afterEach(() => {
  moduleRegistry.queueStreamSuffixFn = null
})

describe('EnvVarsEditor', () => {
  it('emits the raw text on input', async () => {
    const w = mountWith(EnvVarsEditor, { modelValue: '' })
    await w.find('textarea').setValue('A=1\nB=2')
    expect(w.emitted('update:modelValue').at(-1)).toEqual(['A=1\nB=2'])
  })

  it('shows no error for valid variables', () => {
    const w = mountWith(EnvVarsEditor, { modelValue: 'DATABASE_URL=postgres://db\nDEBUG=1' })
    expect(w.find('p.text-red-400').exists()).toBe(false)
  })

  it('explains an invalid variable name', () => {
    const w = mountWith(EnvVarsEditor, { modelValue: 'OK=1\n9LIVES=x' })
    expect(w.find('p.text-red-400').text()).toBe('Invalid variable name "9LIVES": letters, digits and _ only, not starting with a digit')
  })

  it('rejects platform-reserved names', async () => {
    const w = mountWith(EnvVarsEditor, { modelValue: 'PORT=8080' })
    expect(w.find('p.text-red-400').text()).toContain('PORT')
    await w.setProps({ modelValue: 'APP_PORT=8080' })
    expect(w.find('p.text-red-400').exists()).toBe(false)
  })
})

describe('TriggerConfig', () => {
  const synced = { is_synced: true, has_redis: true }
  const buttons = w => Object.fromEntries(w.findAll('button').map(b => [b.text(), b]))

  it('emits the chosen trigger type', async () => {
    const w = mountWith(TriggerConfig, { triggerType: 'http', region: synced })
    await buttons(w).Schedule.trigger('click')
    expect(w.emitted('update:triggerType')).toEqual([['schedule']])
  })

  it('disables HTTP on regions without Knative Serving', () => {
    const w = mountWith(TriggerConfig, { triggerType: 'schedule', region: { is_synced: false, has_serving: false, is_demo: false, has_redis: true } })
    const b = buttons(w)
    expect(b.Endpoint.attributes('disabled')).toBeDefined()
    expect(b.Schedule.attributes('disabled')).toBeUndefined()
  })

  it('allows HTTP on demo regions', () => {
    const w = mountWith(TriggerConfig, { triggerType: 'http', region: { is_synced: false, is_demo: true } })
    expect(buttons(w).Endpoint.attributes('disabled')).toBeUndefined()
  })

  it('disables Queue without Redis and does not emit when clicked', async () => {
    const w = mountWith(TriggerConfig, { triggerType: 'http', region: { is_synced: false, has_serving: true, has_redis: false } })
    const queue = w.findAll('button').find(b => b.text().startsWith('Queue'))
    expect(queue.attributes('disabled')).toBeDefined()
    await queue.trigger('click')
    expect(w.emitted('update:triggerType')).toBeUndefined()
  })

  it('validates the cron expression of schedule triggers', async () => {
    const w = mountWith(TriggerConfig, { triggerType: 'schedule', schedule: '', region: synced })
    expect(w.find('p.text-red-400').text()).toBe('Cron expression is required')

    await w.setProps({ schedule: '0 0 * * * *' })
    expect(w.find('p.text-red-400').text()).toMatch(/^Use 5 fields/)

    await w.setProps({ schedule: '*/10 * * * *' })
    expect(w.find('p.text-red-400').exists()).toBe(false)

    await w.find('input').setValue('@daily')
    expect(w.emitted('update:schedule').at(-1)).toEqual(['@daily'])
  })

  it('requires a stream name for queue triggers and shows the tenant-resolved name', async () => {
    moduleRegistry.queueStreamSuffixFn = () => '-acme'
    const w = mountWith(TriggerConfig, { triggerType: 'queue', queueStream: '', region: synced })
    expect(w.find('p.text-red-400').text()).toBe('Queue/Stream name is required')

    await w.setProps({ queueStream: ' orders ' })
    expect(w.find('p.text-red-400').exists()).toBe(false)
    expect(w.text()).toContain('Actual stream name: orders-acme')
  })
})

describe('ScaleConfig', () => {
  function lastEmits(w) {
    return {
      zero: w.emitted('update:scaleToZero')?.at(-1)?.[0],
      min: w.emitted('update:minScale')?.at(-1)?.[0],
      max: w.emitted('update:maxScale')?.at(-1)?.[0],
    }
  }

  it('scale to zero resets to 0..1 replicas', async () => {
    const w = mountWith(ScaleConfig, { scaleToZero: false, minScale: 3, maxScale: 8 })
    await w.findAll('button')[0].trigger('click')
    expect(lastEmits(w)).toEqual({ zero: true, min: 0, max: 1 })
  })

  it('custom scale starts at 1..5 replicas, or keeps existing bounds', async () => {
    const w = mountWith(ScaleConfig, { scaleToZero: true, minScale: 0, maxScale: 1 })
    await w.findAll('button')[1].trigger('click')
    expect(lastEmits(w)).toEqual({ zero: false, min: 1, max: 5 })

    const kept = mountWith(ScaleConfig, { scaleToZero: true, minScale: 2, maxScale: 9 })
    await kept.findAll('button')[1].trigger('click')
    expect(lastEmits(kept)).toEqual({ zero: false, min: 2, max: 9 })
  })

  it('hides the replica inputs while scaling to zero', () => {
    const w = mountWith(ScaleConfig, { scaleToZero: true, minScale: 0, maxScale: 1 })
    expect(w.findAll('input')).toHaveLength(0)
  })

  // Buttons in custom mode: [zero, custom, min-, min+, max-, max+]
  it('raising min above max raises max too, capped at 10', async () => {
    const w = mountWith(ScaleConfig, { scaleToZero: false, minScale: 3, maxScale: 3 })
    await w.findAll('button')[3].trigger('click')
    expect(lastEmits(w)).toMatchObject({ min: 4, max: 4 })

    const capped = mountWith(ScaleConfig, { scaleToZero: false, minScale: 10, maxScale: 20 })
    await capped.findAll('button')[3].trigger('click')
    expect(lastEmits(capped).min).toBe(10)
  })

  it('lowering max below min lowers min too, never below 1', async () => {
    const w = mountWith(ScaleConfig, { scaleToZero: false, minScale: 4, maxScale: 4 })
    await w.findAll('button')[4].trigger('click')
    expect(lastEmits(w)).toMatchObject({ min: 3, max: 3 })

    const floor = mountWith(ScaleConfig, { scaleToZero: false, minScale: 1, maxScale: 1 })
    await floor.findAll('button')[4].trigger('click')
    await floor.findAll('button')[2].trigger('click')
    expect(lastEmits(floor)).toMatchObject({ min: 1, max: 1 })
  })

  it('emits typed replica counts as numbers', async () => {
    const w = mountWith(ScaleConfig, { scaleToZero: false, minScale: 1, maxScale: 5 })
    await w.findAll('input')[1].setValue('12')
    expect(lastEmits(w).max).toBe(12)
  })
})

describe('ResourcesEditor', () => {
  const base = { cpu: 250, memoryMb: 256, timeoutSeconds: 30 }

  it('preselects the matching size preset, or "custom"', async () => {
    const w = mountWith(ResourcesEditor, base)
    expect(w.find('select').element.value).toBe('micro')
    await w.setProps({ memoryMb: 300 })
    expect(w.find('select').element.value).toBe('')
  })

  it('applies a preset as CPU millicores + memory', async () => {
    const w = mountWith(ResourcesEditor, base)
    await w.find('select').setValue('large')
    expect(w.emitted('update:cpu').at(-1)).toEqual([2000])
    expect(w.emitted('update:memoryMb').at(-1)).toEqual([4096])
  })

  it('warns when the timeout exceeds the region request timeout', async () => {
    const w = mountWith(ResourcesEditor, { ...base, timeoutSeconds: 600, maxTimeout: 300 })
    expect(w.find('p.text-red-400').text()).toBe('At most 300 s (request timeout of the region)')
    await w.setProps({ timeoutSeconds: 300 })
    expect(w.find('p.text-red-400').exists()).toBe(false)
  })

  it('offers the image pull policy only for containers', async () => {
    const fn = mountWith(ResourcesEditor, { ...base, serviceType: 'faas' })
    expect(fn.text()).not.toContain('If Not Present')

    const caas = mountWith(ResourcesEditor, { ...base, serviceType: 'caas' })
    const always = caas.findAll('button').find(b => b.text() === 'Always')
    await always.trigger('click')
    expect(caas.emitted('update:imagePullPolicy')).toEqual([['Always']])
  })

  it('emits typed CPU, memory and timeout as numbers', async () => {
    const w = mountWith(ResourcesEditor, base)
    const [cpu, mem, timeout] = w.findAll('input')
    await cpu.setValue('750')
    await mem.setValue('640')
    await timeout.setValue('45')
    expect(w.emitted('update:cpu').at(-1)).toEqual([750])
    expect(w.emitted('update:memoryMb').at(-1)).toEqual([640])
    expect(w.emitted('update:timeoutSeconds').at(-1)).toEqual([45])
  })
})
