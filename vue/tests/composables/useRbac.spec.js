import { describe, it, expect } from 'vitest'
import { expandPermissions, minimalPermissions } from '@/composables/useRbac'
import en from '@/locales/en/rbac'
import tr from '@/locales/tr/rbac'

// Permission helpers of the Roles page — the same rules as App\Support\Rbac\PermissionCatalog

const catalog = [
  { key: 'projects.view', implies: [] },
  { key: 'env.view',      implies: ['projects.view'] },
  { key: 'env.edit',      implies: ['env.view'] },
  { key: 'logs.view',     implies: ['projects.view'] },
]

describe('permission helpers', () => {
  it('expands implied permissions transitively, in catalog order', () => {
    expect(expandPermissions(['env.edit'], catalog)).toEqual(['projects.view', 'env.view', 'env.edit'])
    expect(expandPermissions(['unknown'], catalog)).toEqual([])
  })

  it('reduces a stored role to the permissions picked explicitly', () => {
    expect(minimalPermissions(['projects.view', 'env.view', 'env.edit', 'logs.view'], catalog)).toEqual(['env.edit', 'logs.view'])
  })
})

describe('rbac locales', () => {
  it('have the same keys in English and Turkish', () => {
    const keys = (o, p = '') => Object.entries(o).flatMap(([k, v]) => (typeof v === 'object' ? keys(v, `${p}${k}.`) : [`${p}${k}`]))
    expect(keys(tr).sort()).toEqual(keys(en).sort())
  })
})
