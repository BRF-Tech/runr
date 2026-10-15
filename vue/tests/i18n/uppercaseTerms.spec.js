// @vitest-environment jsdom
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { parse } from 'vue/compiler-sfc'
import en from '@/locales/en'
import tr from '@/locales/tr'
import TermText from '@/components/TermText.vue'
import { termSegments } from '@/utils/termCase'
import { withPlugins } from '../helpers'

// Under <html lang="tr"> CSS text-transform: uppercase turns every i into İ, also in the
// English terms the Turkish UI keeps ("KNATİVE BÖLGELERİ", "BASE DOMAİN"). TermText marks
// those terms lang="en"; this spec keeps every upper-cased template text on it.

const sources = import.meta.glob('/src/**/*.vue', { query: '?raw', import: 'default', eager: true })
const moduleLocales = import.meta.glob('/src/modules/*/locales/*.js', { eager: true, import: 'default' })

function flatten(obj, prefix = '', out = {}) {
  for (const [k, v] of Object.entries(obj ?? {})) {
    const key = prefix ? `${prefix}.${k}` : k
    if (v && typeof v === 'object' && !Array.isArray(v)) flatten(v, key, out)
    else out[key] = String(v)
  }
  return out
}

const messages = { en: flatten(en), tr: flatten(tr) }
for (const [file, data] of Object.entries(moduleLocales)) {
  const lang = file.endsWith('/tr.js') ? 'tr' : 'en'
  Object.assign(messages[lang], flatten(data))
}

/** What text-transform: uppercase shows under <html lang="tr">. */
function rendered(segments) {
  return segments.map((s) => s.text.toLocaleUpperCase(s.en ? 'en' : 'tr')).join('')
}

/** English terms of a Turkish message that Turkish upper-casing changes (they contain an i). */
function brokenTerms(key) {
  const strip = (s) => (s ?? '').replace(/\{[^}]*\}/g, ' ')   // {placeholders} are not text
  return termSegments(strip(messages.tr[key]), strip(messages.en[key]))
    .filter((s) => s.en && /i/.test(s.text))
    .map((s) => s.text.trim())
}

// Classes that upper-case their text (Tailwind `uppercase`, main.css `.label`, the forms'
// scoped `.section-title`); text-transform is inherited by the children.
const UPPER = /(^|[\s'"`:])(uppercase|label|section-title)(?=[\s'"`]|$)/
const RESET = /(^|[\s'"`])normal-case(?=[\s'"`]|$)/

function classesOf(node) {
  return node.props
    .map((p) => (p.type === 6 && p.name === 'class' ? p.value?.content
      : p.type === 7 && p.name === 'bind' && p.arg?.content === 'class' ? p.exp?.content : ''))
    .join(' ')
}

const isLangEn = (node) => node.props.some((p) => p.type === 6 && p.name === 'lang' && p.value?.content === 'en')

/** i18n keys a template expression translates: t('a.b'), t(`a.${x}`) (every matching key). */
function keysOf(exp) {
  const keys = [...exp.matchAll(/\bt\(\s*'([^']+)'/g)].map((m) => m[1])
  for (const m of exp.matchAll(/\bt\(\s*`([^`]+)`/g)) {
    const re = new RegExp('^' + m[1].split(/\$\{[^}]*\}/).map((p) => p.replace(/[.*+?^$()|[\]\\]/g, '\\$&')).join('[^.]+') + '$')
    keys.push(...Object.keys(messages.tr).filter((k) => re.test(k)))
  }
  return keys
}

function upperCasedTexts() {
  const found = []
  for (const [file, source] of Object.entries(sources)) {
    const ast = parse(source, { filename: file }).descriptor.template?.ast
    if (!ast) continue
    const walk = (node, upper) => {
      if (node.type === 1) {
        const cls = classesOf(node)
        if (isLangEn(node) || RESET.test(cls)) return
        upper = upper || UPPER.test(cls)
      }
      if (node.type === 5 && upper) {
        for (const key of keysOf(node.content.content ?? '')) found.push({ file, line: node.loc.start.line, key })
      }
      for (const child of node.children ?? []) walk(child, upper)
    }
    walk(ast, false)
  }
  return found
}

describe('termSegments', () => {
  const cases = [
    ['Knative Bölgeleri', 'Knative Regions', 'KNATIVE BÖLGELERİ'],
    ['Base Domain', 'Base Domain', 'BASE DOMAIN'],
    ['Bellek (MiB)', 'Memory (MiB)', 'BELLEK (MIB)'],
    ['Redis Adresi', 'Redis Address', 'REDIS ADRESİ'],
    ["İzin verilen registry'ler", 'Allowed registries', "İZİN VERİLEN REGISTRY'LER"],
    ['Silme süresi (commit gelmeyen saat)', 'Delete after (hours without a commit)', 'SİLME SÜRESİ (COMMIT GELMEYEN SAAT)'],
    ['Disk (MiB)', 'Disk (MiB)', 'DİSK (MIB)'],
    ['Şifre', 'Password', 'ŞİFRE'],
  ]

  it.each(cases)('%s is upper-cased as %s with the English terms in English', (text, english, upper) => {
    expect(rendered(termSegments(text, english))).toBe(upper)
  })

  it('keeps neighbouring terms in one run and the text unchanged', () => {
    const segments = termSegments('IdP Entity ID girin', 'Enter the IdP Entity ID')
    expect(segments).toEqual([{ text: 'IdP Entity ID', en: true }, { text: ' girin', en: false }])
    expect(segments.map((s) => s.text).join('')).toBe('IdP Entity ID girin')
  })
})

describe('TermText', () => {
  // the rendered text as the DOM holds it (VTU's text() trims each node of the fragment)
  const textOf = (w) => w.html({ raw: true }).replace(/<[^>]+>/g, '')

  it('marks the English terms of a Turkish message lang="en"', () => {
    const w = mount(TermText, { props: { path: 'settings.regions.section' }, ...withPlugins('tr') })
    expect(w.html({ raw: true })).toBe('<span lang="en">Knative</span> Bölgeleri')
    expect(textOf(w)).toBe(messages.tr['settings.regions.section'])
  })

  it('renders the English message as plain text', () => {
    const w = mount(TermText, { props: { path: 'settings.regions.section' }, ...withPlugins('en') })
    expect(w.html({ raw: true })).toBe('Knative Regions')
  })

  it('interpolates params; a value that reads the same in both languages keeps English casing', () => {
    const w = mount(TermText, { props: { path: 'bindings.data.deleteTypeName', params: { name: 'minio-data' } }, ...withPlugins('tr') })
    expect(textOf(w)).toBe('Onaylamak için minio-data yazın')
    expect(w.find('span[lang="en"]').text()).toBe('minio-data')
  })
})

describe('upper-cased template texts', () => {
  const texts = upperCasedTexts()

  it('are found (the scan reads the templates)', () => {
    expect(texts.length).toBeGreaterThan(300)
  })

  it('keep the English terms of the Turkish messages in English casing (TermText)', () => {
    const broken = texts
      .map((x) => ({ ...x, terms: brokenTerms(x.key) }))
      .filter((x) => x.terms.length)
      .map((x) => `${x.file}:${x.line} ${x.key} → ${x.terms.join(', ')}`)
    expect(broken).toEqual([])
  })
})
