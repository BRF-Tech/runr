import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ref } from 'vue'
import { useRuntimesStore, normalizeRuntime } from '@/stores/runtimes'
import {
  runtimeLabel, runtimeIcon, runtimeExtension, runtimeHandlerPattern, runtimeLanguageFor,
} from '@/utils/runtimes'
import { runtimeTag } from '@/utils/projectHelpers'
import { useProjectEditor } from '@/composables/useProjectEditor'
import { projectsApi } from '@/api/projects'
import { freshPinia, catalog, builtinCatalog, builtinTemplates, Q_ICON } from '@/test-support/helpers'

vi.mock('@/api/runtimes', () => ({ runtimesApi: { list: vi.fn() } }))
vi.mock('@/api/settings', () => ({ settingsApi: { get: vi.fn() } }))
vi.mock('@/api/projects', () => ({
  projectsApi: { saveCode: vi.fn(), saveFiles: vi.fn(), getCode: vi.fn(), getFiles: vi.fn(), versionFiles: vi.fn() },
}))

function useCatalog(...entries) {
  useRuntimesStore().list = entries.map(normalizeRuntime)
}

beforeEach(() => {
  freshPinia()
  vi.clearAllMocks()
})

describe('runtime display helpers', () => {
  it('use the built-in names and icons without a catalog', () => {
    expect(runtimeLabel('python')).toBe('Python')
    expect(runtimeLabel('nodejs')).toBe('Node.js')
    expect(runtimeLabel('zig')).toBe('zig')
    expect(runtimeIcon('python')).toMatch(/python/)
    expect(runtimeIcon('zig')).toBeNull()
  })

  it('use the manifest name and icon of catalog runtimes', () => {
    useCatalog(catalog.q, catalog.c)
    expect(runtimeLabel('q')).toBe('Q')
    expect(runtimeIcon('q')).toBe(Q_ICON)
    expect(runtimeIcon('c')).toBeNull()
  })

  it('show only image URLs and image data URIs as icons', () => {
    useCatalog(
      { ...catalog.q, id: 'a', icon: 'javascript:alert(1)' },
      { ...catalog.q, id: 'b', icon: 'https://cdn.example.test/b.svg' },
      { ...catalog.q, id: 'c2', icon: 'data:text/html;base64,PHNjcmlwdD4=' },
    )
    expect(runtimeIcon('a')).toBeNull()
    expect(runtimeIcon('b')).toBe('https://cdn.example.test/b.svg')
    expect(runtimeIcon('c2')).toBeNull()
  })
})

describe('runtime extension, handler check and editor language', () => {
  it('take the extension from the manifest, else the built-in one', () => {
    expect(runtimeExtension('python')).toBe('py')
    expect(runtimeExtension('pytorch')).toBe('py')
    expect(runtimeExtension('q')).toBeNull()

    useCatalog(catalog.q, catalog.c)
    expect(runtimeExtension('q')).toBe('q')
    expect(runtimeExtension('c')).toBe('c')
    expect(runtimeTag({ service_type: 'xaas', runtime: 'q', runtime_version: '1.0' })).toBe('q 1.0')
  })

  it('build the handler check from handler.pattern with the escaped handler name', () => {
    useCatalog({ ...catalog.q, handler: { pattern: 'fn\\s+{handler}\\s*\\(' } })

    const re = runtimeHandlerPattern('q', 'my.fn')
    expect(re).toBeInstanceOf(RegExp)
    expect(re.test('fn my.fn (event)')).toBe(true)
    expect(re.test('fn myXfn (event)')).toBe(false)   // the dot is literal
    expect(runtimeHandlerPattern('q').test('fn handler(')).toBe(true)
  })

  it('skip the check for runtimes without a pattern or with a pattern JavaScript can not compile', () => {
    useCatalog(catalog.c, { ...catalog.q, handler: { pattern: '(?<open' } })
    expect(runtimeHandlerPattern('c')).toBeNull()
    expect(runtimeHandlerPattern('q')).toBeNull()
  })

  it('keep the built-in checks until the catalog is loaded', () => {
    expect(runtimeHandlerPattern('python').test('def handler(event, context):')).toBe(true)
    expect(runtimeHandlerPattern('go').test('func Handler(event interface{}')).toBe(true)
    expect(runtimeHandlerPattern('q')).toBeNull()
  })

  it('use the manifest language for the runtime\'s own extensions only', () => {
    useCatalog(catalog.q, catalog.c)
    expect(runtimeLanguageFor('q', 'main.q')).toBe('lua')
    expect(runtimeLanguageFor('q', 'README.md')).toBeNull()
    expect(runtimeLanguageFor('c', 'lib.h')).toBe('c')
    expect(runtimeLanguageFor('unknown', 'main.q')).toBeNull()
  })
})

