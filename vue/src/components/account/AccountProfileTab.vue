<script setup>
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAccountProfile } from '@/composables/useAccountProfile'
import { useAccountSecurity } from '@/composables/useAccountSecurity'
import { UserCheck, Shield, Monitor } from 'lucide-vue-next'
import PasswordChangeCard from './PasswordChangeCard.vue'
import TwoFactorCard from './TwoFactorCard.vue'
import ActiveSessionsCard from './ActiveSessionsCard.vue'
import AccountDangerCard from './AccountDangerCard.vue'

const { t } = useI18n()
const { loading, saving, error, form, loadProfile, saveProfile } = useAccountProfile()
const security = useAccountSecurity()

const sessionCount = computed(() => security.sessions.value?.length ?? 0)
const is2FAEnabled = computed(() => security.twoFactorEnabled.value)

onMounted(() => {
  loadProfile()
  security.loadSessions()
})
</script>

<template>
  <div v-if="loading" class="py-16 text-center text-gray-400 text-sm">{{ t('common.loading') }}</div>

  <div v-else class="space-y-8">
    <!-- Stats ribbon -->
    <div class="grid grid-cols-3 gap-4">
      <div class="rounded-xl border border-gray-800 bg-gray-900/80 p-4 relative overflow-hidden">
        <div class="absolute inset-x-0 top-0 h-px" style="background:linear-gradient(90deg,rgb(var(--brand-500)),rgb(var(--brand-700)))" />
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0" style="background:rgb(var(--brand-500)/0.1)">
            <UserCheck class="w-5 h-5" style="color:rgb(var(--brand-500))" />
          </div>
          <div>
            <p class="text-[11px] text-gray-500 font-medium uppercase tracking-wider">{{ t('account.statAccount') }}</p>
            <p class="text-sm font-semibold text-green-400">{{ t('account.statActive') }}</p>
          </div>
        </div>
      </div>
      <div class="rounded-xl border border-gray-800 bg-gray-900/80 p-4 relative overflow-hidden">
        <div class="absolute inset-x-0 top-0 h-px" style="background:linear-gradient(90deg,rgb(var(--brand-500)),rgb(var(--brand-700)))" />
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0" style="background:rgb(var(--brand-500)/0.1)">
            <Monitor class="w-5 h-5" style="color:rgb(var(--brand-500))" />
          </div>
          <div>
            <p class="text-[11px] text-gray-500 font-medium uppercase tracking-wider">{{ t('account.statSessions') }}</p>
            <p class="text-xl font-bold text-gray-100 font-mono leading-tight">{{ sessionCount }}</p>
          </div>
        </div>
      </div>
      <div class="rounded-xl border border-gray-800 bg-gray-900/80 p-4 relative overflow-hidden">
        <div class="absolute inset-x-0 top-0 h-px" style="background:linear-gradient(90deg,rgb(var(--brand-500)),rgb(var(--brand-700)))" />
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0" style="background:rgb(var(--brand-500)/0.1)">
            <Shield class="w-5 h-5" style="color:rgb(var(--brand-500))" />
          </div>
          <div>
            <p class="text-[11px] text-gray-500 font-medium uppercase tracking-wider">{{ t('account.stat2FA') }}</p>
            <p class="text-sm font-semibold" :class="is2FAEnabled ? 'text-green-400' : 'text-yellow-400'">
              {{ is2FAEnabled ? t('account.twoFactorEnabled') : t('account.twoFactorDisabled') }}
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- Profile section -->
    <div>
      <div class="flex items-center gap-3 mb-5">
        <div class="w-1 h-8 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
        <h3 class="text-base font-semibold text-gray-100">{{ t('account.profileTitle') }}</h3>
      </div>

      <div class="grid grid-cols-5 gap-6 items-stretch">
        <div class="col-span-2 flex flex-col gap-4">
          <div class="card p-6 space-y-5 flex-1" data-tour="account-name-fields">
            <div>
              <label class="label">{{ t('account.name') }}</label>
              <input v-model="form.name" class="input" />
            </div>
            <div>
              <label class="label">{{ t('account.username') }}</label>
              <input v-model="form.username" class="input font-mono" />
            </div>
            <div>
              <label class="label">{{ t('account.email') }}</label>
              <input v-model="form.email" type="email" class="input" />
            </div>
          </div>
          <div data-tour="account-save-profile">
            <p v-if="error" class="text-xs text-red-400 mb-3">{{ error }}</p>
            <button class="btn-primary w-full justify-center text-xs" :disabled="saving" @click="saveProfile">
              {{ saving ? t('common.saving') : t('common.save') }}
            </button>
          </div>
        </div>

        <div class="col-span-3 card p-6 space-y-5" data-tour="account-bio">
          <div>
            <label class="label">{{ t('account.bio') }}</label>
            <textarea v-model="form.bio" rows="5" class="input resize-none" :placeholder="t('account.bioPlaceholder')"></textarea>
          </div>
          <div>
            <label class="label">{{ t('account.phone') }}</label>
            <input v-model="form.phone" type="tel" class="input" :placeholder="t('account.phonePlaceholder')" />
          </div>
        </div>
      </div>
    </div>

    <!-- Security section -->
    <div>
      <div class="flex items-center gap-3 mb-5">
        <div class="w-1 h-8 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
        <h3 class="text-base font-semibold text-gray-100">{{ t('account.securityTitle') }}</h3>
      </div>

      <div class="grid grid-cols-5 gap-6 items-stretch">
        <div class="col-span-2 flex flex-col gap-4">
          <div data-tour="account-password">
            <PasswordChangeCard
              :saving="security.pwSaving.value"
              :error="security.pwError.value"
              @change="security.changePassword"
            />
          </div>
          <div data-tour="account-2fa" class="flex-1 [&>.card]:h-full">
            <TwoFactorCard
              :enabled="security.twoFactorEnabled.value"
              :setup-data="security.setupData.value"
              :recovery-codes="security.recoveryCodes.value"
              :saving="security.tfSaving.value"
              @setup="security.setup2FA"
              @confirm="security.confirm2FA"
              @disable="security.disable2FA"
              @regenerate="security.regenerateCodes"
            />
          </div>
        </div>

        <div class="col-span-3 flex flex-col gap-4">
          <div data-tour="account-sessions">
            <ActiveSessionsCard
              :sessions="security.sessions.value"
              :loading="security.sessionsLoading.value"
              @revoke="security.revokeSession"
              @revoke-all="security.revokeAllSessions"
            />
          </div>
          <div data-tour="account-danger" class="flex-1 [&>.card]:h-full">
            <AccountDangerCard />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
