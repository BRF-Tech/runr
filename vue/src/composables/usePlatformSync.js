import { useJobEvents } from './useJobEvents'
import { useSettingsStore } from '@/stores/settings'
import { projectsApi } from '@/api/projects'

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

/**
 * Platform event'lerini local state'e yansıtır.
 *
 * ProjectsView: usePlatformSync({ projects })
 * ProjectDetailView: usePlatformSync({ project, versions, deployments })
 */
// Broadcasts never carry environment variables (env_vars_hidden): keep the ones already loaded
function keepEnv(prev, next) {
  if (!next?.config?.env_vars_hidden || !prev?.config || prev.config.env_vars_hidden) return next
  return { ...next, config: { ...next.config, env_vars: prev.config.env_vars, env_vars_hidden: false } }
}

export function usePlatformSync({ projects, project, versions, deployments, refetch } = {}) {
  const settingsStore = useSettingsStore()
  const handlers = {}

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
      if (exists) return
      const { project: merged, stale } = mergeRedactedProject(payload.project, null)
      projects.value.unshift(merged)
      if (stale && refetch) refetch()
    }

    handlers['project.updated'] = (payload) => {
      if (!payload.project) return
      const idx = projects.value.findIndex(p => p.id === payload.project.id)
      if (idx === -1) return
      // env_vars_hidden (RBAC) and masked values (env_vars_redacted): keep what is loaded
      const { project: merged, stale } = mergeRedactedProject(keepEnv(projects.value[idx], payload.project), projects.value[idx])
      projects.value[idx] = merged
      if (stale && refetch) refetch()
    }

    handlers['project.deleted'] = (payload) => {
      if (!payload.project_id) return
      projects.value = projects.value.filter(p => p.id !== payload.project_id)
    }
  }

  // ── Single project (ProjectDetailView) ──────────────────────────────────
  if (project) {
    handlers['project.updated'] = async (payload) => {
      if (!payload.project || !project.value) return
      if (payload.project.id !== project.value.id) return
      const { project: merged, stale } = mergeRedactedProject(keepEnv(project.value, payload.project), project.value)
      project.value = merged
      if (stale) {
        try {
          const { data } = await projectsApi.get(merged.id)
          if (project.value?.id === merged.id) project.value = data.data ?? data
        } catch { /* keep the merged copy */ }
      }
    }

    handlers['project.deleted'] = (payload) => {
      if (project.value && payload.project_id === project.value.id) {
        project.value = null
      }
    }
  }

  // ── Versions ────────────────────────────────────────────────────────────
  if (versions && project) {
    handlers['version.created'] = (payload) => {
      if (!project.value || payload.project_id !== project.value.id) return
      if (!payload.version) return
      const exists = versions.value.some(v => v.id === payload.version.id)
      if (!exists) versions.value.unshift(payload.version)
    }
  }

  // ── Deployments ─────────────────────────────────────────────────────────
  if (deployments && project) {
    handlers['deployment.created'] = (payload) => {
      if (!project.value || payload.project_id !== project.value.id) return
      if (!payload.deployment) return
      const exists = deployments.value.some(d => d.id === payload.deployment.id)
      if (!exists) deployments.value.unshift(payload.deployment)
    }

    handlers['deployment.deleted'] = (payload) => {
      if (!project.value || payload.project_id !== project.value.id) return
      if (payload.deployment_id) {
        deployments.value = deployments.value.filter(d => d.id !== payload.deployment_id)
      }
    }

    handlers['deployment.*'] = (payload) => {
      if (!project.value || payload.project_id !== project.value.id) return
      if (payload.deployment_id && payload.status) {
        const dep = deployments.value.find(d => d.id === payload.deployment_id)
        if (dep) dep.status = payload.status
      }
    }
  }

  // ── Build completion ────────────────────────────────────────────────────
  if (versions && project) {
    handlers['build.completed'] = (payload) => {
      if (!project.value || payload.project_id !== project.value.id) return
      const ver = versions.value.find(v => v.number === payload.version)
      if (ver) ver.build_status = 'ready'
    }

    handlers['build.failed'] = (payload) => {
      if (!project.value || payload.project_id !== project.value.id) return
      const ver = versions.value.find(v => v.number === payload.version)
      if (ver) {
        ver.build_status = 'failed'
        ver.build_error = payload.error || null
      }
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