describe('the Java, .NET, Ruby and Bun runtimes', () => {
  const ids = ['java', 'dotnet', 'ruby', 'bun']
  // The starter file with the handler renamed: the check must not find it
  const withoutHandler = {
    java:   builtinTemplates.java.replace('handler(', 'process('),
    dotnet: builtinTemplates.dotnet.replace('Handler(', 'Process('),
    ruby:   builtinTemplates.ruby.replace('def handler', 'def process'),
    bun:    builtinTemplates.bun.replace('function handler', 'function process'),
  }

  it('have built-in names, icons and extensions until the catalog is loaded', () => {
    expect(ids.map(id => runtimeLabel(id))).toEqual(['Java', '.NET (C#)', 'Ruby', 'TypeScript (Bun)'])
    // an asset URL, or the SVG itself as a data URI (Vite inlines small assets): one icon each
    for (const id of ids) expect(runtimeIcon(id)).toMatch(/^data:image\/svg\+xml,|\.svg$/)
    expect(new Set(ids.map(id => runtimeIcon(id))).size).toBe(4)
    expect(ids.map(id => runtimeExtension(id))).toEqual(['java', 'cs', 'rb', 'ts'])
    expect(runtimeTag({ service_type: 'faas', runtime: 'dotnet', runtime_version: '10.0' })).toBe('cs 10.0')
  })

  it.each(ids)('%s: the built-in handler check finds the starter file\'s handler, not an empty or other file', (id) => {
    const re = runtimeHandlerPattern(id)
    expect(re).toBeInstanceOf(RegExp)
    expect(re.test(builtinTemplates[id])).toBe(true)
    expect(re.test('')).toBe(false)
    expect(re.test(withoutHandler[id])).toBe(false)
  })

  it('accept the other export forms of a Bun handler and a Ruby class method', () => {
    const bun = runtimeHandlerPattern('bun')
    expect(bun.test('export const handler = async (event) => ({ statusCode: 200 })')).toBe(true)
    expect(bun.test('async function handler() {}\nexport { handler }')).toBe(true)
    expect(bun.test('export default async function handler() {}')).toBe(true)
    expect(bun.test('exports.handler = async () => ({})')).toBe(true)
    expect(bun.test('async function handler() {}')).toBe(false)   // not exported

    const ruby = runtimeHandlerPattern('ruby')
    expect(ruby.test('class App\n  def self.handler(event, context)\n  end\nend')).toBe(true)
    expect(ruby.test('def handlers(event)\nend')).toBe(false)
  })

  it('compile the manifest handler patterns in JavaScript with the project\'s handler name', () => {
    useCatalog(...Object.values(builtinCatalog))
    for (const id of ids) {
      const re = runtimeHandlerPattern(id, builtinCatalog[id].handler.function)
      expect(re, id).toBeInstanceOf(RegExp)
      expect(re.test(builtinTemplates[id]), id).toBe(true)
      expect(re.test(''), id).toBe(false)
      expect(re.test(withoutHandler[id]), id).toBe(false)
    }
    expect(runtimeHandlerPattern('java', 'process').test(builtinTemplates.java)).toBe(false)
    expect(runtimeHandlerPattern('dotnet', 'handler').test(builtinTemplates.dotnet)).toBe(false)   // C# names are case-sensitive
  })

  it('give the handler files their manifest language', () => {
    useCatalog(...Object.values(builtinCatalog))
    expect(runtimeLanguageFor('java', 'Main.java')).toBe('java')
    expect(runtimeLanguageFor('dotnet', 'Function.cs')).toBe('csharp')
    expect(runtimeLanguageFor('ruby', 'main.rb')).toBe('ruby')
    expect(runtimeLanguageFor('bun', 'main.ts')).toBe('typescript')
    expect(runtimeLanguageFor('bun', 'View.tsx')).toBe('typescript')
    expect(runtimeLanguageFor('dotnet', 'Function.csproj')).toBeNull()
    expect(runtimeLanguageFor('java', 'pom.xml')).toBeNull()
  })

  it('check the main file of a FaaS project before a save', async () => {
    useCatalog(...Object.values(builtinCatalog))
    const ed = useProjectEditor({
      project:        ref({ id: 'p3', service_type: 'faas', runtime: 'dotnet', config: { handler: 'Function.Handler' } }),
      versions:       ref([]),
      versionsLoaded: ref(false),
      loadVersionsFn: vi.fn(),
    })
    expect(ed.mainFile.value).toBe('Function.cs')
    expect(ed.handlerName.value).toBe('Handler')

    ed.selectedVersionFiles.value = { 'Function.cs': withoutHandler.dotnet }
    await ed.save()
    expect(ed.handlerError.value).toBe(true)
    expect(projectsApi.saveFiles).not.toHaveBeenCalled()

    projectsApi.saveFiles.mockResolvedValue({ data: {} })
    ed.selectedVersionFiles.value = { 'Function.cs': builtinTemplates.dotnet }
    await ed.save()
    expect(ed.handlerError.value).toBe(false)
    expect(projectsApi.saveFiles).toHaveBeenCalledWith('p3', [{ name: 'Function.cs', content: builtinTemplates.dotnet }], undefined)
  })
})

