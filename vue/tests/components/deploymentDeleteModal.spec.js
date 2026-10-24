import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError, CanceledError } from 'axios'
import i18n from '@/i18n'
import { describeConnectionFailure } from '@/api/errors'
import { apiError } from '@/test-support/helpers'
import { withPlugins } from '../helpers'

const projectsApi = vi.hoisted(() => ({ deleteDeployment: vi.fn() }))
vi.mock('@/api/projects', () => ({ projectsApi }))

import DeploymentDeleteModal from '@/components/DeploymentDeleteModal.vue'

// The modal dropped the error and said "An error occurred." for every failure: a refusal of the
// API and a server it could not reach alike.

const project = { id: 'p1', name: 'api' }
const deployment = { id: 'd1', status: 'failed', k8s_job_name: 'api-d1' }

async function deleteWith(rejection) {
  projectsApi.deleteDeployment.mockRejectedValueOnce(rejection)
  const w = mount(DeploymentDeleteModal, { props: { project, deployment }, global: withPlugins('en').global })
  await w.find('[data-testid="deployment-delete-confirm"]').trigger('click')
  await flushPromises()
  return w
}

const errorText = (w) => w.find('[data-testid="deployment-delete-error"]')

beforeEach(() => { i18n.global.locale.value = 'en' })
afterEach(() => { i18n.global.locale.value = 'tr' })

describe('DeploymentDeleteModal errors', () => {
  it('says the console could not reach the server when the call gets no answer', async () => {
    const w = await deleteWith(describeConnectionFailure(new AxiosError('Network Error', AxiosError.ERR_NETWORK, {}, {})))
    expect(errorText(w).text()).toBe('The console could not reach the server. Check your connection and try again.')
    expect(w.emitted('deleted')).toBeUndefined()
  })

  it('shows the API\'s message', async () => {
    const w = await deleteWith(apiError(409, { message: 'The deployment still serves traffic.' }))
    expect(errorText(w).text()).toBe('The deployment still serves traffic.')
  })

  it('falls back to "An error occurred." without one', async () => {
    const w = await deleteWith(apiError(500, {}))
    expect(errorText(w).text()).toBe('An error occurred.')
  })

  it('shows nothing for a cancelled request', async () => {
    const w = await deleteWith(new CanceledError())
    expect(errorText(w).exists()).toBe(false)
  })
})
