import { describe, it, expect } from 'vitest'
import { defineComponent, h, effectScope } from 'vue'
import { mount } from '@vue/test-utils'
import { useLatest } from '@/composables/useLatest'

// Last-call-wins guard of the loaders whose answers can arrive late or out of order
describe('useLatest', () => {
  it('keeps only the newest call current', () => {
    const latest = useLatest()
    const first = latest.begin()
    expect(first()).toBe(true)
    const second = latest.begin()
    expect(first()).toBe(false)
    expect(second()).toBe(true)
  })

  it('cancel() supersedes the calls in flight without starting one', () => {
    const latest = useLatest()
    const call = latest.begin()
    latest.cancel()
    expect(call()).toBe(false)
    expect(latest.begin()()).toBe(true)
  })

  it('ends every call when the owner is unmounted', () => {
    let latest
    const w = mount(defineComponent({ setup() { latest = useLatest(); return () => h('div') } }))
    const call = latest.begin()
    expect(latest.alive()).toBe(true)
    w.unmount()
    expect(call()).toBe(false)
    expect(latest.alive()).toBe(false)
    expect(latest.begin()()).toBe(false)
  })

  it('follows an effect scope as well (composables outside components)', () => {
    const scope = effectScope()
    const latest = scope.run(() => useLatest())
    const call = latest.begin()
    scope.stop()
    expect(call()).toBe(false)
  })
})
