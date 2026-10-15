<script setup>
import { computed, inject } from 'vue'
import { useI18n } from 'vue-i18n'
import { Link2, ExternalLink, Unlink, Search, Check } from 'lucide-vue-next'
import { PROJECT_GIT } from '@/composables/useProjectGit'
import { usePermissionsStore } from '@/stores/permissions'
import TermText from '@/components/TermText.vue'

// Connect the project to a GitHub / GitLab / Forgejo repository (or show the connection)
const { t } = useI18n()
const git = inject(PROJECT_GIT)
const permissions = usePermissionsStore()

const type = computed(() => git.provider?.type ?? null)
const canConnect = computed(() => {
  if (!git.connect.providerId) return false
  if (type.value === 'github') return !!git.connect.installationId
  return !!git.connect.tokenRef || git.connect.accessToken.trim() !== ''
})

function providerLabel(p) {
  return `${p.name} (${t(`gitDeploy.providerTypes.${p.type}`)})`
}

function fmtDate(iso) {
  return iso ? new Date(iso).toLocaleString() : '-'
}
</script>

<template>
  <section class="card" data-testid="git-connect">
    <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-800">
      <Link2 class="w-4 h-4" style="color:rgb(var(--brand-500))" />
      <h3 class="text-sm font-semibold text-gray-100">{{ t('gitDeploy.connect.title') }}</h3>
    </div>

    <!-- Connected ───────────────────────────────────────────────────────── -->
    <div v-if="git.connection" data-testid="git-connection">
      <p class="text-xs text-gray-400 mb-2">{{ t(`gitDeploy.providerTypes.${git.connection.provider_type}`) }} · {{ git.connection.provider_name }}</p>
      <a v-if="git.connection.repo_url" :href="git.connection.repo_url" target="_blank" rel="noopener"
        class="inline-flex items-center gap-1.5 text-sm font-mono text-brand-500 hover:underline" data-testid="git-connection-repo">
        {{ git.connection.repo_full_name }}<ExternalLink class="w-3.5 h-3.5" />
      </a>
      <p v-else class="text-sm font-mono text-gray-100" data-testid="git-connection-repo">{{ git.connection.repo_full_name }}</p>

      <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs mt-3">
        <span class="text-gray-500">{{ t('gitDeploy.connect.defaultBranch') }}</span>
        <span class="text-gray-300 font-mono">{{ git.connection.default_branch || '-' }}</span>
        <span class="text-gray-500">{{ t('gitDeploy.connect.webhook') }}</span>
        <span :class="git.connection.webhook_registered ? 'text-emerald-400' : 'text-amber-400'">
          {{ git.connection.webhook_registered ? t('gitDeploy.connect.webhookOk') : t('gitDeploy.connect.webhookMissing') }}
        </span>
        <span class="text-gray-500">{{ t('gitDeploy.connect.lastEvent') }}</span>
        <span class="text-gray-300">{{ fmtDate(git.connection.last_event_at) }}</span>
      </div>

      <p class="text-xs text-gray-500 mt-3">{{ t('gitDeploy.connect.connectedHint', { branch: git.info.settings.deploy_branch }) }}</p>

      <button v-if="git.abilities.manage" type="button" class="btn-secondary mt-4" :disabled="git.busy.disconnect" data-testid="git-disconnect" @click="git.disconnect()">
        <Unlink class="w-4 h-4" />{{ t('gitDeploy.connect.disconnect') }}
      </button>
    </div>

    <!-- No provider ─────────────────────────────────────────────────────── -->
    <div v-else-if="!git.providers.length" class="text-sm text-gray-400" data-testid="git-no-providers">
      <p>{{ t('gitDeploy.connect.noProviders') }}</p>
      <RouterLink v-if="permissions.can('git_providers.manage')" to="/settings?tab=git-providers" class="text-xs text-brand-500 hover:underline mt-2 inline-block">
        {{ t('gitDeploy.connect.configureProviders') }}
      </RouterLink>
    </div>

    <!-- Not allowed ─────────────────────────────────────────────────────── -->
    <p v-else-if="!git.abilities.manage" class="text-sm text-gray-400">{{ t('gitDeploy.connect.notConnected') }}</p>

    <!-- Connect ─────────────────────────────────────────────────────────── -->
    <form v-else class="space-y-3" data-testid="git-connect-form" @submit.prevent="git.loadRepos()">
      <p class="text-xs text-gray-400">{{ t('gitDeploy.connect.intro') }}</p>

      <div>
        <label class="label" for="git-provider">{{ t('gitDeploy.connect.provider') }}</label>
        <select id="git-provider" v-model="git.connect.providerId" class="input" data-testid="git-provider-select">
          <option v-for="p in git.providers" :key="p.id" :value="p.id">{{ providerLabel(p) }}</option>
        </select>
      </div>

      <!-- GitHub App installations -->
      <div v-if="type === 'github'">
        <label class="label" for="git-installation"><TermText path="gitDeploy.connect.installation" /></label>
        <div class="flex gap-2">
          <select id="git-installation" v-model="git.connect.installationId" class="input" :disabled="!git.connect.installations.length" data-testid="git-installation-select">
            <option v-if="!git.connect.installations.length" value="">{{ t('gitDeploy.connect.noInstallations') }}</option>
            <option v-for="i in git.connect.installations" :key="i.installation_id" :value="i.installation_id">
              {{ i.account_login || i.installation_id }}
            </option>
          </select>
          <button type="button" class="btn-secondary shrink-0" data-testid="git-install-app" @click="git.installGithubApp()">
            <ExternalLink class="w-4 h-4" />{{ t('gitDeploy.connect.installApp') }}
          </button>
        </div>
        <p class="text-xs text-gray-500 mt-1">{{ t('gitDeploy.connect.installHint') }}</p>
      </div>

      <!-- Access token (GitLab / Forgejo) -->
      <template v-else-if="type">
        <div v-if="type === 'gitlab' && git.provider.connect_modes.includes('oauth')" class="flex items-center gap-3">
          <button type="button" class="btn-secondary" data-testid="git-gitlab-signin" @click="git.signInGitlab()">
            {{ t('gitDeploy.connect.signInGitlab') }}
          </button>
          <span v-if="git.connect.tokenRef" class="inline-flex items-center gap-1 text-xs text-emerald-400" data-testid="git-gitlab-signed-in">
            <Check class="w-3.5 h-3.5" />{{ t('gitDeploy.connect.signedIn') }}
          </span>
          <span v-else class="text-xs text-gray-500">{{ t('gitDeploy.connect.orToken') }}</span>
        </div>
        <div v-if="!git.connect.tokenRef">
          <label class="label" for="git-token">{{ t('gitDeploy.connect.accessToken') }}</label>
          <input id="git-token" v-model="git.connect.accessToken" type="password" autocomplete="off" class="input font-mono" data-testid="git-access-token"
            :placeholder="t(`gitDeploy.connect.tokenPlaceholder.${type}`)" />
          <p class="text-xs text-gray-500 mt-1">{{ t(`gitDeploy.connect.tokenHint.${type}`) }}</p>
        </div>
      </template>

      <div class="flex gap-2">
        <div class="relative flex-1">
          <Search class="w-4 h-4 text-gray-500 absolute left-3 top-1/2 -translate-y-1/2" />
          <input v-model="git.connect.search" class="input pl-9" :placeholder="t('gitDeploy.connect.searchPlaceholder')" data-testid="git-repo-search" />
        </div>
        <button type="submit" class="btn-secondary shrink-0" :disabled="!canConnect || git.connect.loadingRepos" data-testid="git-load-repos">
          {{ git.connect.loadingRepos ? t('common.loading') : t('gitDeploy.connect.loadRepos') }}
        </button>
      </div>

      <div v-if="git.connect.reposLoaded" class="max-h-56 overflow-y-auto border border-gray-800 rounded-lg divide-y divide-gray-800" data-testid="git-repo-list">
        <p v-if="!git.connect.repos.length" class="p-3 text-xs text-gray-500">{{ t('gitDeploy.connect.noRepos') }}</p>
        <label v-for="r in git.connect.repos" :key="r.id" class="flex items-center gap-3 px-3 py-2 cursor-pointer hover:bg-gray-800/50" :data-repo="r.full_name">
          <input v-model="git.connect.repository" type="radio" name="git-repository" :value="r.full_name" class="accent-brand-500" />
          <span class="text-sm font-mono text-gray-200 truncate">{{ r.full_name }}</span>
          <span class="ml-auto text-[11px] text-gray-500">{{ r.private ? t('gitDeploy.connect.private') : t('gitDeploy.connect.public') }}</span>
        </label>
      </div>

      <p v-if="git.connect.error" class="text-xs text-red-400" data-testid="git-connect-error">{{ git.connect.error }}</p>

      <button v-if="git.connect.reposLoaded" type="button" class="btn-primary w-full justify-center" :disabled="!git.connect.repository || git.connect.connecting" data-testid="git-connect-submit" @click="git.connectRepo()">
        {{ git.connect.connecting ? t('gitDeploy.connect.connecting') : t('gitDeploy.connect.connectRepo', { repo: git.connect.repository || '…' }) }}
      </button>
    </form>
  </section>
</template>
