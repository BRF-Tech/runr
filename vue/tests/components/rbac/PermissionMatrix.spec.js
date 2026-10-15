import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import PermissionMatrix from '@/components/rbac/PermissionMatrix.vue'
import { withPlugins } from '../../helpers'

// Roles page permission matrix (docs/permissions.md)

const catalog = [
  { key: 'projects.view',   group: 'projects',       scope: 'project', implies: [] },
  { key: 'projects.deploy', group: 'projects',       scope: 'project', implies: ['projects.view'] },
  { key: 'env.view',        group: 'configuration',  scope: 'project', implies: ['projects.view'] },
  { key: 'env.edit',        group: 'configuration',  scope: 'project', implies: ['env.view'] },
  { key: 'regions.manage',  group: 'infrastructure', scope: 'org',     implies: [] },
]

function factory(props) {
  return mount(PermissionMatrix, { props: { catalog, ...props }, ...withPlugins() })
}

const box = (wrapper, key) => wrapper.find(`[data-permission="${key}"] input`)

describe('PermissionMatrix', () => {
  it('renders one column per permission group with translated labels', () => {
    const wrapper = factory({ modelValue: [] })
    expect(wrapper.text()).toContain('Projects')
    expect(wrapper.text()).toContain('Configuration')
    expect(wrapper.text()).toContain('Edit environment variables')
    expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(catalog.length)
  })

  it('checks and locks implied permissions', () => {
    const wrapper = factory({ modelValue: ['env.edit'] })
    expect(box(wrapper, 'env.edit').element.checked).toBe(true)
    expect(box(wrapper, 'env.view').element.checked).toBe(true)
    expect(box(wrapper, 'env.view').element.disabled).toBe(true)
    expect(box(wrapper, 'projects.view').element.checked).toBe(true)
    expect(box(wrapper, 'regions.manage').element.checked).toBe(false)
  })

  it('emits the explicit selection when a permission is toggled', async () => {
    const wrapper = factory({ modelValue: ['projects.view'] })
    await box(wrapper, 'projects.deploy').trigger('change')
    expect(wrapper.emitted('update:modelValue').at(-1)[0]).toEqual(['projects.view', 'projects.deploy'])

    await wrapper.setProps({ modelValue: ['projects.view', 'projects.deploy'] })
    await box(wrapper, 'projects.view').trigger('change')
    expect(wrapper.emitted('update:modelValue').at(-1)[0]).toEqual(['projects.deploy'])
  })

  it('is read-only for built-in roles', async () => {
    const wrapper = factory({ modelValue: ['projects.view'], readonly: true })
    expect(wrapper.findAll('input').every(i => i.element.disabled)).toBe(true)
    await box(wrapper, 'regions.manage').trigger('change')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('disables permissions the editor cannot hand out', () => {
    const wrapper = factory({ modelValue: [], allowed: ['projects.view', 'projects.deploy'] })
    expect(box(wrapper, 'regions.manage').element.disabled).toBe(true)
    expect(box(wrapper, 'projects.deploy').element.disabled).toBe(false)
  })
})
