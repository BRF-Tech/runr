import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { runtimePatchesApi } from '@/api/runtimePatches'
import { usePermissionsStore } from '@/stores/permissions'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import RuntimePatchBadge from '@/components/project/RuntimePatchBadge.vue'
import RuntimePatchDialog from '@/components/project/RuntimePatchDialog.vue'
import ProjectCard from '@/components/ProjectCard.vue'
import { withPlugins } from '../../helpers'

vi.mock('vue-router', async (orig) => ({ ...(await orig()), useRouter: () => ({ push: vi.fn() }), useRoute: () => ({ query: {} }) }))

const find = (w, id) => w.find(`[data-test="${id}"]`)
const flush = async () => { for (let i = 0; i < 4; i++) await new Promise(r => setTimeout(r, 0)) }
const ok = (data) => Promise.resolve({ data })
const fail = (status, data) => Promise.reject(Object.assign(new Error('x'), { response: { status, data } }))

const R1 = {
  id: 'php-8.3-8.3.36-r1', runtime: 'php', version: '8.3', tag: '8.3.36-r1', digest: 'sha256:' + 'a'.repeat(64), level: 'routine',
  reason: 'Rebuilt on the new base image.', reasons: { en: 'Rebuilt on the new base image.', tr: 'Yeni taban imajı üzerine yeniden derlendi.' },
  cves: [], url: null, upstream: 'PHP 8.3.36', published_at: '2026-10-01T08:00:00Z', deadline_at: null, deadline_extended: false, in_feed: true,
}
const R2 = {
  ...R1, id: 'php-8.3-8.3.36-r2', tag: '8.3.36-r2', digest: 'sha256:' + 'b'.repeat(64), level: 'zero-day',
  reason: 'Remote code execution in the FPM worker.', reasons: { en: 'Remote code execution in the FPM worker.', tr: 'FPM işçisinde uzaktan kod çalıştırma.' },
  cves: ['CVE-2026-1111', 'CVE-2026-2222'], url: 'https://example.test/advisory', published_at: '2026-10-20T08:00:00Z', deadline_at: '2026-10-23T08:00:00Z',
}
const sum = (p) => ({ id: p.id, tag: p.tag, level: p.level, digest: p.digest, published_at: p.published_at })
const state = (over = {}) => ({
  state: 'patch_available', level: 'zero-day', deadline_at: R2.deadline_at, deployed: true,
  current: sum(R1), latest: sum(R2), pending: [R2.id], pin: null, ...over,
})
const payload = (over = {}) => ({ data: { state: state(), patches: [R2, R1], keep: 5, auto_migrate: true, migrations: [], ...over } })
const project = (over = {}) => ({ id: 'p1', name: 'shop', slug: 'shop', service_type: 'faas', runtime: 'php', runtime_version: '8.3', config: {}, runtime_patch: state(), ...over })

function grant(permissions, projects = {}) {
  usePermissionsStore().apply({ permissions, platform_admin: false, edition: 'single', role: null, roles: [], projects })
}

