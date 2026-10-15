import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import MonacoEditor from '@/components/MonacoEditor.vue'
import { useRuntimesStore, normalizeRuntime } from '@/stores/runtimes'
import { freshPinia, catalog } from '@/test-support/helpers'

vi.mock('@/api/runtimes', () => ({ runtimesApi: { list: vi.fn() } }))
vi.mock('@/api/settings', () => ({ settingsApi: { get: vi.fn() } }))
// The editor component itself needs a browser: a stub that shows the language it is given
vi.mock('@guolao/vue-monaco-editor', async () => {
  const { h } = await import('vue')
  return {
    VueMonacoEditor: {
      name: 'VueMonacoEditor',
      props: ['value', 'language', 'options', 'height'],
      setup: (props) => () => h('div', { class: 'monaco-stub', 'data-language': props.language }),
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

  it('lets an explicit language win', () => {
    expect(languageOf({ filename: 'main.q', runtime: 'q', language: 'yaml' })).toBe('yaml')
  })
})
