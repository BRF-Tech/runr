import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import RoleSelect from '@/components/rbac/RoleSelect.vue'
import { withPlugins } from '../../helpers'

// Role picker of the users, groups, project access and SSO screens (docs/permissions.md)

const roles = [
  { key: 'owner',     name: 'Owner',     builtin: true,  grantable: false },
  { key: 'admin',     name: 'Admin',     builtin: true,  grantable: true },
  { key: 'developer', name: 'Developer', builtin: true,  grantable: true },
  { key: 'qa',        name: 'QA',        builtin: false, grantable: false },
]

function factory(props, locale = 'en') {
  return mount(RoleSelect, { props: { roles, ...props }, ...withPlugins(locale) })
}

describe('RoleSelect', () => {
  it('leaves Owner out unless asked for', () => {
    expect(factory({ modelValue: 'admin' }).findAll('option').map(o => o.element.value)).toEqual(['admin', 'developer', 'qa'])
    expect(factory({ modelValue: 'admin', includeOwner: true }).findAll('option')).toHaveLength(4)
  })

  it('translates built-in roles and marks custom ones', () => {
    const labels = factory({ modelValue: 'admin' }, 'tr').findAll('option').map(o => o.text())
    expect(labels).toContain('Geliştirici')
    expect(labels.some(l => l.startsWith('QA') && l.includes('Özel'))).toBe(true)
  })

  it('disables roles the user cannot grant, except the current one', () => {
    const current = factory({ modelValue: 'qa' }).findAll('option')
    expect(current.find(o => o.element.value === 'qa').element.disabled).toBe(false)
    const other = factory({ modelValue: 'admin' }).findAll('option')
    expect(other.find(o => o.element.value === 'qa').element.disabled).toBe(true)
  })

  it('offers an empty choice with its own label', () => {
    const wrapper = factory({ modelValue: null, allowEmpty: true, emptyLabel: 'Organization default' })
    expect(wrapper.find('option').text()).toBe('Organization default')
  })

  it('emits the chosen key', async () => {
    const wrapper = factory({ modelValue: 'admin' })
    await wrapper.find('select').setValue('developer')
    expect(wrapper.emitted('update:modelValue')[0]).toEqual(['developer'])
  })
})
