import { describe, it, expect } from 'vitest'
import en from '@/modules/sandboxes/locales/en.js'
import tr from '@/modules/sandboxes/locales/tr.js'

/** Every leaf key of a message tree: "sandboxes.actions.create", … */
function keys(tree, prefix = '') {
  return Object.entries(tree).flatMap(([k, v]) => {
    const path = prefix ? `${prefix}.${k}` : k
    return v && typeof v === 'object' ? keys(v, path) : [path]
  })
}

function placeholders(text) {
  return [...String(text).matchAll(/\{(\w+)\}/g)].map(m => m[1]).sort()
}

function leaf(tree, path) {
  return path.split('.').reduce((node, k) => node?.[k], tree)
}

describe('sandboxes locales', () => {
  const enKeys = keys(en)
  const trKeys = keys(tr)

  it('has every English key in Turkish and no extra Turkish key', () => {
    expect(trKeys.filter(k => !enKeys.includes(k))).toEqual([])
    expect(enKeys.filter(k => !trKeys.includes(k))).toEqual([])
  })

  it('keeps every message under the sandboxes namespace', () => {
    expect(Object.keys(en)).toEqual(['sandboxes'])
    expect(Object.keys(tr)).toEqual(['sandboxes'])
  })

  it('uses the same placeholders in both languages', () => {
    const mismatched = enKeys.filter(k => placeholders(leaf(en, k)).join() !== placeholders(leaf(tr, k)).join())
    expect(mismatched).toEqual([])
  })

  it('has no empty message', () => {
    expect(enKeys.filter(k => !String(leaf(en, k)).trim())).toEqual([])
    expect(trKeys.filter(k => !String(leaf(tr, k)).trim())).toEqual([])
  })
})
