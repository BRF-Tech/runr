import { ref, computed } from 'vue'
import { tokensApi } from '@/api/tokens'
import { useI18n } from 'vue-i18n'

export function useApiTokens() {
  const { t, te } = useI18n()

  const tokens             = ref([])
  const availableAbilities = ref([])
  const loading            = ref(false)
  const creating           = ref(false)
  const error              = ref(null)

  // Create form state
  const newName      = ref('')
  const newAbilities = ref([])
  const newExpiresAt = ref('')
  const noExpiry     = ref(true)   // the form starts with 'no expiry'
  const fullAccess   = ref(true)
  const createdToken = ref(null)
  const copied       = ref(false)

  const canCreate = computed(() =>
    newName.value.trim().length > 0 && (noExpiry.value || newExpiresAt.value)
      && (fullAccess.value || newAbilities.value.length > 0)
  )

  async function fetchTokens() {
    loading.value = true
    error.value   = null
    try {
      const { data } = await tokensApi.list()
      tokens.value             = data.tokens || []
      availableAbilities.value = data.available_abilities || []
    } catch (e) {
      error.value = e.response?.data?.message ?? t('common.error')
    } finally {
      loading.value = false
    }
  }

  async function createToken() {
    if (!canCreate.value) return
    creating.value = true
    error.value    = null
    try {
      const payload = {
        name:      newName.value.trim(),
        abilities: fullAccess.value ? ['*'] : newAbilities.value,
      }
      if (!noExpiry.value && newExpiresAt.value) payload.expires_at = newExpiresAt.value

      const { data } = await tokensApi.create(payload)
      createdToken.value = data.plain_token
      tokens.value.unshift(data.token)
      newName.value      = ''
      newAbilities.value = []
      newExpiresAt.value = ''
      noExpiry.value     = true
      fullAccess.value   = true
    } catch (e) {
      error.value = e.response?.data?.message ?? t('common.error')
    } finally {
      creating.value = false
    }
  }

  async function deleteToken(tokenId) {
    try {
      await tokensApi.destroy(tokenId)
      tokens.value = tokens.value.filter(t => t.id !== tokenId)
    } catch (e) {
      error.value = e.response?.data?.message ?? t('common.error')
    }
  }

  function copyToken() {
    if (!createdToken.value) return
    navigator.clipboard.writeText(createdToken.value).then(() => {
      copied.value = true
      setTimeout(() => { copied.value = false }, 2000)
    })
  }

  function dismissCreatedToken() {
    createdToken.value = null
    copied.value       = false
  }

  function toggleAbility(ability) {
    const idx = newAbilities.value.indexOf(ability)
    if (idx === -1) newAbilities.value.push(ability)
    else newAbilities.value.splice(idx, 1)
  }

  // Abilities are catalog permissions (docs/permissions.md); 0.4.x scope names keep their label
  function abilityLabel(ability) {
    const legacy = `tokens.ability_${ability.replace(':', '_')}`
    if (ability.includes(':') && te(legacy)) return t(legacy)
    const perm = `rbac.perm.${ability.replace(/\./g, '_')}`
    return te(perm) ? t(perm) : ability
  }

  return {
    tokens, availableAbilities, loading, creating, error,
    newName, newAbilities, newExpiresAt, noExpiry, fullAccess, createdToken, copied,
    canCreate,
    fetchTokens, createToken, deleteToken, copyToken,
    dismissCreatedToken, toggleAbility, abilityLabel,
  }
}
