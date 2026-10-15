import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import TriggerConfig from '@/components/project/TriggerConfig.vue'
import en from '@/locales/en'

function i18n() {
  return createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: {
      en: {
        ...en,
        create: {
          ...en.create,
          triggerDisabled: { http: 'Serving required', queue: 'Redis required' },
        },
      },
    },
  })
}

const mountConfig = (props) => mount(TriggerConfig, { props: { triggerType: 'http', ...props }, global: { plugins: [i18n()] } })

describe('TriggerConfig — workflow trigger', () => {
  it('offers the workflow trigger and emits it', async () => {
    const w = mountConfig({ region: { is_synced: false, has_serving: true, has_redis: true } })
    const button = w.findAll('button').find((b) => b.text() === 'Workflow')
    expect(button).toBeTruthy()

    await button.trigger('click')
    expect(w.emitted('update:triggerType')).toEqual([['workflow']])
  })

  it('shows the optional queue stream for workflow projects', async () => {
    const w = mountConfig({ triggerType: 'workflow', queueStream: '', region: { is_synced: false, has_serving: true, has_redis: true } })
    const section = w.find('[data-test="workflow-trigger"]')
    expect(section.exists()).toBe(true)
    expect(section.text()).toContain('Queue stream (optional)')

    await section.find('input').setValue('orders')
    expect(w.emitted('update:queueStream')).toEqual([['orders']])
  })

  it('warns when a queue stream is set on a region without Redis', () => {
    const w = mountConfig({ triggerType: 'workflow', queueStream: 'orders', region: { is_synced: false, has_serving: true, has_redis: false } })
    expect(w.find('[data-test="workflow-trigger"]').text()).toContain('Redis required')
  })

  it('is disabled like http when the region has no serving', () => {
    const w = mountConfig({ region: { is_synced: false, has_serving: false, is_demo: false } })
    const button = w.findAll('button').find((b) => b.text() === 'Workflow')
    expect(button.attributes('disabled')).toBeDefined()
  })
})
