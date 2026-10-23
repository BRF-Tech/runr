<script setup>
// Build & Run → Images (docs/images.md): the organization's uploaded images per region — container
// images and WebAssembly modules — with their scan, the storage quota, upload (with resume of
// unfinished uploads) and delete.
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { Package, Upload, RefreshCw, Trash2, Copy } from 'lucide-vue-next'
import { imagesApi } from '../api'
import ImageUploadDialog from './ImageUploadDialog.vue'
import { useSettingsStore } from '@/stores/settings'
import { usePermissionsStore } from '@/stores/permissions'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import { formatBytes } from '@/utils/ai'

const { t } = useI18n()
const settingsStore = useSettingsStore()
const perms = usePermissionsStore()
const toast = useToast()
const { confirm } = useConfirm()

const images    = ref([])
const quota     = ref(null)
const uploads   = ref([])
const loading   = ref(true)
const loadError = ref(null)
const regionId  = ref('')
const dialog    = ref(null)   // { resume } while the upload dialog is open

const canUpload = computed(() => perms.can('images.upload'))
const canDelete = computed(() => perms.can('images.delete'))
const regions   = computed(() => settingsStore.data.regions ?? [])
const shown     = computed(() => images.value.filter(i => !regionId.value || i.knative_region_id === regionId.value))
const quotaText = computed(() => {
  if (!quota.value) return ''
  const used = formatBytes(quota.value.used_bytes) === '—' ? '0 B' : formatBytes(quota.value.used_bytes)
  return quota.value.limit_bytes > 0
    ? t('images.quota', { used, limit: formatBytes(quota.value.limit_bytes) })
    : t('images.quotaUnlimited', { used })
})
const quotaPercent = computed(() => quota.value?.limit_bytes > 0 ? Math.min(100, Math.round(quota.value.used_bytes / quota.value.limit_bytes * 100)) : 0)

async function load() {
  loading.value = true
  loadError.value = null
  try {
    const { data } = await imagesApi.list()
    images.value = data.data ?? []
    quota.value  = data.quota ?? null
    if (canUpload.value) {
      const open = await imagesApi.uploads()
      uploads.value = open.data?.data ?? []
    }
  } catch (e) {
    loadError.value = e.response?.data?.message ?? t('images.loadFailed')
  } finally {
    loading.value = false
  }
}

function securityText(image) {
  const s = image.security
  if (!s) return t('images.security.none')
  if (s.status === 'pending' || s.status === 'running') return t(`images.security.${s.status}`)
  if (s.scan_status !== 'ok') return t('images.security.failed')
  return t('images.security.counts', { critical: s.counts?.critical ?? 0, high: s.counts?.high ?? 0 })
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text)
    toast.success(t('images.copied'))
  } catch {
    toast.error(t('images.copyFailed'))
  }
}

async function remove(image) {
  const ok = await confirm({
    title:        t('images.deleteTitle'),
    message:      t('images.deleteConfirm', { name: image.name, region: image.region_name ?? image.knative_region_id }),
    confirmLabel: t('images.delete'),
    destructive:  true,
  })
  if (!ok) return
  try {
    await imagesApi.remove(image.id)
    images.value = images.value.filter(i => i.id !== image.id)
    toast.success(t('images.deleted', { name: image.name }))
    imagesApi.quota().then(r => { quota.value = r.data }).catch(() => {})
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('images.deleteFailed'))
  }
}

async function abortUpload(upload) {
  try {
    await imagesApi.abortUpload(upload.id)
    uploads.value = uploads.value.filter(u => u.id !== upload.id)
    toast.success(t('images.aborted'))
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  }
}

function onCompleted() {
  dialog.value = null
  load()
}

onMounted(() => {
  if (!settingsStore.loaded) settingsStore.fetch()
  load()
})
</script>

