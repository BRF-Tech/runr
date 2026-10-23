import { useJobEvents } from './useJobEvents'
import { useSettingsStore } from '@/stores/settings'
import { projectsApi } from '@/api/projects'
import { applyRolloutUpdate } from '@/utils/deployStrategy'

// Broadcasts are public: env var values arrive masked (config.env_vars_redacted, docs/security.md).
// Keep the values already loaded when the names match; otherwise the caller reloads the project.
export function mergeRedactedProject(incoming, local) {
  if (!incoming?.config?.env_vars_redacted) return { project: incoming, stale: false }
  const localEnv = local?.config?.env_vars ?? {}
  const names = (o) => JSON.stringify(Object.keys(o ?? {}).sort())
  const same = names(localEnv) === names(incoming.config.env_vars)
  const config = { ...incoming.config, env_vars: same ? localEnv : (local?.config?.env_vars ?? {}) }
  delete config.env_vars_redacted
  return { project: { ...incoming, config }, stale: !same }
}
import { usePermissionsStore } from '@/stores/permissions'
import { insertChange, removeChange, updateChange } from '@/utils/changeJournal'

/**
 * Platform event'lerini local state'e yansıtır.
 *
 * ProjectsView: usePlatformSync({ projects })
 * ProjectDetailView: usePlatformSync({ project, versions, deployments, rollout, environment })
 *   environment: the environment the deployments list shows (a ref; null = production): a new
 *   deployment of another environment is not added to it, a rollout of another one is not shown
 *   (docs/environments.md)
 *
 * journals ({ projects, versions, deployments }, utils/changeJournal): every change an event
 * makes to a list is also recorded there, so a reload of the list that was in flight when the
 * event arrived applies it to its answer instead of dropping it.
 */
// Broadcasts never carry environment variables (env_vars_hidden): keep the ones already loaded
function keepEnv(prev, next) {
  if (!next?.config?.env_vars_hidden || !prev?.config || prev.config.env_vars_hidden) return next
  return { ...next, config: { ...next.config, env_vars: prev.config.env_vars, env_vars_hidden: false } }
}

// Status topics with their own handler below; the deployment.* fallback leaves them alone
const OWN_STATUS_TOPICS = ['deployment.status', 'deployment.rollout']

