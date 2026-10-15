import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ModelLibrary from '@/components/models/ModelLibrary.vue'
import { aiApi } from '@/api/ai'
import { useAiStore } from '@/stores/ai'
import { makeI18n, freshPinia } from '@/test-support/helpers'

vi.mock('@/api/ai', () => ({ aiApi: { createSnapshot: vi.fn(), deleteSnapshot: vi.fn(), snapshotLogs: vi.fn(), deleteModel: vi.fn() } }))

const i18n = makeI18n()
let pinia
const mounted = []

const volumePending = 'The model volume runr-models is Pending: failed to provision volume with StorageClass "local-path": NodePath only supports ReadWriteOnce and ReadWriteOncePod (1.22+) access modes'

function model(snapshots) {
  return {
    id: 'm1', name: 'all-minilm-l6-v2', source: 'hub', hub_id: 'sentence-transformers/all-MiniLM-L6-v2',
    revision: 'main', revision_sha: 'a'.repeat(40), task: 'feature-extraction', size_bytes: 90 * 1024 * 1024, snapshots,
  }
}

function mountLibrary(snapshots) {
  useAiStore().models = [model(snapshots)]
  const wrapper = mount(ModelLibrary, { global: { plugins: [pinia, i18n] } })
  mounted.push(wrapper)
  return wrapper
}

beforeEach(() => {
  pinia = freshPinia()
  vi.clearAllMocks()
})
afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

describe('Model library → offline copies', () => {
  it('shows a copy that waits for the model volume as pending, with the reason', async () => {
    const w = mountLibrary([{ id: 's1', region_name: 'eu-1', status: 'pending', message: volumePending, size_bytes: null, files_count: null }])
    await flushPromises()

    const row = w.find('[data-snapshot-region="eu-1"]')
    expect(row.text()).toContain('Pending')
    expect(row.text()).not.toContain('Copying')
    // the row truncates the message: the whole reason is its tooltip
    const message = row.find('[data-testid="snapshot-message"]')
    expect(message.text()).toBe(volumePending)
    expect(message.attributes('title')).toBe(volumePending)
  })

  it('shows a running copy without a message', async () => {
    const w = mountLibrary([{ id: 's1', region_name: 'eu-1', status: 'running', message: null, size_bytes: null, files_count: null }])
    await flushPromises()

    const row = w.find('[data-snapshot-region="eu-1"]')
    expect(row.text()).toContain('Copying')
    expect(row.find('[data-testid="snapshot-message"]').exists()).toBe(false)
  })

  it('loads the logs of a copy', async () => {
    aiApi.snapshotLogs.mockResolvedValue({ data: { logs: 'Fetching 6 files' } })
    const w = mountLibrary([{ id: 's1', region_name: 'eu-1', status: 'running', message: null, size_bytes: null, files_count: null }])
    await flushPromises()

    await w.find('[data-snapshot-region="eu-1"] button[title="Logs"]').trigger('click')
    await flushPromises()

    expect(aiApi.snapshotLogs).toHaveBeenCalledWith('m1', 's1')
    expect(w.find('[data-snapshot-region="eu-1"] pre').text()).toBe('Fetching 6 files')
  })
})
