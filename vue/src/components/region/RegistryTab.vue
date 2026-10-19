<script setup>
import { ref, computed, onUnmounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { knativeRegionsApi } from '@/api/knativeRegions'
import { useConfirm } from '@/composables/useConfirm'
import { useRegistrySetup } from '@/composables/useRegistrySetup'
import RegistrySection from './RegistrySection.vue'

// The Registry tab of an existing region: the registry it has, its removal, and the setup of one
// (POST /knative-regions/{id}/registry) — for a region created without one, after a removal, or
// to replace an external registry
const { t } = useI18n()
const { confirm } = useConfirm()

const props = defineProps({
  region: { type: Object, required: true },
})
const emit = defineEmits(['saved'])

const form = useRegistrySetup()
const storageClasses = ref([])
const classesLoaded = ref(false)
const busy = ref(false)
const error = ref(null)
const replacing = ref(false)
let poll = null

const status = computed(() => props.region.registry_status)
const installing = computed(() => status.value === 'installing')
const hasRegistry = computed(() => !!props.region.registry_mode && status.value === 'ready')
// an internal registry is removed first; an external one is replaced
const showForm = computed(() => !installing.value && (!hasRegistry.value || (replacing.value && props.region.registry_mode === 'external')))

async function loadStorageClasses() {
  if (classesLoaded.value) return
  classesLoaded.value = true
  try {
    const { data: blob } = await knativeRegionsApi.downloadKubeconfig(props.region.id)
    const kubeconfig = typeof blob === 'string' ? blob : await blob.text()
    const { data } = await knativeRegionsApi.previewStorageClasses({ kubeconfig })
    storageClasses.value = data.storageClasses || []
    const def = storageClasses.value.find(sc => sc.is_default)
    if (def && !form.pvcConfig.value.storageClass) form.pvcConfig.value = { ...form.pvcConfig.value, storageClass: def.name }
  } catch {
    storageClasses.value = []
  }
}
watch(showForm, (show) => { if (show) loadStorageClasses() }, { immediate: true })
// another registry (set up, removed): the replace form closes
watch(() => [props.region.registry_mode, props.region.registry_status], () => { replacing.value = false })

function startPoll() {
  stopPoll()
  poll = setInterval(async () => {
    try {
      const { data } = await knativeRegionsApi.registryStatusForRegion(props.region.id)
      emit('saved', { ...props.region, ...data })
      if (data.registry_status !== 'installing') stopPoll()
    } catch { /* next tick */ }
  }, 3000)
}
function stopPoll() { if (poll) { clearInterval(poll); poll = null } }
watch(installing, (v) => (v ? startPoll() : stopPoll()), { immediate: true })
onUnmounted(stopPoll)

async function submit() {
  busy.value = true
  error.value = null
  try {
    const { data } = await knativeRegionsApi.configureRegistry(props.region.id, form.getRegistryPayload())
    replacing.value = false
    const region = data.region?.data ?? data.region
    if (region) emit('saved', region)
  } catch (e) {
    error.value = e.response?.data?.message ?? Object.values(e.response?.data?.errors ?? {}).flat()[0] ?? t('common.error')
  } finally { busy.value = false }
}

async function remove() {
  const ok = await confirm({
    title: t('settings.regionDetail.registry.remove'),
    message: t(props.region.registry_mode === 'internal' ? 'settings.regionDetail.registry.removeInternalConfirm' : 'settings.regionDetail.registry.removeExternalConfirm'),
    destructive: true,
  })
  if (!ok) return
  busy.value = true
  error.value = null
  try {
    const { data } = await knativeRegionsApi.removeRegistry(props.region.id)
    emit('saved', data.data ?? data)
  } catch (e) {
    error.value = e.response?.data?.message ?? t('common.error')
  } finally { busy.value = false }
}
</script>

<template>
  <div class="space-y-4" data-testid="registry-tab">
    <RegistrySection v-if="region.registry_mode && !showForm" :readonly="true" :registry-mode="region.registry_mode" :setup-status="status"
      :is-setting-up="installing" setup-phase="queued" :setup-error="status === 'error' ? region.registry_status_message : null" />
    <p v-if="region.registry_address && hasRegistry" class="text-xs text-gray-400 font-mono bg-gray-900/60 rounded-sm px-3 py-2">{{ region.registry_address }}</p>
    <p v-if="installing && region.registry_status_message" class="text-xs text-gray-400" data-testid="registry-progress">{{ region.registry_status_message }}</p>

    <template v-if="showForm">
      <p class="text-xs text-gray-400">{{ t(hasRegistry ? 'settings.regionDetail.registry.replaceHint' : 'settings.regionDetail.registry.setupHint') }}</p>
      <RegistrySection
        :registry-mode="form.registryMode.value" :storage-type="form.storageType.value"
        :pvc-config="form.pvcConfig.value" :s3-config="form.s3Config.value" :external-config="form.externalConfig.value"
        :storage-classes="storageClasses" :free-storage-class="!storageClasses.length"
        @update:registry-mode="form.registryMode.value = $event" @update:storage-type="form.storageType.value = $event"
        @update:pvc-config="form.pvcConfig.value = $event" @update:s3-config="form.s3Config.value = $event"
        @update:external-config="form.externalConfig.value = $event" />
      <div class="flex gap-2">
        <button type="button" class="btn-primary text-xs" :disabled="busy || form.hasValidationError.value" data-testid="setup-registry" @click="submit">
          {{ busy ? t('common.saving') : t('settings.regionDetail.registry.setup') }}
        </button>
        <button v-if="replacing" type="button" class="btn-secondary text-xs" @click="replacing = false">{{ t('common.cancel') }}</button>
      </div>
    </template>

    <div v-if="hasRegistry && !replacing" class="flex gap-2">
      <button v-if="region.registry_mode === 'external'" type="button" class="btn-secondary text-xs" data-testid="replace-registry" @click="replacing = true">
        {{ t('settings.regionDetail.registry.replace') }}
      </button>
      <button type="button" class="btn-secondary text-xs text-red-400! hover:text-red-300!" :disabled="busy" data-testid="remove-registry" @click="remove">
        {{ t('settings.regionDetail.registry.remove') }}
      </button>
    </div>

    <p v-if="error" class="text-xs text-red-400">{{ error }}</p>
  </div>
</template>
