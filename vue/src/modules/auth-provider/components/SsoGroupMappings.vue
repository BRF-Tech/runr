<script setup>
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { Plus, Trash2, Users } from 'lucide-vue-next'
import { rbacApi } from '@/api/rbac'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { useRbacLabels } from '@/composables/useRbac'
import RoleSelect from '@/components/rbac/RoleSelect.vue'

/** External IdP group → group and / or role of the organization (applied on every SSO sign-in). */
const props = defineProps({
  providers: { type: Array, default: () => [] },
})

const { t } = useI18n()
const { confirm } = useConfirm()
const toast = useToast()
const { roleLabel } = useRbacLabels()

const mappings = ref([])
const groups   = ref([])
const roles    = ref([])
const loading  = ref(true)
const saving   = ref(false)
const error    = ref(null)
const form     = ref({ external_group: '', auth_provider_id: '', group_id: '', role: null })

function message(e) {
  return e.response?.data?.message ?? Object.values(e.response?.data?.errors ?? {}).flat()[0] ?? t('common.error')
}

async function load() {
  loading.value = true
  try {
    const [m, g, r] = await Promise.all([rbacApi.ssoMappings(), rbacApi.groups(), rbacApi.roles()])
    mappings.value = m.data.mappings ?? []
    groups.value   = g.data.groups ?? []
    roles.value    = (r.data.roles ?? []).filter(x => x.key !== 'owner')
  } finally {
    loading.value = false
  }
}

async function add() {
  saving.value = true
  error.value  = null
  try {
    await rbacApi.createSsoMapping({
      external_group:   form.value.external_group.trim(),
      auth_provider_id: form.value.auth_provider_id || null,
      group_id:         form.value.group_id || null,
      role:             form.value.role || null,
    })
    form.value = { external_group: '', auth_provider_id: form.value.auth_provider_id, group_id: '', role: null }
    await load()
  } catch (e) {
    error.value = message(e)
  } finally {
    saving.value = false
  }
}

async function remove(mapping) {
  const ok = await confirm({ title: t('authProvider.mappingDeleteTitle'), message: t('authProvider.mappingDeleteConfirm', { group: mapping.external_group }), destructive: true })
  if (!ok) return
  try {
    await rbacApi.deleteSsoMapping(mapping.id)
    await load()
  } catch (e) {
    toast.error(message(e))
  }
}

onMounted(load)
</script>

<template>
  <div class="rounded-xl border border-gray-800 bg-gray-900/60 p-5 space-y-4" data-testid="sso-group-mappings">
    <div class="flex items-center gap-2">
      <Users class="w-4 h-4" style="color:rgb(var(--brand-500))" />
      <div>
        <h3 class="text-sm font-semibold text-gray-100">{{ t('authProvider.mappingsTitle') }}</h3>
        <p class="text-xs text-gray-400 mt-0.5">{{ t('authProvider.mappingsDescription') }}</p>
      </div>
    </div>

    <div class="grid grid-cols-[1fr_10rem_10rem_10rem_auto] gap-2 items-end">
      <div>
        <label class="label">{{ t('authProvider.mappingExternal') }}</label>
        <input v-model="form.external_group" class="input text-xs font-mono" :placeholder="t('authProvider.mappingExternalPlaceholder')" data-testid="mapping-external" />
      </div>
      <div>
        <label class="label">{{ t('authProvider.mappingProvider') }}</label>
        <select v-model="form.auth_provider_id" class="input text-xs">
          <option value="">{{ t('authProvider.mappingAnyProvider') }}</option>
          <option v-for="p in props.providers" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
      </div>
      <div>
        <label class="label">{{ t('authProvider.mappingGroup') }}</label>
        <select v-model="form.group_id" class="input text-xs" data-testid="mapping-group">
          <option value="">{{ t('authProvider.mappingNoGroup') }}</option>
          <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
        </select>
      </div>
      <div>
        <label class="label">{{ t('authProvider.mappingRole') }}</label>
        <RoleSelect v-model="form.role" :roles="roles" allow-empty :empty-label="t('authProvider.mappingNoRole')" size="xs" data-testid="mapping-role" />
      </div>
      <button class="btn-primary text-xs flex items-center gap-1.5 h-9"
        :disabled="saving || !form.external_group.trim() || (!form.group_id && !form.role)"
        data-testid="mapping-add" @click="add">
        <Plus class="w-3.5 h-3.5" />{{ t('authProvider.mappingAdd') }}
      </button>
    </div>
    <p v-if="error" class="text-xs text-red-400">{{ error }}</p>

    <p v-if="loading" class="text-xs text-gray-400 text-center py-2">{{ t('common.loading') }}</p>
    <p v-else-if="!mappings.length" class="text-xs text-gray-400 italic">{{ t('authProvider.mappingNone') }}</p>
    <div v-else class="space-y-1.5">
      <div v-for="m in mappings" :key="m.id" class="flex items-center gap-3 rounded-md border border-gray-800 px-3 py-2 text-xs" :data-mapping="m.external_group">
        <span class="font-mono text-gray-100">{{ m.external_group }}</span>
        <span class="text-gray-500">→</span>
        <span v-if="m.group" class="px-1.5 py-0.5 rounded border border-gray-700 text-gray-300">{{ m.group.name }}</span>
        <span v-if="m.role" class="px-1.5 py-0.5 rounded border border-sky-800/50 bg-sky-900/30 text-sky-400">{{ roleLabel(m.role, roles) }}</span>
        <span class="flex-1" />
        <span class="text-gray-500">{{ m.provider?.name ?? t('authProvider.mappingAnyProvider') }}</span>
        <button class="text-gray-400 hover:text-red-400" @click="remove(m)"><Trash2 class="w-3.5 h-3.5" /></button>
      </div>
    </div>
  </div>
</template>
