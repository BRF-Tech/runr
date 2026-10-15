import { ref, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { accountApi } from '@/api/account'

export function useAccountProfile() {
  const { t } = useI18n()
  const auth  = useAuthStore()
  const toast = useToast()

  const loading = ref(false)
  const saving  = ref(false)
  const error   = ref(null)

  const form = reactive({
    name:     auth.user?.name || '',
    username: auth.user?.username || '',
    email:    auth.user?.email || '',
    bio:      auth.user?.bio || '',
    phone:    auth.user?.phone || '',
  })

  const avatarUrl     = ref(auth.user?.avatar_url || null)
  const avatarFile    = ref(null)
  const uploadingAvatar = ref(false)

  async function loadProfile() {
    loading.value = true
    try {
      const { data } = await accountApi.show()
      form.name     = data.name || ''
      form.username = data.username || ''
      form.email    = data.email || ''
      form.bio      = data.bio || ''
      form.phone    = data.phone || ''
      avatarUrl.value = data.avatar_url || null
      auth.setUser(data)
    } finally {
      loading.value = false
    }
  }

  async function saveProfile() {
    saving.value = true
    error.value  = null
    try {
      const { data } = await accountApi.updateProfile({
        name:     form.name,
        username: form.username,
        email:    form.email,
        bio:      form.bio || null,
        phone:    form.phone || null,
      })
      auth.setUser(data.user)
      toast.success(t('account.profileSaved'))
    } catch (e) {
      error.value = e.response?.data?.message
        ?? Object.values(e.response?.data?.errors ?? {}).flat()[0]
        ?? t('common.error')
    } finally {
      saving.value = false
    }
  }

  async function uploadAvatar(file) {
    uploadingAvatar.value = true
    try {
      const { data } = await accountApi.uploadAvatar(file)
      avatarUrl.value = data.avatar_url
      await auth.refreshUser()
      toast.success(t('account.profileSaved'))
    } catch (e) {
      toast.error(e.response?.data?.message ?? t('common.error'))
    } finally {
      uploadingAvatar.value = false
    }
  }

  async function removeAvatar() {
    try {
      await accountApi.removeAvatar()
      avatarUrl.value = null
      await auth.refreshUser()
    } catch (e) {
      toast.error(e.response?.data?.message ?? t('common.error'))
    }
  }

  return {
    loading, saving, error, form,
    avatarUrl, avatarFile, uploadingAvatar,
    loadProfile, saveProfile, uploadAvatar, removeAvatar,
  }
}
