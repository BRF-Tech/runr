import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import MonacoEditor from '@/components/MonacoEditor.vue'
import { useRuntimesStore, normalizeRuntime } from '@/stores/runtimes'
import { freshPinia, catalog, builtinCatalog } from '@/test-support/helpers'

vi.mock('@/api/runtimes', () => ({ runtimesApi: { list: vi.fn() } }))
vi.mock('@/api/settings', () => ({ settingsApi: { get: vi.fn() } }))
// The editor component itself needs a browser: a stub that shows the language it is given
const fakeMonaco = vi.hoisted(() => ({ editor: { defineTheme: vi.fn() } }))
vi.mock('@guolao/vue-monaco-editor', async () => {
  const { h } = await import('vue')
  return {
    VueMonacoEditor: {
      name: 'VueMonacoEditor',
      props: ['value', 'language', 'options', 'height'],
      emits: ['beforeMount', 'change'],
      setup: (props, { emit }) => {
        emit('beforeMount', fakeMonaco)
        return () => h('div', { class: 'monaco-stub', 'data-language': props.language, 'data-theme': props.options?.theme })
      },
    },
  }
})

let pinia

function languageOf(props) {
  return mount(MonacoEditor, { props, global: { plugins: [pinia] } }).find('.monaco-stub').attributes('data-language')
}

beforeEach(() => {
  pinia = freshPinia()
  useRuntimesStore().list = [catalog.q, catalog.c].map(normalizeRuntime)
})

describe('MonacoEditor language', () => {
  it('uses the manifest language for files of the project runtime', () => {
    expect(languageOf({ filename: 'main.q', runtime: 'q' })).toBe('lua')
    expect(languageOf({ filename: 'util.h', runtime: 'c' })).toBe('c')
  })

  it('falls back to the extension map for other files', () => {
    expect(languageOf({ filename: 'notes.md', runtime: 'q' })).toBe('markdown')
    expect(languageOf({ filename: 'main.rs' })).toBe('rust')
    expect(languageOf({ filename: 'run.sh' })).toBe('shell')
    expect(languageOf({ filename: 'main.q' })).toBe('plaintext')
    expect(languageOf({ filename: 'Dockerfile' })).toBe('dockerfile')
  })

  it('highlights Java, C#, Ruby and TypeScript files and their dependency files', () => {
    // the manifests' languages once the catalog has the runtimes
    useRuntimesStore().list = [catalog.q, ...Object.values(builtinCatalog)].map(normalizeRuntime)
    expect(languageOf({ filename: 'Main.java', runtime: 'java' })).toBe('java')
    expect(languageOf({ filename: 'Function.cs', runtime: 'dotnet' })).toBe('csharp')
    expect(languageOf({ filename: 'main.rb', runtime: 'ruby' })).toBe('ruby')
    expect(languageOf({ filename: 'main.ts', runtime: 'bun' })).toBe('typescript')
    expect(languageOf({ filename: 'util.js', runtime: 'bun' })).toBe('typescript')   // Bun's manifest language
    expect(languageOf({ filename: 'Function.csproj', runtime: 'dotnet' })).toBe('xml')
    expect(languageOf({ filename: 'pom.xml', runtime: 'java' })).toBe('xml')
    expect(languageOf({ filename: 'Gemfile', runtime: 'ruby' })).toBe('ruby')

    // the extension map without a catalog entry
    useRuntimesStore().list = []
    expect(languageOf({ filename: 'Main.java' })).toBe('java')
    expect(languageOf({ filename: 'Function.cs' })).toBe('csharp')
    expect(languageOf({ filename: 'main.rb' })).toBe('ruby')
    expect(languageOf({ filename: 'main.ts' })).toBe('typescript')
    expect(languageOf({ filename: 'View.tsx' })).toBe('typescript')
    expect(languageOf({ filename: 'lib.mjs' })).toBe('javascript')
  })

  it('lets an explicit language win', () => {
    expect(languageOf({ filename: 'main.q', runtime: 'q', language: 'yaml' })).toBe('yaml')
  })
})

describe('MonacoEditor theme', () => {
  it("defines vs-dark with the console's accents: the brand and the dark theme's tones, no blue", () => {
    document.documentElement.style.setProperty('--brand-400', '0 159 227')
    fakeMonaco.editor.defineTheme.mockClear()
    const stub = mount(MonacoEditor, { props: { filename: 'main.py' }, global: { plugins: [pinia] } }).find('.monaco-stub')
    document.documentElement.removeAttribute('style')

    expect(stub.attributes('data-theme')).toBe('runr-dark')
    const [name, theme] = fakeMonaco.editor.defineTheme.mock.calls.at(-1)
    expect(name).toBe('runr-dark')
    expect(theme).toMatchObject({ base: 'vs-dark', inherit: true })
    expect(theme.colors['editorCursor.foreground']).toBe('#009fe3')        // the branding's brand-400
    expect(theme.colors['editor.background']).toBe('#0a0a0a')              // RunR's dark page
    expect(theme.colors.focusBorder).toBe('#f97316')
    for (const value of Object.values(theme.colors)) expect(value).toMatch(/^#[0-9a-f]{6}([0-9a-f]{2})?$/)
  })
})