export function usePlatformSync({ projects, project, versions, deployments, rollout, refetch, journals = {}, onProjectDeleted = null, environment } = {}) {
  const settingsStore = useSettingsStore()
  const handlers = {}

  /** Applies a change (list → list) to a list and records it in the list's journal. */
  function change(list, journal, apply) {
    list.value = apply(list.value)
    journal?.record(apply)
  }

  // ── Projects list (ProjectsView) ────────────────────────────────────────
  if (projects && !project) {
    handlers['project.created'] = (payload) => {
      if (!payload.project) return
      // Members with project access only see their projects (theirs, or granted on reload)
      const me = JSON.parse(localStorage.getItem('user') || '{}')?.id
      const mine = String(payload.project.created_by?.id) === String(me)
      if (!usePermissionsStore().can('projects.view') && !mine) return
      if (mine) usePermissionsStore().load() // creators get the Admin role on their project
      const exists = projects.value.some(p => p.id === payload.project.id)
      const { project: merged, stale } = mergeRedactedProject(payload.project, null)
      change(projects, journals.projects, insertChange(merged))
      if (!exists && stale && refetch) refetch()
    }

    handlers['project.updated'] = (payload) => {
      if (!payload.project) return
      const known = projects.value.find(p => p.id === payload.project.id)
      // env_vars_hidden (RBAC) and masked values (env_vars_redacted): keep what is loaded
      const update = (list) => list.map(p => (p.id === payload.project.id
        ? mergeRedactedProject(keepEnv(p, payload.project), p).project
        : p))
      change(projects, journals.projects, update)
      if (known && mergeRedactedProject(keepEnv(known, payload.project), known).stale && refetch) refetch()
    }

    handlers['project.deleted'] = (payload) => {
      if (!payload.project_id) return
      change(projects, journals.projects, removeChange(payload.project_id))
    }
  }

  // ── Single project (ProjectDetailView) ──────────────────────────────────
  if (project) {
    // The reload of a masked update must not overwrite a newer update that arrived meanwhile
    let updates = 0
    handlers['project.updated'] = async (payload) => {
      if (!payload.project || !project.value) return
      if (payload.project.id !== project.value.id) return
      const seq = ++updates
      const { project: merged, stale } = mergeRedactedProject(keepEnv(project.value, payload.project), project.value)
      project.value = merged
      if (stale) {
        try {
          const { data } = await projectsApi.get(merged.id)
          if (seq === updates && project.value?.id === merged.id) project.value = data.data ?? data
        } catch { /* keep the merged copy */ }
      }
    }

    // The page decides (onProjectDeleted): it leaves for the projects list. Without one the
    // project is dropped.
    handlers['project.deleted'] = (payload) => {
      if (project.value && payload.project_id === project.value.id) {
        if (onProjectDeleted) onProjectDeleted(payload)
        else project.value = null
      }
    }
  }

  // ── Versions ────────────────────────────────────────────────────────────
  if (versions && project) {
    handlers['version.created'] = (payload) => {
      if (!project.value || payload.project_id !== project.value.id) return
      if (!payload.version) return
      change(versions, journals.versions, insertChange(payload.version))
    }
  }

  // ── Deployments ─────────────────────────────────────────────────────────
  if (deployments && project) {
    handlers['deployment.created'] = (payload) => {
      if (!project.value || payload.project_id !== project.value.id) return
      if (!payload.deployment) return
      if (environment && (payload.deployment.environment ?? 'production') !== (environment.value ?? 'production')) return
      change(deployments, journals.deployments, insertChange(payload.deployment))
    }

    handlers['deployment.deleted'] = (payload) => {
      if (!project.value || payload.project_id !== project.value.id) return
      if (payload.deployment_id) {
        change(deployments, journals.deployments, removeChange(payload.deployment_id))
      }
    }

    // A deployment's status, e.g. the deployment a rollout serves next to (stable / previous). A
    // status that arrives while the deployments list is reloading (e.g. right after a deploy) is
    // applied to that answer too (journals.deployments)
    handlers['deployment.status'] = (payload) => {
      if (!project.value || payload.project_id !== project.value.id) return
      if (payload.deployment_id && payload.status) {
        change(deployments, journals.deployments, updateChange(payload.deployment_id, { status: payload.status }))
      }
    }

    // Every change of a blue-green / canary rollout: the new deployment carries the rollout
    // (traffic, step, health, metrics) and its status; the roles follow the newest rollout — of
    // the environment shown (each environment rolls out on its own, docs/deploy-strategies.md)
    handlers['deployment.rollout'] = (payload) => {
      if (!project.value || payload.project_id !== project.value.id) return
      if (environment && (payload.rollout?.environment ?? 'production') !== (environment.value ?? 'production')) return
      applyRolloutUpdate({ rollout, deployments }, payload.rollout, payload.status ?? null)
    }

    handlers['deployment.*'] = (payload, topic) => {
      if (OWN_STATUS_TOPICS.includes(topic)) return
      handlers['deployment.status'](payload)
    }
  }

  // ── Build completion ────────────────────────────────────────────────────
  if (versions && project) {
    const buildChange = (number, fields) => updateChange(v => v.number === number, fields)

    handlers['build.completed'] = (payload) => {
      if (!project.value || payload.project_id !== project.value.id) return
      change(versions, journals.versions, buildChange(payload.version, { build_status: 'ready' }))
    }

    handlers['build.failed'] = (payload) => {
      if (!project.value || payload.project_id !== project.value.id) return
      change(versions, journals.versions, buildChange(payload.version, { build_status: 'failed', build_error: payload.error || null }))
    }
  }

  // ── Demo removed/seeded → full refetch (projects + regions change) ────
  if (refetch) {
    handlers['demo.removed'] = () => refetch()
    handlers['demo.seeded'] = () => refetch()
  }

  // ── Settings ────────────────────────────────────────────────────────────
  handlers['settings.updated'] = (payload) => {
    if (payload.settings) settingsStore.update(payload.settings)
  }

  useJobEvents(handlers)
}
