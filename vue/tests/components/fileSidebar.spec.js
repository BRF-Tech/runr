import { describe, it, expect, afterEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import FileSidebar from '@/components/FileSidebar.vue'
import { makeI18n } from '@/test-support/helpers'

// Uploads in the project file list: text files of the runtimes' languages and their dependency files
const mounted = []

function mountSidebar() {
  const wrapper = mount(FileSidebar, {
    props:  { files: { 'main.py': '' }, activeFile: 'main.py', isLatest: true },
    global: { plugins: [makeI18n()] },
  })
  mounted.push(wrapper)
  return wrapper
}

async function upload(wrapper, files) {
  const input = wrapper.find('input[type="file"]')
  Object.defineProperty(input.element, 'files', { value: files, configurable: true })
  await input.trigger('change')
}

afterEach(() => {
  while (mounted.length) mounted.pop().unmount()
})

describe('FileSidebar uploads', () => {
  it('accepts the sources and dependency files of the Java, .NET, Ruby and Bun runtimes', async () => {
    const wrapper = mountSidebar()
    const names = ['Main.java', 'pom.xml', 'Function.cs', 'Function.csproj', 'main.rb', 'Gemfile', 'Gemfile.lock', 'main.ts', 'View.tsx', 'lib.mjs', 'bun.lock']
    await upload(wrapper, names.map(name => new File(['text'], name)))

    await vi.waitFor(() => expect(wrapper.emitted('upload')).toHaveLength(names.length))
    expect(wrapper.emitted('upload').map(([file]) => file.name)).toEqual(names)
    expect(wrapper.text()).not.toContain('not supported')
  })

  it('still refuses other file types', async () => {
    const wrapper = mountSidebar()
    await upload(wrapper, [new File(['MZ'], 'tool.exe')])

    await vi.waitFor(() => expect(wrapper.text()).toContain('This file type is not supported'))
    expect(wrapper.emitted('upload')).toBeUndefined()
  })
})