describe('useProjectEditor handler derivation', () => {
  function editor(projectValue) {
    return useProjectEditor({
      project:         ref(projectValue),
      versions:        ref([]),
      versionsLoaded:  ref(false),
      loadVersionsFn:  vi.fn(),
    })
  }

  it('derives main file and handler name for XaaS and FaaS projects', () => {
    useCatalog(catalog.q)

    const xaas = editor({ service_type: 'xaas', runtime: 'q', config: { filename: 'main.q', handler: 'greet' } })
    expect(xaas.mainFile.value).toBe('main.q')
    expect(xaas.handlerName.value).toBe('greet')

    const faas = editor({ service_type: 'faas', runtime: 'q', config: { handler: 'app.run' } })
    expect(faas.mainFile.value).toBe('app.q')
    expect(faas.handlerName.value).toBe('run')
  })

  it('blocks a save whose main file lacks the manifest handler', async () => {
    useCatalog({ ...catalog.q, handler: { pattern: 'fn\\s+{handler}\\b' } })
    const ed = editor({ id: 'p1', service_type: 'xaas', runtime: 'q', config: { filename: 'main.q', handler: 'greet' } })

    ed.selectedVersionFiles.value = { 'main.q': 'say "hello"' }
    await ed.save()
    expect(ed.handlerError.value).toBe(true)
    expect(projectsApi.saveCode).not.toHaveBeenCalled()

    projectsApi.saveCode.mockResolvedValue({ data: {} })
    ed.selectedVersionFiles.value = { 'main.q': 'fn greet\n  say "hello"' }
    await ed.save()
    expect(ed.handlerError.value).toBe(false)
    expect(projectsApi.saveCode).toHaveBeenCalledWith('p1', 'fn greet\n  say "hello"', undefined)
  })

  it('does not check runtimes without a handler pattern', async () => {
    useCatalog(catalog.c)
    projectsApi.saveCode.mockResolvedValue({ data: {} })
    const ed = editor({ id: 'p2', service_type: 'xaas', runtime: 'c', config: { filename: 'main.c' } })

    ed.selectedVersionFiles.value = { 'main.c': 'int main(void) { return 0; }' }
    await ed.save()
    expect(ed.handlerError.value).toBe(false)
    expect(projectsApi.saveCode).toHaveBeenCalled()
  })
})
