<script setup>
import { computed, inject } from 'vue'
import { useI18n } from 'vue-i18n'
import { GitBranch, Copy, RotateCw, Lock } from 'lucide-vue-next'
import { PROJECT_GIT } from '@/composables/useProjectGit'

// The project's RunR git remote: URL, commands, read-only state, redeploy of the branch head
const { t } = useI18n()
const git = inject(PROJECT_GIT)

const remote   = computed(() => git.info?.remote_url ?? '')
const branch   = computed(() => git.info?.settings?.deploy_branch ?? 'main')
const commands = computed(() => [
  `git remote add runr ${remote.value}`,
  `git push runr ${branch.value}`,
].join('\n'))
</script>

<template>
  <section class="card" data-testid="git-remote">
    <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-800">
      <GitBranch class="w-4 h-4" style="color:rgb(var(--brand-500))" />
      <h3 class="text-sm font-semibold text-gray-100">{{ t('gitDeploy.remote.title') }}</h3>
      <span v-if="git.info.read_only" class="ml-auto inline-flex items-center gap-1 text-[11px] text-amber-400" data-testid="git-read-only">
        <Lock class="w-3 h-3" />{{ t('gitDeploy.remote.readOnly') }}
      </span>
    </div>

    <label class="label">{{ t('gitDeploy.remote.url') }}</label>
    <div class="flex gap-2">
      <input :value="remote" readonly class="input font-mono text-xs" data-testid="git-remote-url" @focus="$event.target.select()" />
      <button type="button" class="btn-secondary shrink-0" :title="t('gitDeploy.copy')" :aria-label="t('gitDeploy.copy')" data-testid="git-remote-copy" @click="git.copy(remote)">
        <Copy class="w-4 h-4" />
      </button>
    </div>

    <p class="text-xs text-gray-400 mt-3">
      {{ git.info.read_only
        ? t('gitDeploy.remote.readOnlyHint', { repo: git.info.connection?.repo_full_name ?? '' })
        : t('gitDeploy.remote.pushHint', { branch }) }}
    </p>

    <div v-if="!git.info.read_only" class="mt-3 relative">
      <pre class="bg-gray-950 border border-gray-800 rounded-lg p-3 text-xs font-mono text-gray-300 overflow-x-auto" data-testid="git-remote-commands">{{ commands }}</pre>
      <button type="button" class="absolute top-2 right-2 text-gray-500 hover:text-gray-200" :title="t('gitDeploy.copy')" :aria-label="t('gitDeploy.copy')" @click="git.copy(commands)">
        <Copy class="w-3.5 h-3.5" />
      </button>
    </div>

    <p class="text-xs text-gray-500 mt-3">
      {{ t('gitDeploy.remote.authHint', { user: git.info.username_hint }) }}
      <RouterLink to="/settings?tab=tokens" class="text-brand-500 hover:underline">{{ t('gitDeploy.remote.tokensLink') }}</RouterLink>
    </p>

    <div class="mt-4 flex items-center justify-between gap-3 text-xs">
      <span class="text-gray-400">
        {{ t('gitDeploy.remote.head', { branch }) }}
        <code v-if="git.info.remote_head" class="font-mono text-gray-200" data-testid="git-remote-head">{{ git.info.remote_head.slice(0, 7) }}</code>
        <span v-else class="text-gray-500">{{ t('gitDeploy.remote.noCommits') }}</span>
      </span>
      <button
        v-if="git.abilities.push && !git.info.read_only && git.info.remote_head"
        type="button" class="btn-secondary" :disabled="git.busy.redeploy" data-testid="git-redeploy"
        @click="git.redeploy()"
      >
        <RotateCw class="w-4 h-4" :class="git.busy.redeploy && 'animate-spin'" />{{ t('gitDeploy.remote.redeploy') }}
      </button>
    </div>
  </section>
</template>