describe('RuntimePatchBadge', () => {
  it('renders the state of the project and opens the dialog', async () => {
    const w = mount(RuntimePatchBadge, { props: { state: state({ level: 'security' }) }, global: withPlugins().global })
    expect(find(w, 'runtime-patch-badge-available').text()).toBe('Security patch')
    await find(w, 'runtime-patch-badge-available').trigger('click')
    expect(w.emitted('open')).toHaveLength(1)
  })

  it('overdue and pinned states, nothing when up to date', () => {
    const plugins = withPlugins().global
    expect(find(mount(RuntimePatchBadge, { props: { state: state({ state: 'overdue' }) }, global: plugins }), 'runtime-patch-badge-overdue').text()).toBe('Patch overdue')
    const pinned = mount(RuntimePatchBadge, { props: { state: state({ state: 'pinned', pin: { id: R1.id, tag: R1.tag, available: true } }) }, global: plugins })
    expect(find(pinned, 'runtime-patch-badge-pinned').text()).toBe('Pinned 8.3.36-r1')
    const gone = mount(RuntimePatchBadge, { props: { state: state({ state: 'pinned', pin: { id: R1.id, tag: R1.tag, available: false } }) }, global: plugins })
    expect(find(gone, 'runtime-patch-badge-pinned').text()).toContain('no longer available')
    expect(mount(RuntimePatchBadge, { props: { state: state({ state: 'up_to_date', level: null }) }, global: plugins }).html()).not.toContain('runtime-patch-badge')
    expect(mount(RuntimePatchBadge, { props: { state: null }, global: plugins }).html()).not.toContain('runtime-patch-badge')
  })

  it('the project card shows it next to the EOL badges and asks for the dialog', async () => {
    const w = mount(ProjectCard, { props: { project: project() }, global: withPlugins().global })
    await find(w, 'runtime-patch-badge-available').trigger('click')
    expect(w.emitted('patches')[0][0]).toMatchObject({ id: 'p1' })
  })
})

