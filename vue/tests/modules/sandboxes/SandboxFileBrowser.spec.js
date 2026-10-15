import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import SandboxFileBrowser from '@/modules/sandboxes/components/SandboxFileBrowser.vue'
import { sandboxesApi } from '@/modules/sandboxes/api/sandboxes'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { freshPinia } from '@/test-support/helpers'
import { breadcrumbs, bytesToText, joinPath, parentPath } from '@/modules/sandboxes/utils'
import { sandboxI18n } from './fixtures'

vi.mock('@/modules/sandboxes/api/sandboxes', () => ({
  sandboxesApi: {
    files: { list: vi.fn(), read: vi.fn(), download: vi.fn(), write: vi.fn(), remove: vi.fn(), mkdir: vi.fn(), archive: vi.fn() },
  },
}))

const i18n = sandboxI18n()
let pinia
const mounted = []
const SBX = '01jb0000000000000000aaaa01'

const listing = {
  '/workspace': [
    { name: 'main.py', path: '/workspace/main.py', type: 'file', size: 21, mode: '0644', modified_at: '2026-10-10T12:00:00Z' },
    { name: 'src', path: '/workspace/src', type: 'dir', size: 4096, mode: '0755', modified_at: '2026-10-10T12:00:00Z' },
  ],
  '/workspace/src': [
    { name: 'util.py', path: '/workspace/src/util.py', type: 'file', size: 9, mode: '0644', modified_at: '2026-10-10T12:00:00Z' },
  ],
}

function b64(text) {
  return btoa(String.fromCharCode(...new TextEncoder().encode(text)))
}

async function mountBrowser(props = {}) {
  const wrapper = mount(SandboxFileBrowser, { props: { sandboxId: SBX, ...props }, global: { plugins: [pinia, i18n] } })
  mounted.push(wrapper)
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  pinia = freshPinia()
  vi.clearAllMocks()
  sandboxesApi.files.list.mockImplementation((id, path) => Promise.resolve({
    data: { data: { path, is_dir: true, entries: listing[path] ?? [], truncated: false } },
  }))
  useToast().toasts.value = []
})

afterEach(() => {
  while (mounted.length) mounted.pop().unmount()
})

describe('SandboxFileBrowser', () => {
  it('lists /workspace, folders first, and navigates into a folder and back by breadcrumb', async () => {
    const wrapper = await mountBrowser()

    expect(sandboxesApi.files.list).toHaveBeenCalledWith(SBX, '/workspace')
    expect(wrapper.findAll('[data-file-row]').map(r => r.attributes('data-file-row'))).toEqual(['src', 'main.py'])

    await wrapper.find('[data-file-row="src"] button').trigger('click')
    await flushPromises()
    expect(sandboxesApi.files.list).toHaveBeenLastCalledWith(SBX, '/workspace/src')
    expect(wrapper.findAll('[data-file-row]').map(r => r.attributes('data-file-row'))).toEqual(['util.py'])

    await wrapper.find('[data-crumb="/workspace"]').trigger('click')
    await flushPromises()
    expect(sandboxesApi.files.list).toHaveBeenLastCalledWith(SBX, '/workspace')
  })

  it('previews a text file and marks a binary one', async () => {
    sandboxesApi.files.read.mockResolvedValueOnce({ data: { data: { path: '/workspace/main.py', size: 21, content_base64: b64('print("hello world")\n') } } })
    const wrapper = await mountBrowser()

    await wrapper.find('[data-file-row="main.py"] button').trigger('click')
    await flushPromises()
    expect(sandboxesApi.files.read).toHaveBeenCalledWith(SBX, '/workspace/main.py')
    expect(wrapper.find('[data-file-preview]').text()).toContain('print("hello world")')

    sandboxesApi.files.read.mockResolvedValueOnce({ data: { data: { path: '/workspace/main.py', size: 4, content_base64: btoa('\u0000ÿþ\u0001') } } })
    await wrapper.find('[data-file-row="main.py"] button').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-file-preview]').text()).toContain('Binary file')
  })

  it('uploads the picked files as base64 into the current folder', async () => {
    sandboxesApi.files.write.mockResolvedValue({ data: { data: { path: '/workspace/notes.txt', size: 11 } } })
    const wrapper = await mountBrowser()

    const input = wrapper.find('input[data-upload-input]')
    const file  = new File(['hello notes'], 'notes.txt', { type: 'text/plain' })
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
    await input.trigger('change')
    await flushPromises()
    await new Promise(resolve => setTimeout(resolve, 0))
    await flushPromises()

    expect(sandboxesApi.files.write).toHaveBeenCalledWith(SBX, '/workspace/notes.txt', b64('hello notes'))
    expect(useToast().toasts.value.map(t => t.message)).toContain('1 file(s) uploaded.')
  })

  it('deletes a folder recursively after the confirmation', async () => {
    sandboxesApi.files.remove.mockResolvedValue({ data: { data: { path: '/workspace/src', deleted: true } } })
    const wrapper = await mountBrowser()

    await wrapper.find('[data-file-row="src"] button[title="Delete"]').trigger('click')
    await flushPromises()
    expect(useConfirm().current.value?.message).toBe('Delete the folder /workspace/src and everything in it?')
    useConfirm().accept()
    await flushPromises()

    expect(sandboxesApi.files.remove).toHaveBeenCalledWith(SBX, '/workspace/src', true)
    expect(useToast().toasts.value.map(t => t.message)).toContain('/workspace/src deleted.')
  })

  it('creates a folder', async () => {
    sandboxesApi.files.mkdir.mockResolvedValue({ data: { data: { path: '/workspace/data' } } })
    const wrapper = await mountBrowser()

    await wrapper.find('[data-action="new-folder"]').trigger('click')
    await wrapper.find('[data-new-folder] input').setValue('data')
    await wrapper.find('[data-new-folder]').trigger('submit')
    await flushPromises()

    expect(sandboxesApi.files.mkdir).toHaveBeenCalledWith(SBX, '/workspace/data')
  })

  it('shows a notice instead of listing while the sandbox is not running', async () => {
    const wrapper = await mountBrowser({ disabled: true })

    expect(sandboxesApi.files.list).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('The sandbox is not running.')
  })
})

describe('path helpers', () => {
  it('joins, climbs and splits sandbox paths', () => {
    expect(joinPath('/workspace', 'a.txt')).toBe('/workspace/a.txt')
    expect(joinPath('/', 'tmp')).toBe('/tmp')
    expect(parentPath('/workspace/src')).toBe('/workspace')
    expect(parentPath('/workspace')).toBe('/')
    expect(breadcrumbs('/workspace/src').map(c => c.path)).toEqual(['/', '/workspace', '/workspace/src'])
  })

  it('tells text from binary content', () => {
    expect(bytesToText(new TextEncoder().encode('héllo'))).toBe('héllo')
    expect(bytesToText(new Uint8Array([0xff, 0xfe, 0x00]))).toBeNull()
  })
})
