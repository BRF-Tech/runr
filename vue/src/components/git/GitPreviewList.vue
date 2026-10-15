<script setup>
import { inject } from 'vue'
import { useI18n } from 'vue-i18n'
import { Eye, ExternalLink, Trash2, RotateCw, GitPullRequest } from 'lucide-vue-next'
import { PROJECT_GIT } from '@/composables/useProjectGit'
import GitStatusBadge from '@/components/git/GitStatusBadge.vue'
import TermText from '@/components/TermText.vue'

// Preview environments of pull/merge requests: URL, status, delete / redeploy
const { t } = useI18n()
const git = inject(PROJECT_GIT)

function fmtDate(iso) {
  return iso ? new Date(iso).toLocaleString() : '-'
}
</script>

<template>
  <section class="card" data-testid="git-previews">
    <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-800">
      <Eye class="w-4 h-4" style="color:rgb(var(--brand-500))" />
      <h3 class="text-sm font-semibold text-gray-100">{{ t('gitDeploy.previews.title') }}</h3>
      <span class="ml-auto text-xs text-gray-500">{{ t('gitDeploy.previews.count', { n: git.previews.length }) }}</span>
    </div>

    <p v-if="!git.previews.length" class="text-sm text-gray-500" data-testid="git-previews-empty">
      {{ git.info?.settings?.previews_enabled ? t('gitDeploy.previews.empty') : t('gitDeploy.previews.off') }}
    </p>

    <div v-else class="overflow-x-auto -mx-5">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-[11px] uppercase tracking-wider text-gray-500 border-b border-gray-800">
            <th class="px-5 py-2 font-medium">{{ t('gitDeploy.previews.request') }}</th>
            <th class="px-3 py-2 font-medium"><TermText path="gitDeploy.history.commit" /></th>
            <th class="px-3 py-2 font-medium">{{ t('gitDeploy.history.status') }}</th>
            <th class="px-3 py-2 font-medium">{{ t('gitDeploy.previews.url') }}</th>
            <th class="px-3 py-2 font-medium">{{ t('gitDeploy.previews.expires') }}</th>
            <th class="px-5 py-2" />
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in git.previews" :key="p.id" class="border-b border-gray-800/60" :data-preview="p.number">
            <td class="px-5 py-2.5">
              <div class="flex items-center gap-2">
                <GitPullRequest class="w-3.5 h-3.5 text-gray-500 shrink-0" />
                <a v-if="p.pr_url" :href="p.pr_url" target="_blank" rel="noopener" class="text-gray-100 hover:text-brand-500">#{{ p.number }}</a>
                <span v-else class="text-gray-100">#{{ p.number }}</span>
                <span class="text-gray-400 truncate max-w-[16rem]" :title="p.title">{{ p.title }}</span>
                <span v-if="p.is_fork" class="text-[10px] px-1.5 py-0.5 rounded-sm bg-gray-800 text-gray-400">{{ t('gitDeploy.previews.fork') }}</span>
              </div>
              <p class="text-[11px] text-gray-500 font-mono mt-0.5">{{ p.branch }} → {{ p.base_branch }}</p>
            </td>
            <td class="px-3 py-2.5 font-mono text-xs text-gray-300">{{ p.short_sha || '-' }}</td>
            <td class="px-3 py-2.5">
              <GitStatusBadge :status="p.status" />
              <p v-if="p.status_message && ['failed', 'skipped'].includes(p.status)" class="text-[11px] text-gray-500 mt-1 max-w-[18rem]">{{ p.status_message }}</p>
            </td>
            <td class="px-3 py-2.5">
              <a v-if="p.url && p.status === 'ready'" :href="p.url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-xs font-mono text-brand-500 hover:underline" data-testid="git-preview-url">
                {{ p.url.replace(/^https?:\/\//, '') }}<ExternalLink class="w-3 h-3" />
              </a>
              <span v-else class="text-xs text-gray-500">-</span>
            </td>
            <td class="px-3 py-2.5 text-xs text-gray-400">{{ fmtDate(p.expires_at) }}</td>
            <td class="px-5 py-2.5 text-right whitespace-nowrap">
              <template v-if="git.abilities.manage_previews && p.status !== 'deleted'">
                <button v-if="['ready', 'failed'].includes(p.status)" type="button" class="p-1.5 text-gray-400 hover:text-gray-100" :title="t('gitDeploy.previews.redeploy')" :aria-label="t('gitDeploy.previews.redeploy')"
                  :disabled="git.busy[`preview:${p.id}`]" @click="git.redeployPreview(p)">
                  <RotateCw class="w-4 h-4" />
                </button>
                <button type="button" class="p-1.5 text-gray-400 hover:text-red-400" :title="t('common.delete')" :aria-label="t('common.delete')"
                  :disabled="git.busy[`preview:${p.id}`]" data-testid="git-preview-delete" @click="git.deletePreview(p)">
                  <Trash2 class="w-4 h-4" />
                </button>
              </template>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
