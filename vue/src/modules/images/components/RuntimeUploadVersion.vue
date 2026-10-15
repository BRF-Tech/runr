<script setup>
// Settings → Runtimes → Add / Edit: a version whose image is an uploaded image (`upload:`,
// docs/runtimes.md#versions). Emits { version, upload, makeDefault }; the editor adds it to the
// manifest text (appendUploadVersion).
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { PackagePlus } from 'lucide-vue-next'
import { imagesApi } from '../api'
import { usePermissionsStore } from '@/stores/permissions'

const { t } = useI18n()
const perms = usePermissionsStore()
const emit = defineEmits(['add'])

const images      = ref([])
const upload      = ref('')
const version     = ref('')
const makeDefault = ref(false)

// one entry per name: the reference resolves per region
const names = computed(() => [...new Set(images.value.map(i => i.name))].sort())
const canAdd = computed(() => !!upload.value && /^[0-9A-Za-z][0-9A-Za-z._-]{0,31}$/.test(version.value.trim()))

onMounted(async () => {
  if (!perms.can('images.view')) return
  try {
    const { data } = await imagesApi.list()
    images.value = data.data ?? []
  } catch {
    images.value = []
  }
})

function pick(name) {
  upload.value = name
  if (!version.value) version.value = name.split(':').pop() === 'latest' ? '1.0' : name.split(':').pop()
}

function add() {
  emit('add', { version: version.value.trim(), upload: upload.value, makeDefault: makeDefault.value })
  upload.value = ''
  version.value = ''
  makeDefault.value = false
}
</script>

<template>
  <section v-if="perms.can('images.view')" data-testid="runtime-upload-version">
    <p class="section-title">{{ t('images.runtime.title') }}</p>
    <p class="text-[11px] text-gray-400 mb-2">{{ t('images.runtime.hint') }}</p>
    <p v-if="!names.length" class="text-xs text-gray-500">{{ t('images.runtime.none') }}</p>
    <div v-else class="space-y-2">
      <select :value="upload" class="input text-xs font-mono" :aria-label="t('images.runtime.image')" data-testid="runtime-upload-image" @change="pick($event.target.value)">
        <option value="" disabled>{{ t('images.runtime.image') }}</option>
        <option v-for="n in names" :key="n" :value="n">{{ n }}</option>
      </select>
      <div class="flex items-center gap-2">
        <input v-model="version" class="input text-xs font-mono w-28" :placeholder="t('images.runtime.version')" data-testid="runtime-upload-version-label" />
        <label class="flex items-center gap-1.5 text-xs text-gray-300">
          <input v-model="makeDefault" type="checkbox" class="accent-brand-500" />{{ t('images.runtime.makeDefault') }}
        </label>
        <button type="button" class="btn-secondary text-xs ml-auto" :disabled="!canAdd" data-testid="runtime-upload-add" @click="add">
          <PackagePlus class="w-3.5 h-3.5" />{{ t('images.runtime.add') }}
        </button>
      </div>
    </div>
  </section>
</template>

<style scoped>
.section-title {
  @apply text-xs text-gray-400 uppercase tracking-wider font-medium mb-3 border-b border-gray-800/60 pb-2;
}
</style>
