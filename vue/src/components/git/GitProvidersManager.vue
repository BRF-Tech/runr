<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { GitBranch, Plus, Pencil, Trash2, Copy } from 'lucide-vue-next'
import { gitApi } from '@/api/git'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import GitProviderModal from '@/components/git/GitProviderModal.vue'
import TermText from '@/components/TermText.vue'

// Settings → Git providers: the GitHub App, GitLab and Forgejo/Gitea instances projects deploy from
const { t } = useI18n()
const toast = useToast()
const { confirm } = useConfirm()

const providers = ref([])
const loading   = ref(true)
const loadError = ref(null)
const editing   = ref(null)   // provider being edited, {} for a new one, null when closed

async function load() {
  loading.value = true
  loadError.value = null
  try {
    const { data } = await gitApi.providers()
    providers.value = data.data ?? []
  } catch (e) {
    loadError.value = e.response?.data?.message ?? t('gitDeploy.providers.loadFailed')
  } finally {
    loading.value = false
  }
}

function onSaved(provider) {
  const idx = providers.value.findIndex(p => p.id === provider.id)
  providers.value = idx === -1 ? [...providers.value, provider] : providers.value.map(p => (p.id === provider.id ? provider : p))
  editing.value = null
  toast.success(t('gitDeploy.providers.saved', { name: provider.name }))
}

async function remove(provider) {
  const ok = await confirm({
    title:        t('gitDeploy.providers.deleteTitle'),
    message:      t('gitDeploy.providers.deleteConfirm', { name: provider.name }),
    confirmLabel: t('common.delete'),
    destructive:  true,
  })
  if (!ok) return
  try {
    await gitApi.deleteProvider(provider.id)
    providers.value = providers.value.filter(p => p.id !== provider.id)
    toast.success(t('gitDeploy.providers.deleted', { name: provider.name }))
  } catch (e) {
    const errors = e.response?.data?.errors
    toast.error(errors ? Object.values(errors).flat()[0] : (e.response?.data?.message ?? t('gitDeploy.providers.deleteFailed')))
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

onMounted(load)
</script>

<template>
  <div class="card" data-testid="git-providers">
    <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-800">
      <GitBranch class="w-4 h-4" style="color:rgb(var(--brand-500))" />
      <h3 class="text-sm font-semibold text-gray-100">{{ t('gitDeploy.providers.title') }}</h3>
      <button type="button" class="btn-primary ml-auto" data-testid="git-provider-add" @click="editing = {}">
        <Plus class="w-4 h-4" />{{ t('gitDeploy.providers.add') }}
      </button>
    </div>
    <p class="text-xs text-gray-400 mb-4">{{ t('gitDeploy.providers.description') }}</p>

    <div v-if="loading" class="py-8 text-center text-gray-400 text-sm">{{ t('common.loading') }}</div>
    <p v-else-if="loadError" class="text-sm text-red-400">{{ loadError }}</p>
    <p v-else-if="!providers.length" class="text-sm text-gray-500" data-testid="git-providers-empty">{{ t('gitDeploy.providers.empty') }}</p>

    <div v-else class="space-y-3">
      <div v-for="p in providers" :key="p.id" class="border border-gray-800 rounded-lg p-4" :data-provider="p.name">
        <div class="flex items-start gap-3">
          <div class="min-w-0">
            <p class="text-sm font-semibold text-gray-100">
              {{ p.name }}
              <span class="ml-2 text-[10px] px-1.5 py-0.5 rounded-sm bg-gray-800 text-gray-400 font-normal">{{ t(`gitDeploy.providerTypes.${p.type}`) }}</span>
              <span v-if="!p.is_enabled" class="ml-1 text-[10px] px-1.5 py-0.5 rounded-sm bg-gray-800 text-gray-500 font-normal">{{ t('gitDeploy.providers.disabled') }}</span>
              <span v-else-if="!p.configured" class="ml-1 text-[10px] px-1.5 py-0.5 rounded-sm bg-amber-900/40 text-amber-400 font-normal">{{ t('gitDeploy.providers.incomplete') }}</span>
            </p>
            <p class="text-xs text-gray-500 font-mono mt-0.5">{{ p.web_url }}</p>
            <p class="text-xs text-gray-500 mt-0.5">{{ t('gitDeploy.providers.projects', { n: p.links_count }) }}</p>
          </div>
          <div class="ml-auto flex items-center gap-1">
            <button type="button" class="p-1.5 text-gray-400 hover:text-gray-100" :title="t('gitDeploy.providers.edit')" :aria-label="t('gitDeploy.providers.edit')" @click="editing = p">
              <Pencil class="w-4 h-4" />
            </button>
            <button type="button" class="p-1.5 text-gray-400 hover:text-red-400" :title="t('common.delete')" :aria-label="t('common.delete')" @click="remove(p)">
              <Trash2 class="w-4 h-4" />
            </button>
          </div>
        </div>

        <div v-if="p.urls && Object.keys(p.urls).length" class="mt-3 space-y-1.5">
          <p class="text-[11px] uppercase tracking-wider text-gray-500"><TermText :path="`gitDeploy.providers.urlsTitle.${p.type}`" /></p>
          <div v-for="(url, key) in p.urls" :key="key" class="flex items-center gap-2 text-xs">
            <span class="text-gray-500 w-20 shrink-0">{{ t(`gitDeploy.providers.urls.${key}`) }}</span>
            <code class="font-mono text-gray-300 truncate">{{ url }}</code>
            <button type="button" class="text-gray-500 hover:text-gray-200 shrink-0" :title="t('gitDeploy.copy')" :aria-label="t('gitDeploy.copy')" @click="copy(url)">
              <Copy class="w-3.5 h-3.5" />
            </button>
          </div>
        </div>
      </div>
    </div>

    <GitProviderModal v-if="editing" :provider="editing.id ? editing : null" @saved="onSaved" @close="editing = null" />
  </div>
</template>
