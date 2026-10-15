import { ref, reactive, computed, watch, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { gitApi } from '@/api/git'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import { envVarsToString, parseEnvVars } from '@/composables/useProjectForm'
import { envVarsError } from '@/composables/projectValidation'

/** Injection key of the Git tab state (ProjectGitTab provides it to its sections) */
export const PROJECT_GIT = Symbol('projectGit')

/** Leaving the console for a provider page (GitHub installation, GitLab sign-in); replaceable in tests */
export const gitNavigation = {
  assign(url) {
    window.location.assign(url)
  },
}

const IN_FLIGHT_DEPLOYS  = ['queued', 'importing', 'building', 'deploying']
const IN_FLIGHT_PREVIEWS = ['pending', 'building', 'deploying', 'deleting']
const POLL_MS = 8000

/**
 * State and actions of a project's Git tab: the RunR remote, the connected provider repository,
 * deploy and preview settings, previews and the deploy history (docs/git-deploy.md).
 */
export function useProjectGit(projectId) {
  const { t, te }   = useI18n()
  const route       = useRoute()
  const router      = useRouter()
  const toast       = useToast()
  const { confirm } = useConfirm()

  const info      = ref(null)
  const previews  = ref([])
  const deploys   = ref([])
  const loading   = ref(true)
  const loadError = ref(null)
  const saving    = ref(false)
  const saveError = ref(null)
  const busy      = ref({})       // key → true while an action runs (redeploy, preview:<id>, disconnect)

  const form = reactive({
    deploy_branch: 'main', root_dir: '', auto_deploy: true, allow_force_push: false,
    previews_enabled: false, preview_limit: 3, preview_ttl_hours: 72,
    preview_env: '', fork_previews: false, fork_secrets: false,
  })

  // Connect a repository
  const connect = reactive({
    providerId: '', installationId: '', installations: [], accessToken: '', tokenRef: '',
    search: '', repos: [], reposLoaded: false, loadingRepos: false, repository: '', connecting: false, error: null,
  })

  const abilities  = computed(() => info.value?.abilities ?? {})
  const providers  = computed(() => info.value?.providers ?? [])
  const connection = computed(() => info.value?.connection ?? null)
  const provider   = computed(() => providers.value.find(p => p.id === connect.providerId) ?? null)
  const previewEnvError = computed(() => envVarsError(form.preview_env))
  const dirty = computed(() => {
    const s = info.value?.settings
    if (!s) return false
    return form.deploy_branch !== s.deploy_branch
      || (form.root_dir || '') !== (s.root_dir || '')
      || form.auto_deploy !== s.auto_deploy
      || form.allow_force_push !== s.allow_force_push
      || form.previews_enabled !== s.previews_enabled
      || Number(form.preview_limit) !== s.preview_limit
      || Number(form.preview_ttl_hours) !== s.preview_ttl_hours
      || form.fork_previews !== s.fork_previews
      || form.fork_secrets !== s.fork_secrets
      || form.preview_env.trim() !== envVarsToString(s.preview_env).trim()
  })

  function applyInfo(data) {
    info.value = data
    const s = data?.settings ?? {}
    Object.assign(form, {
      deploy_branch:     s.deploy_branch ?? 'main',
      root_dir:          s.root_dir ?? '',
      auto_deploy:       !!s.auto_deploy,
      allow_force_push:  !!s.allow_force_push,
      previews_enabled:  !!s.previews_enabled,
      preview_limit:     s.preview_limit ?? 3,
      preview_ttl_hours: s.preview_ttl_hours ?? 72,
      preview_env:       envVarsToString(s.preview_env),
      fork_previews:     !!s.fork_previews,
      fork_secrets:      !!s.fork_secrets,
    })
    if (!connect.providerId && providers.value.length) connect.providerId = providers.value[0].id
  }

  async function load({ silent = false } = {}) {
    if (!silent) loading.value = true
    loadError.value = null
    try {
      const [g, p, d] = await Promise.all([
        gitApi.get(projectId()),
        gitApi.previews(projectId()),
        gitApi.deploys(projectId()),
      ])
      applyInfo(g.data.data ?? g.data)
      previews.value = p.data.data ?? []
      deploys.value  = d.data.data ?? []
    } catch (e) {
      if (!silent) loadError.value = e.response?.data?.message ?? t('gitDeploy.loadFailed')
    } finally {
      loading.value = false
    }
  }

  async function save() {
    if (previewEnvError.value) return
    saving.value = true
    saveError.value = null
    try {
      const payload = {
        deploy_branch:    form.deploy_branch.trim(),
        root_dir:         form.root_dir.trim() || null,
        auto_deploy:      form.auto_deploy,
        allow_force_push: form.allow_force_push,
      }
      if (abilities.value.manage_previews) {
        Object.assign(payload, {
          previews_enabled:  form.previews_enabled,
          preview_limit:     Number(form.preview_limit),
          preview_ttl_hours: Number(form.preview_ttl_hours),
          preview_env:       parseEnvVars(form.preview_env) ?? null,
          fork_previews:     form.fork_previews,
          fork_secrets:      form.fork_previews && form.fork_secrets,
        })
      }
      const { data } = await gitApi.update(projectId(), payload)
      applyInfo(data.data ?? data)
      toast.success(t('gitDeploy.settings.saved'))
    } catch (e) {
      const errors = e.response?.data?.errors
      saveError.value = errors ? Object.values(errors).flat()[0] : (e.response?.data?.message ?? t('gitDeploy.settings.saveFailed'))
    } finally {
      saving.value = false
    }
  }

  async function copy(text) {
    try {
      await navigator.clipboard.writeText(text)
      toast.success(t('gitDeploy.copied'))
    } catch {
      toast.error(t('gitDeploy.copyFailed'))
    }
  }

  async function redeploy() {
    busy.value = { ...busy.value, redeploy: true }
    try {
      await gitApi.redeploy(projectId())
      toast.success(t('gitDeploy.remote.redeployStarted'))
      await load({ silent: true })
    } catch (e) {
      toast.error(firstError(e) ?? t('gitDeploy.remote.redeployFailed'))
    } finally {
      busy.value = { ...busy.value, redeploy: false }
    }
  }

  // ── Connect ────────────────────────────────────────────────────────────────
  function resetRepos() {
    connect.repos = []
    connect.reposLoaded = false
    connect.repository = ''
    connect.error = null
  }

  async function loadInstallations() {
    if (provider.value?.type !== 'github') return
    try {
      const { data } = await gitApi.installations(projectId(), connect.providerId)
      connect.installations = data.data ?? []
      if (!connect.installationId && connect.installations.length) {
        connect.installationId = connect.installations[0].installation_id
      }
    } catch {
      connect.installations = []
    }
  }

  async function installGithubApp() {
    try {
      const { data } = await gitApi.githubInstallUrl(projectId(), connect.providerId)
      gitNavigation.assign(data.url)
    } catch (e) {
      connect.error = firstError(e) ?? t('gitDeploy.connect.failed')
    }
  }

  async function signInGitlab() {
    try {
      const { data } = await gitApi.gitlabAuthorizeUrl(projectId(), connect.providerId)
      gitNavigation.assign(data.url)
    } catch (e) {
      connect.error = firstError(e) ?? t('gitDeploy.connect.failed')
    }
  }

  function credentials() {
    const type = provider.value?.type
    if (type === 'github') return { installation_id: connect.installationId || null }
    if (connect.tokenRef) return { token_ref: connect.tokenRef }
    return { access_token: connect.accessToken.trim() || null }
  }

  async function loadRepos() {
    connect.loadingRepos = true
    connect.error = null
    try {
      const { data } = await gitApi.repositories(projectId(), {
        provider_id: connect.providerId,
        search: connect.search.trim() || null,
        ...credentials(),
      })
      connect.repos = data.data ?? []
      connect.reposLoaded = true
      if (connect.repos.length === 1) connect.repository = connect.repos[0].full_name
    } catch (e) {
      connect.error = firstError(e) ?? t('gitDeploy.connect.reposFailed')
    } finally {
      connect.loadingRepos = false
    }
  }

  async function connectRepo() {
    if (!connect.repository) return
    connect.connecting = true
    connect.error = null
    try {
      const { data } = await gitApi.connect(projectId(), {
        provider_id: connect.providerId,
        repository:  connect.repository,
        ...credentials(),
      })
      applyInfo(data.data ?? data)
      connect.accessToken = ''
      connect.tokenRef = ''
      resetRepos()
      toast.success(t('gitDeploy.connect.connected', { repo: info.value?.connection?.repo_full_name ?? '' }))
    } catch (e) {
      connect.error = firstError(e) ?? t('gitDeploy.connect.failed')
    } finally {
      connect.connecting = false
    }
  }

  async function disconnect() {
    const repo = connection.value?.repo_full_name
    const ok = await confirm({
      title:        t('gitDeploy.connect.disconnectTitle'),
      message:      t('gitDeploy.connect.disconnectConfirm', { repo }),
      confirmLabel: t('gitDeploy.connect.disconnect'),
      destructive:  true,
    })
    if (!ok) return
    busy.value = { ...busy.value, disconnect: true }
    try {
      const { data } = await gitApi.disconnect(projectId())
      applyInfo(data.data ?? data)
      previews.value = (await gitApi.previews(projectId())).data.data ?? []
      toast.success(t('gitDeploy.connect.disconnected'))
    } catch (e) {
      toast.error(firstError(e) ?? t('gitDeploy.connect.failed'))
    } finally {
      busy.value = { ...busy.value, disconnect: false }
    }
  }

  // ── Previews ───────────────────────────────────────────────────────────────
  async function deletePreview(preview) {
    const ok = await confirm({
      title:        t('gitDeploy.previews.deleteTitle'),
      message:      t('gitDeploy.previews.deleteConfirm', { number: preview.number }),
      confirmLabel: t('common.delete'),
      destructive:  true,
    })
    if (!ok) return
    busy.value = { ...busy.value, [`preview:${preview.id}`]: true }
    try {
      await gitApi.deletePreview(projectId(), preview.id)
      previews.value = previews.value.filter(p => p.id !== preview.id)
      toast.success(t('gitDeploy.previews.deleted', { number: preview.number }))
    } catch (e) {
      toast.error(firstError(e) ?? t('gitDeploy.previews.deleteFailed'))
    } finally {
      busy.value = { ...busy.value, [`preview:${preview.id}`]: false }
    }
  }

  async function redeployPreview(preview) {
    busy.value = { ...busy.value, [`preview:${preview.id}`]: true }
    try {
      const { data } = await gitApi.redeployPreview(projectId(), preview.id)
      const row = data.data ?? data
      previews.value = previews.value.map(p => (p.id === row.id ? row : p))
    } catch (e) {
      toast.error(firstError(e) ?? t('gitDeploy.previews.redeployFailed'))
    } finally {
      busy.value = { ...busy.value, [`preview:${preview.id}`]: false }
    }
  }

  // ── Provider round trips (?git_installation, ?gitlab_token_ref, ?git_error) ──
  function consumeQuery() {
    const q = route?.query ?? {}
    if (!q.git_installation && !q.gitlab_token_ref && !q.git_error) return
    if (q.git_installation) {
      connect.installationId = String(q.git_installation)
      toast.success(t('gitDeploy.connect.installed'))
    }
    if (q.gitlab_token_ref) {
      connect.tokenRef = String(q.gitlab_token_ref)
      if (q.git_provider) connect.providerId = String(q.git_provider)
      toast.success(t('gitDeploy.connect.signedIn'))
    }
    if (q.git_error) {
      const key = `gitDeploy.connect.errors.${q.git_error}`
      toast.error(te(key) ? t(key) : t('gitDeploy.connect.failed'), 6000)
    }
    const rest = { ...q }
    delete rest.git_installation
    delete rest.gitlab_token_ref
    delete rest.git_provider
    delete rest.git_error
    router?.replace({ query: rest, hash: route.hash })
  }

  // ── Polling while something is in flight ──────────────────────────────────
  let timer = null
  const inFlight = computed(() =>
    deploys.value.some(d => IN_FLIGHT_DEPLOYS.includes(d.status))
    || previews.value.some(p => IN_FLIGHT_PREVIEWS.includes(p.status)))

  watch(inFlight, (on) => {
    clearInterval(timer)
    timer = on ? setInterval(() => load({ silent: true }), POLL_MS) : null
  })

  // Another provider picked: its own credentials and repositories (the first pick keeps what a
  // provider round trip brought back)
  watch(() => connect.providerId, (now, before) => {
    if (before) {
      resetRepos()
      connect.installationId = ''
      connect.installations = []
      connect.tokenRef = ''
    }
    loadInstallations()
  })

  onMounted(async () => {
    consumeQuery()
    await load()
    await loadInstallations()
  })
  onUnmounted(() => clearInterval(timer))

  return {
    info, previews, deploys, loading, loadError, saving, saveError, busy, form, connect,
    abilities, providers, connection, provider, previewEnvError, dirty, inFlight,
    load, save, copy, redeploy,
    loadInstallations, installGithubApp, signInGitlab, loadRepos, connectRepo, disconnect,
    deletePreview, redeployPreview,
  }
}

function firstError(e) {
  const errors = e?.response?.data?.errors
  if (errors) return Object.values(errors).flat()[0]
  return e?.response?.data?.message ?? null
}
