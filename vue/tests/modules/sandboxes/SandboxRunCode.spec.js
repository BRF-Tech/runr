import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import SandboxRunCode from '@/modules/sandboxes/components/SandboxRunCode.vue'
import { sandboxesApi } from '@/modules/sandboxes/api/sandboxes'
import { freshPinia } from '@/test-support/helpers'
import { defaultLanguage } from '@/modules/sandboxes/utils'
import { sandboxI18n, sandbox, apiReject } from './fixtures'

vi.mock('@/modules/sandboxes/api/sandboxes', () => ({ sandboxesApi: { run: vi.fn() } }))

const i18n = sandboxI18n()
let pinia
const mounted = []

async function mountRun(props = {}) {
  const wrapper = mount(SandboxRunCode, { props: { sandbox: sandbox('01jb0000000000000000aaaa01'), ...props }, global: { plugins: [pinia, i18n] } })
  mounted.push(wrapper)
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  pinia = freshPinia()
  vi.clearAllMocks()
})

afterEach(() => {
  while (mounted.length) mounted.pop().unmount()
})

describe('SandboxRunCode', () => {
  it('runs the code in the sandbox language and shows output, changed files and images', async () => {
    sandboxesApi.run.mockResolvedValue({ data: { data: {
      language: 'python', exit_code: 0, stdout: '2\n', stderr: '', timed_out: false, duration_ms: 44, truncated: false,
      files_changed: [{ path: '/output/plot.png', size: 2048 }],
      outputs: [{ path: '/output/plot.png', mime: 'image/png', size: 2048, data_base64: 'iVBORw0KGgo=' }],
    } } })
    const wrapper = await mountRun()

    expect(wrapper.find('select[name="language"]').element.value).toBe('python')
    await wrapper.find('textarea[name="code"]').setValue('print(1+1)')
    await wrapper.find('[data-action="run-code"]').trigger('click')
    await flushPromises()

    expect(sandboxesApi.run).toHaveBeenCalledWith('01jb0000000000000000aaaa01', { language: 'python', code: 'print(1+1)' })
    expect(wrapper.find('[data-exit-code]').text()).toBe('exit 0')
    expect(wrapper.find('[data-stdout]').text()).toBe('2')
    expect(wrapper.text()).toContain('/output/plot.png')
    expect(wrapper.find('[data-output-image]').attributes('src')).toBe('data:image/png;base64,iVBORw0KGgo=')
  })

  it('shows a refused run', async () => {
    sandboxesApi.run.mockRejectedValue(apiReject(409, { message: 'The sandbox is paused.', reason: 'not_running' }))
    const wrapper = await mountRun()

    await wrapper.find('textarea[name="code"]').setValue('echo hi')
    await wrapper.find('[data-action="run-code"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('The sandbox is paused.')
  })

  it('picks the interpreter from the runtime', () => {
    expect(defaultLanguage({ runtime: 'nodejs' })).toBe('javascript')
    expect(defaultLanguage({ runtime: 'pytorch' })).toBe('python')
    expect(defaultLanguage({ runtime: 'q' })).toBe('bash')
    expect(defaultLanguage({ runtime: null, image: 'alpine' })).toBe('python')
  })
})