describe('RuntimePatchDialog', () => {
  afterEach(() => vi.restoreAllMocks())

  it('shows why each patch came: level, reason in the locale, CVEs, link, deadline', async () => {
    grant(['projects.view', 'projects.deploy'])
    vi.spyOn(runtimePatchesApi, 'project').mockReturnValue(ok(payload()))
    const w = mount(RuntimePatchDialog, { props: { project: project() }, global: withPlugins('tr').global })
    await flush()

    expect(runtimePatchesApi.project).toHaveBeenCalledWith('p1')
    expect(find(w, 'runtime-patch-current').text()).toBe('8.3.36-r1')
    expect(find(w, 'runtime-patch-latest').text()).toBe('8.3.36-r2')
    const row = find(w, 'runtime-patch-row-8.3.36-r2')
    expect(row.find('[data-test="runtime-patch-level"]').text()).toBe('Sıfırıncı gün')
    expect(row.find('[data-test="runtime-patch-reason"]').text()).toBe('FPM işçisinde uzaktan kod çalıştırma.')
    const cves = row.findAll('[data-test="runtime-patch-cves"] a')
    expect(cves.map(a => a.text())).toEqual(['CVE-2026-1111', 'CVE-2026-2222'])
    expect(cves[0].attributes('href')).toBe('https://www.cve.org/CVERecord?id=CVE-2026-1111')
    expect(row.html()).toContain('https://example.test/advisory')
    expect(find(w, 'runtime-patch-deadline').exists()).toBe(true)
  })

  it('applies the newest patch after a confirmation', async () => {
    useToast().toasts.value = []
    grant(['projects.view', 'projects.deploy'])
    vi.spyOn(runtimePatchesApi, 'project').mockReturnValue(ok(payload()))
    const apply = vi.spyOn(runtimePatchesApi, 'apply').mockReturnValue(ok({ data: { id: 'm1', status: 'started' }, message: 'Redeploying' }))
    const w = mount(RuntimePatchDialog, { props: { project: project() }, global: withPlugins().global })
    await flush()

    const done = w.vm.apply()
    await flush()
    expect(useConfirm().current.value.title).toBe('Apply 8.3.36-r2?')
    useConfirm().accept()
    await done
    await flush()

    expect(apply).toHaveBeenCalledWith('p1')
    expect(useToast().toasts.value.at(-1)).toMatchObject({ type: 'success', message: 'Redeploying onto the newest patch.' })
    expect(w.emitted('changed')).toHaveLength(1)
  })

  it('does not apply when the confirmation is cancelled; shows the refusal of the API', async () => {
    useToast().toasts.value = []
    grant(['projects.view', 'projects.deploy'])
    vi.spyOn(runtimePatchesApi, 'project').mockReturnValue(ok(payload()))
    const apply = vi.spyOn(runtimePatchesApi, 'apply').mockImplementation(() => fail(409, { message: 'A patch migration of the project is still running.', code: 'migration_running' }))
    const w = mount(RuntimePatchDialog, { props: { project: project() }, global: withPlugins().global })
    await flush()

    let done = w.vm.apply()
    await flush()
    useConfirm().cancel()
    await done
    expect(apply).not.toHaveBeenCalled()

    done = w.vm.apply()
    await flush()
    useConfirm().accept()
    await done
    await flush()
    expect(useToast().toasts.value.at(-1)).toMatchObject({ type: 'error', message: 'A patch migration of the project is still running.' })
  })

  it('pins to a patch and unpins', async () => {
    grant(['projects.view', 'projects.deploy'])
    vi.spyOn(runtimePatchesApi, 'project').mockReturnValue(ok(payload({ state: state({ state: 'pinned', pin: { id: R1.id, tag: R1.tag, available: true, by: 'u1' } }) })))
    const pin = vi.spyOn(runtimePatchesApi, 'pin').mockReturnValue(ok({ data: state() }))
    const unpin = vi.spyOn(runtimePatchesApi, 'unpin').mockReturnValue(ok({ data: state() }))
    const w = mount(RuntimePatchDialog, { props: { project: project() }, global: withPlugins().global })
    await flush()

    // Pinned to r1: no pin button on its own row, one on the other
    expect(find(w, 'runtime-patch-pin-8.3.36-r1').exists()).toBe(false)
    expect(find(w, 'runtime-patch-pin-8.3.36-r2').exists()).toBe(true)
    expect(find(w, 'runtime-patch-pin').text()).toContain('Pinned 8.3.36-r1')

    const done = w.vm.pin(R2)
    await flush()
    useConfirm().accept()
    await done
    expect(pin).toHaveBeenCalledWith('p1', R2.id, true)

    await find(w, 'runtime-patch-unpin').trigger('click')
    await flush()
    expect(unpin).toHaveBeenCalledWith('p1')
  })

  it('a pin RunR set after a failed migration says so; a pin that left the feed warns', async () => {
    grant(['projects.view', 'projects.deploy'])
    vi.spyOn(runtimePatchesApi, 'project').mockReturnValue(ok(payload({ state: state({ state: 'overdue', pin: { id: 'php-8.3-old', tag: '8.3.30-r1', available: false, by: 'runr' } }) })))
    const w = mount(RuntimePatchDialog, { props: { project: project() }, global: withPlugins().global })
    await flush()

    const pin = find(w, 'runtime-patch-pin').text()
    expect(pin).toContain('no longer one of the newest')
    expect(pin).toContain('Pinned by RunR')
    expect(find(w, 'runtime-patch-state-label').text()).toBe('Overdue')
  })

  it('without projects.deploy nothing can be applied or pinned', async () => {
    // the permissions of the pinia the dialog mounts with
    const plugins = withPlugins()
    grant(['projects.view'])
    vi.spyOn(runtimePatchesApi, 'project').mockReturnValue(ok(payload()))
    const w = mount(RuntimePatchDialog, { props: { project: project() }, global: plugins.global })
    await flush()

    expect(find(w, 'runtime-patch-apply').exists()).toBe(false)
    expect(find(w, 'runtime-patch-pin-8.3.36-r1').exists()).toBe(false)
  })

  it('lists the recent migrations', async () => {
    grant(['projects.view', 'projects.deploy'])
    vi.spyOn(runtimePatchesApi, 'project').mockReturnValue(ok(payload({
      migrations: [{ id: 'm1', patch_id: R2.id, trigger: 'auto', status: 'rolled_back', message: 'The new revision did not become ready.', created_at: '2026-10-23T08:05:00Z' }],
    })))
    const w = mount(RuntimePatchDialog, { props: { project: project() }, global: withPlugins().global })
    await flush()

    const list = find(w, 'runtime-patch-migrations').text()
    expect(list).toContain('Automatic (deadline)')
    expect(list).toContain('8.3.36-r2')
    expect(list).toContain('Rolled back')
  })
})