<template>
  <div class="card" data-testid="images-manager">
    <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-800">
      <Package class="w-4 h-4" style="color:rgb(var(--brand-500))" />
      <h3 class="text-sm font-semibold text-gray-100">{{ t('images.title') }}</h3>
      <div class="ml-auto flex items-center gap-2">
        <select v-model="regionId" class="input py-1! text-xs w-44" data-testid="images-region-filter">
          <option value="">{{ t('images.allRegions') }}</option>
          <option v-for="r in regions" :key="r.id" :value="r.id">{{ r.name }}</option>
        </select>
        <button type="button" class="btn-secondary text-xs" :title="t('images.refresh')" data-testid="images-refresh" @click="load">
          <RefreshCw class="w-3.5 h-3.5" />
        </button>
        <button v-if="canUpload" type="button" class="btn-primary" data-testid="images-upload" @click="dialog = { resume: null }">
          <Upload class="w-4 h-4" />{{ t('images.upload') }}
        </button>
      </div>
    </div>
    <p class="text-xs text-gray-400 mb-4">{{ t('images.description') }}</p>

    <div v-if="quota" class="mb-4" data-testid="images-quota">
      <div class="flex justify-between text-[11px] text-gray-400 mb-1"><span>{{ quotaText }}</span><span v-if="quota.limit_bytes > 0">{{ quotaPercent }}%</span></div>
      <div v-if="quota.limit_bytes > 0" class="h-1.5 bg-gray-800 rounded-full overflow-hidden">
        <div class="h-full rounded-full" :style="{ width: quotaPercent + '%', background: quotaPercent >= 90 ? '#ef4444' : 'rgb(var(--brand-500))' }" />
      </div>
    </div>

    <!-- Unfinished uploads -->
    <div v-if="uploads.length" class="mb-4 rounded-lg border border-amber-800/50 bg-amber-950/10 p-3" data-testid="images-open-uploads">
      <p class="text-xs font-medium text-amber-300 mb-2">{{ t('images.openUploads') }}</p>
      <div v-for="u in uploads" :key="u.id" class="flex items-center gap-3 text-xs py-1" :data-upload="u.id">
        <span class="font-mono text-gray-200">{{ u.name }}</span>
        <span class="text-gray-500">{{ u.region_name }}</span>
        <span class="text-gray-500 font-mono">{{ formatBytes(u.uploaded_bytes) }} / {{ formatBytes(u.size_bytes) }} · {{ u.status }}</span>
        <span class="ml-auto flex gap-2">
          <button type="button" class="btn-secondary text-xs" data-testid="images-resume" @click="dialog = { resume: u }">{{ t('images.resume') }}</button>
          <button type="button" class="btn-secondary text-xs" data-testid="images-abort" @click="abortUpload(u)">{{ t('images.abort') }}</button>
        </span>
      </div>
    </div>

    <div v-if="loading" class="py-10 text-center text-gray-400 text-sm">{{ t('images.loading') }}</div>
    <p v-else-if="loadError" class="text-sm text-red-400">{{ loadError }}</p>
    <p v-else-if="!shown.length" class="py-8 text-center text-sm text-gray-500" data-testid="images-empty">{{ t('images.empty') }}</p>

    <div v-else class="overflow-x-auto">
      <table class="w-full text-xs">
        <thead>
          <tr class="text-left text-gray-500 border-b border-gray-800">
            <th class="py-2 pr-3 font-medium">{{ t('images.columns.name') }}</th>
            <th class="py-2 pr-3 font-medium">{{ t('images.columns.region') }}</th>
            <th class="py-2 pr-3 font-medium">{{ t('images.columns.kind') }}</th>
            <th class="py-2 pr-3 font-medium">{{ t('images.columns.platforms') }}</th>
            <th class="py-2 pr-3 font-medium">{{ t('images.columns.size') }}</th>
            <th class="py-2 pr-3 font-medium">{{ t('images.columns.security') }}</th>
            <th class="py-2 pr-3 font-medium">{{ t('images.columns.uploaded') }}</th>
            <th class="py-2" />
          </tr>
        </thead>
        <tbody>
          <tr v-for="image in shown" :key="image.id" class="border-b border-gray-800/60" :data-image="image.name">
            <td class="py-2 pr-3">
              <p class="font-mono text-gray-100">{{ image.name }}</p>
              <p class="font-mono text-[10px] text-gray-500 truncate max-w-[16rem]" :title="image.digest">{{ image.digest }}</p>
              <p v-if="image.note" class="text-[10px] text-amber-400 max-w-[20rem]">{{ image.note }}</p>
              <!-- The region moved to another cluster since the upload: its registry does not have it -->
              <p v-if="image.in_registry === false" class="text-[10px] text-amber-400 max-w-[20rem]" data-testid="image-not-in-registry">
                {{ t('images.notInRegistry') }}
              </p>
            </td>
            <td class="py-2 pr-3 text-gray-300">{{ image.region_name ?? image.knative_region_id }}</td>
            <td class="py-2 pr-3 text-gray-300">{{ t(`images.kind.${image.kind}`) }}</td>
            <td class="py-2 pr-3 font-mono text-gray-400">{{ (image.platforms ?? []).join(', ') || '—' }}</td>
            <td class="py-2 pr-3 font-mono text-gray-400">{{ formatBytes(image.size_bytes) }}</td>
            <td class="py-2 pr-3 text-gray-400" data-testid="image-security">{{ image.kind === 'image' ? securityText(image) : '—' }}</td>
            <td class="py-2 pr-3 text-gray-500">{{ image.created_at ? new Date(image.created_at).toLocaleString() : '—' }}</td>
            <td class="py-2 text-right whitespace-nowrap">
              <button type="button" class="p-1 text-gray-400 hover:text-gray-100" :title="t('images.copyRef')" data-testid="image-copy" @click="copy(image.image_ref)"><Copy class="w-3.5 h-3.5" /></button>
              <button v-if="canDelete" type="button" class="p-1 text-gray-400 hover:text-red-400" :title="t('images.delete')" data-testid="image-delete" @click="remove(image)"><Trash2 class="w-3.5 h-3.5" /></button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <ImageUploadDialog
      v-if="dialog"
      :resume="dialog.resume"
      :region-id="regionId || null"
      @close="dialog = null; load()"
      @completed="onCompleted"
    />
  </div>
</template>
