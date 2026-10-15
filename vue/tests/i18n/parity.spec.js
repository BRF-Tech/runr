import { describe, it, expect } from 'vitest'
import en from '@/locales/en'
import tr from '@/locales/tr'

// Discovered at build time, so adding or removing a locale part needs no test change.
const corePartsEn = import.meta.glob('/src/locales/en/*.js', { eager: true, import: 'default' })
const corePartsTr = import.meta.glob('/src/locales/tr/*.js', { eager: true, import: 'default' })
const moduleLocales = import.meta.glob('/src/modules/*/locales/*.js', { eager: true, import: 'default' })

function flatten(obj, prefix = '', out = {}) {
  for (const [k, v] of Object.entries(obj)) {
    const key = prefix ? `${prefix}.${k}` : k
    if (v && typeof v === 'object' && !Array.isArray(v)) flatten(v, key, out)
    else out[key] = v
  }
  return out
}

function placeholders(value) {
  if (typeof value !== 'string') return []
  return [...new Set([...value.matchAll(/\{\s*(\w+)\s*\}/g)].map(m => m[1]))].sort()
}

function keyDiff(a, b) {
  const ka = Object.keys(flatten(a))
  const kb = new Set(Object.keys(flatten(b)))
  return ka.filter(k => !kb.has(k)).sort()
}

function placeholderMismatches(a, b) {
  const fa = flatten(a)
  const fb = flatten(b)
  const out = []
  for (const [k, v] of Object.entries(fa)) {
    if (!(k in fb)) continue
    const pa = placeholders(v).join(',')
    const pb = placeholders(fb[k]).join(',')
    if (pa !== pb) out.push(`${k}: {${pa}} vs {${pb}}`)
  }
  return out
}

const baseName = path => path.split('/').pop()

describe('core locales', () => {
  it('loads the full message catalog', () => {
    expect(Object.keys(flatten(en)).length).toBeGreaterThan(500)
    expect(Object.keys(corePartsEn).length).toBeGreaterThan(3)
  })

  it('every en key exists in tr', () => {
    expect(keyDiff(en, tr)).toEqual([])
  })

  it('every tr key exists in en', () => {
    expect(keyDiff(tr, en)).toEqual([])
  })

  it('translations use the same {placeholders}', () => {
    expect(placeholderMismatches(en, tr)).toEqual([])
  })

  it('en and tr are split into the same part files', () => {
    const namesEn = Object.keys(corePartsEn).map(baseName).sort()
    const namesTr = Object.keys(corePartsTr).map(baseName).sort()
    expect(namesEn.length).toBeGreaterThan(0)
    expect(namesTr).toEqual(namesEn)
  })

  // locales/{en,tr}.js spread the parts into one object: a top-level key that two
  // parts share is replaced wholesale by the later part and its strings vanish.
  it.each([['en', corePartsEn, en], ['tr', corePartsTr, tr]])('%s: no part file loses keys when merged', (_, parts, merged) => {
    const flatMerged = flatten(merged)
    const lost = []
    for (const [path, part] of Object.entries(parts)) {
      for (const [key, value] of Object.entries(flatten(part))) {
        if (flatMerged[key] !== value) lost.push(`${baseName(path)}: ${key}`)
      }
    }
    expect(lost).toEqual([])
  })
})

describe('module locales', () => {
  const modules = {}
  for (const [path, messages] of Object.entries(moduleLocales)) {
    const [, mod, file] = path.match(/\/modules\/([^/]+)\/locales\/([^/]+)\.js$/)
    modules[mod] ??= {}
    modules[mod][file] = messages
  }
  const names = Object.keys(modules).sort()

  it('modules ship locales', () => {
    expect(names.length).toBeGreaterThan(0)
  })

  it.each(names)('%s ships both en and tr', (mod) => {
    expect(Object.keys(modules[mod]).sort()).toEqual(['en', 'tr'])
  })

  it.each(names)('%s: en and tr have the same keys', (mod) => {
    const { en: men = {}, tr: mtr = {} } = modules[mod]
    expect({ missingInTr: keyDiff(men, mtr), missingInEn: keyDiff(mtr, men) })
      .toEqual({ missingInTr: [], missingInEn: [] })
  })

  it.each(names)('%s: translations use the same {placeholders}', (mod) => {
    const { en: men = {}, tr: mtr = {} } = modules[mod]
    expect(placeholderMismatches(men, mtr)).toEqual([])
  })
})
