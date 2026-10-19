<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ShieldCheck } from 'lucide-vue-next'
import { useAuthStore } from '@/stores/auth'
import { authApi } from '@/api/auth'
import client from '@/api/client'
import { moduleRegistry } from '@/modules/index'
import RegisterForm from '@/components/RegisterForm.vue'
import RunrWordmark from '@/components/RunrWordmark.vue'
import { useBrandingStore } from '@/modules/white-label/stores/branding'

const { t, locale } = useI18n()

// White-label branding (platform, or the tenant's own override on the tenant sign-in)
const branding = useBrandingStore()
const brandHeadline = computed(() => branding.data.login_headline)
const footerLinks = computed(() => branding.data.footer_links ?? [])
// RunR's own brand: "RunR by <company>"; re-branded: "© <year> <company>" (+ "Powered by RunR")
const year = new Date().getFullYear()
const poweredBy = computed(() => branding.data.powered_by && !branding.isDefaultName)
function toggleLocale() { locale.value = locale.value === 'tr' ? 'en' : 'tr'; localStorage.setItem('locale', locale.value) }

const router = useRouter()
const route  = useRoute()
const auth   = useAuthStore()

// State
const mode = ref('login')
const registrationEnabled = ref(false)
const login    = ref('')
const password = ref('')
const loading  = ref(false)
const error    = ref(null)
const showPw   = ref(false)

// 2FA
const needs2FA      = ref(false)
const twoFactorCode = ref('')

// Tenancy sign-in (tenant users)
const tenancyEnabled = import.meta.env.VITE_TENANCY_ENABLED === 'true'
const userType = ref('platform')
const tenancySlug    = ref('')
const tenancyStep  = ref(1)

// OAuth providers
const providers = ref([])

// Carousel
const slideIndex = ref(0)
const slides = computed(() => [
  { tagline: t('auth.slide1Tagline'), desc: t('auth.slide1Desc') },
  { tagline: t('auth.slide2Tagline'), desc: t('auth.slide2Desc') },
  { tagline: t('auth.slide3Tagline'), desc: t('auth.slide3Desc') },
])

let timer = null
onMounted(async () => {
  timer = setInterval(() => { slideIndex.value = (slideIndex.value + 1) % 3 }, 4000)
  try { const { data } = await authApi.registrationStatus(); registrationEnabled.value = data.enabled } catch {}
  if (route.query.oauth_error) error.value = route.query.oauth_error
  discoverProviders()
})
onUnmounted(() => clearInterval(timer))

async function discoverProviders(tenantSlug) {
  try {
    const params = tenantSlug ? { tenant: tenantSlug } : {}
    const { data } = await client.get('/auth-providers/discover', { params })
    providers.value = data
  } catch { providers.value = [] }
}

// Tenant user sign-in flow
const tenantName = ref('')
async function continueTenancy() {
  const slug = tenancySlug.value.trim()
  if (!slug) return
  error.value = null
  loading.value = true
  try {
    const { data } = await client.get(`/tenant/check/${slug}`)
    tenantName.value = data.name
    discoverProviders(slug)
    branding.load(slug)
    tenancyStep.value = 2
  } catch {
    error.value = t('auth.tenancyNotFound')
  } finally { loading.value = false }
}
function backToTenancy() { tenancyStep.value = 1; providers.value = []; error.value = null; discoverProviders(); if (branding.tenantSlug) branding.load(null) }

watch(userType, (val) => {
  tenancyStep.value = 1; providers.value = []; error.value = null
  if (val === 'platform') { discoverProviders(); if (branding.tenantSlug) branding.load(null) }
})

const showLoginForm = computed(() => userType.value === 'platform' || tenancyStep.value === 2)
const showTenancyStep = computed(() => tenancyEnabled && userType.value === 'tenant' && tenancyStep.value === 1)

async function submit() {
  error.value = null; loading.value = true
  try {
    const tenantSlug = (userType.value === 'tenant' && tenancyStep.value === 2) ? tenancySlug.value.trim() : null
    const code = needs2FA.value ? twoFactorCode.value : null
    const data = await auth.login(login.value, password.value, tenantSlug, code)

    if (data.requires_2fa) {
      needs2FA.value = true
      return
    }

    for (const hook of moduleRegistry.loginHooks) await hook(data)
    router.push(route.query.redirect || '/projects')
  } catch (e) { error.value = e.response?.data?.message ?? t('auth.error') }
  finally { loading.value = false }
}

function loginWithProvider(p) {
  const slug = (userType.value === 'tenant' && tenancyStep.value === 2) ? tenancySlug.value.trim() : null
  const params = slug ? { tenant: slug } : {}
  // SAML starts at its service provider endpoint; OAuth / OIDC name the provider (several per driver)
  const start = p.driver === 'saml'
    ? client.get(`/saml/${p.id}/login`, { params })
    : client.get(`/oauth/${p.driver}/redirect`, { params: { ...params, provider: p.id } })
  start
    .then(({ data }) => { window.location.href = data.redirect_url })
    .catch((e) => { error.value = e.response?.data?.message ?? t('auth.oauthError') })
}
</script>

<template>
  <div class="login-bg relative min-h-screen overflow-hidden">
    <div class="login-orb login-orb-1" aria-hidden="true"></div>
    <div class="login-orb login-orb-2" aria-hidden="true"></div>
    <div class="login-orb login-orb-3" aria-hidden="true"></div>

    <!-- Language Toggle -->
    <button type="button" class="absolute top-5 right-5 z-20 w-10 h-10 flex items-center justify-center rounded-md transition-colors hover:bg-white/20" @click="toggleLocale">
      <span :class="locale === 'tr' ? 'fi fi-tr' : 'fi fi-gb'" style="display:block;width:1.4rem;height:1.4rem;background-size:cover;background-position:50% 50%;border-radius:50%" />
    </button>

    <div class="relative z-10 grid md:grid-cols-2 min-h-screen">
      <!-- Left: Branding + Carousel -->
      <div class="hidden md:flex flex-col justify-center pl-40 pr-8 pb-20 text-white sticky top-0 h-screen">
        <div class="mb-14" data-test="login-brand"><RunrWordmark size="lg" force-white /></div>
        <!-- Branded login text replaces the carousel -->
        <div v-if="brandHeadline" class="min-h-40" data-test="login-headline">
          <h1 class="text-6xl font-bold mb-5 leading-tight">{{ brandHeadline }}</h1>
          <p v-if="branding.data.login_text" class="text-blue-200 text-lg leading-relaxed max-w-md whitespace-pre-line">{{ branding.data.login_text }}</p>
        </div>
        <template v-else>
        <Transition name="slide-fade" mode="out-in">
          <div :key="slideIndex" class="min-h-40">
            <h1 class="text-6xl font-bold mb-5 leading-tight">{{ slides[slideIndex].tagline }}</h1>
            <p class="text-blue-200 text-lg leading-relaxed max-w-md">{{ slides[slideIndex].desc }}</p>
          </div>
        </Transition>
        <div class="flex gap-3 mt-10">
          <button v-for="i in 3" :key="i" type="button" class="rounded-full transition-all duration-500"
            :class="slideIndex === i - 1 ? 'w-16 h-4 bg-blue-400' : 'w-14 h-3.5 bg-white/30'" @click="slideIndex = i - 1" />
        </div>
        </template>
      </div>

      <!-- Right: Card -->
      <div class="flex justify-start h-screen overflow-y-auto" :style="{ paddingTop: mode === 'register' ? '8vh' : '22vh' }">
        <div class="login-card w-full rounded-t-2xl flex flex-col px-14 pt-12 pb-20"
          :class="providers.length && showLoginForm ? 'max-w-3xl' : 'max-w-2xl'">

          <!-- ===== REGISTER ===== -->
          <RegisterForm v-if="mode === 'register'" @back="mode = 'login'" />

          <!-- ===== LOGIN ===== -->
          <template v-else>
            <!-- 2FA Step -->
            <template v-if="needs2FA">
              <div class="mb-8">
                <h2 class="login-title text-4xl font-bold mb-3">{{ t('auth.twoFactorTitle') }}</h2>
                <p class="login-subtitle text-xl leading-relaxed">{{ t('auth.twoFactorDesc') }}</p>
              </div>
              <form @submit.prevent="submit" class="space-y-6">
                <div>
                  <label class="login-label block text-xs font-bold mb-2 uppercase tracking-widest">{{ t('auth.twoFactorCode') }}</label>
                  <input v-model="twoFactorCode" type="text" inputmode="numeric" maxlength="20" class="login-input w-full px-4 py-4 rounded-md text-base transition font-mono text-center tracking-widest" :placeholder="t('auth.twoFactorCode')" autocomplete="one-time-code" autofocus />
                </div>
                <p v-if="error" class="text-sm font-medium" style="color:#dc2626">{{ error }}</p>
                <button type="submit" class="login-submit w-full py-4 rounded-md text-base font-semibold text-white transition-all disabled:opacity-50" :disabled="loading || !twoFactorCode">
                  {{ loading ? t('auth.submitting') : t('auth.twoFactorSubmit') }}
                </button>
                <button type="button" class="text-sm hover:underline" style="color:rgb(var(--brand-600))" @click="needs2FA = false; twoFactorCode = ''; error = null">
                  {{ t('auth.twoFactorBack') }}
                </button>
              </form>
            </template>

            <!-- Normal Login -->
            <template v-else>
            <div class="mb-8">
              <h2 class="login-title text-4xl font-bold mb-3">{{ t('auth.loginTitle') }}</h2>
              <p class="login-subtitle text-xl leading-relaxed">{{ t('auth.loginSubtitle') }}</p>
            </div>

            <!-- User type selector (tenancy — hidden in tenant step 2) -->
            <div v-if="tenancyEnabled && !(userType === 'tenant' && tenancyStep === 2)" class="flex gap-4 mb-8">
              <button type="button" class="user-type-card flex-1 flex items-center justify-start gap-3 px-4 py-3.5 rounded-md border-2 transition-all text-left"
                :class="userType === 'platform' ? 'user-type-active' : 'user-type-inactive'" @click="userType = 'platform'">
                <span class="user-type-radio shrink-0" :class="userType === 'platform' ? 'user-type-radio-active' : 'user-type-radio-inactive'"></span>
                <span>
                  <span class="block text-sm font-semibold">{{ t('auth.platformUser') }}</span>
                  <span class="block text-xs mt-0.5 user-type-desc">{{ t('auth.platformUserDesc') }}</span>
                </span>
              </button>
              <button type="button" class="user-type-card flex-1 flex items-center gap-3 px-4 py-3.5 rounded-md border-2 transition-all text-left"
                :class="userType === 'tenant' ? 'user-type-active' : 'user-type-inactive'" @click="userType = 'tenant'">
                <span class="user-type-radio shrink-0" :class="userType === 'tenant' ? 'user-type-radio-active' : 'user-type-radio-inactive'"></span>
                <span>
                  <span class="block text-sm font-semibold">{{ t('auth.tenantUser') }}</span>
                  <span class="block text-xs mt-0.5 user-type-desc">{{ t('auth.tenantUserDesc') }}</span>
                </span>
              </button>
            </div>

            <!-- Tenant step 1: tenancy -->
            <div v-if="showTenancyStep" class="space-y-6">
              <div>
                <label class="login-label block text-xs font-bold mb-2 uppercase tracking-widest">{{ t('auth.tenancy') }}</label>
                <input v-model="tenancySlug" type="text" class="login-input w-full px-4 py-4 rounded-md text-base transition"
                  :placeholder="t('auth.tenancyHint')" autocomplete="off" @keyup.enter="continueTenancy" />
              </div>
              <p v-if="error" class="text-sm font-medium" style="color:#dc2626">{{ error }}</p>
              <button type="button" class="login-submit w-full py-4 rounded-md text-base font-semibold text-white transition-all disabled:opacity-50"
                :disabled="loading" @click="continueTenancy">
                {{ loading ? t('auth.submitting') : t('auth.continue') }}
              </button>
            </div>

            <!-- Login form (platform user, or tenant step 2) -->
            <template v-if="showLoginForm">
              <!-- Tenant back + tenancy badge -->
              <div v-if="userType === 'tenant'" class="flex items-center gap-2 mb-6">
                <button type="button" class="text-xs hover:underline" style="color:rgb(var(--brand-600))" @click="backToTenancy">{{ t('common.back') }}</button>
                <span class="text-xs px-2 py-0.5 rounded-sm" style="background:rgb(var(--brand-600) / .08);border:1px solid rgb(var(--brand-600) / .2);color:rgb(var(--brand-600))">
                  <span class="font-medium">{{ tenantName }}</span>
                  <span class="font-mono opacity-60 ml-1">{{ tenancySlug }}</span>
                </span>
              </div>

              <div class="flex gap-0">
                <!-- Form -->
                <div :class="providers.length ? 'flex-1 pr-8' : 'w-full'">
                  <form @submit.prevent="submit" class="space-y-6">
                    <div>
                      <label class="login-label block text-xs font-bold mb-2 uppercase tracking-widest">{{ t('auth.email') }}</label>
                      <input v-model="login" type="email" class="login-input w-full px-4 py-4 rounded-md text-base transition" :placeholder="t('auth.email')" autocomplete="email" required />
                    </div>
                    <div>
                      <label class="login-label block text-xs font-bold mb-2 uppercase tracking-widest">{{ t('auth.password') }}</label>
                      <div class="relative">
                        <input v-model="password" :type="showPw ? 'text' : 'password'" class="login-input w-full px-4 py-4 rounded-md text-base transition pr-12" placeholder="••••••••" autocomplete="current-password" required />
                        <button type="button" class="login-eye absolute right-4 top-1/2 -translate-y-1/2 transition-colors" tabindex="-1" @click="showPw = !showPw">
                          <svg v-if="!showPw" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                          <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" /></svg>
                        </button>
                      </div>
                    </div>
                    <p v-if="error" class="text-sm font-medium" style="color:#dc2626">{{ error }}</p>
                    <button type="submit" class="login-submit w-full py-4 rounded-md text-base font-semibold text-white transition-all disabled:opacity-50" :disabled="loading">
                      {{ loading ? t('auth.submitting') : t('auth.submit') }}
                    </button>
                  </form>
                </div>

                <!-- Provider side panel -->
                <div v-if="providers.length" class="w-56 pl-8 border-l flex flex-col justify-center gap-3" style="border-color:#e2e8f0">
                  <p class="text-xs font-bold uppercase tracking-widest mb-1" style="color:#374151">{{ t('auth.orSignInWith') }}</p>
                  <button v-for="p in providers" :key="p.id" type="button" data-testid="sso-provider"
                    class="oauth-btn flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-semibold transition-all" @click="loginWithProvider(p)">
                    <!-- Google -->
                    <svg v-if="p.driver === 'google'" class="w-5 h-5 shrink-0" viewBox="0 0 24 24">
                      <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/>
                      <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                      <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                      <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                    </svg>
                    <!-- GitHub -->
                    <svg v-else-if="p.driver === 'github'" class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="#24292f">
                      <path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/>
                    </svg>
                    <!-- Microsoft -->
                    <svg v-else-if="p.driver === 'microsoft'" class="w-5 h-5 shrink-0" viewBox="0 0 24 24">
                      <path d="M1 1h10.5v10.5H1z" fill="#F25022"/><path d="M12.5 1H23v10.5H12.5z" fill="#7FBA00"/>
                      <path d="M1 12.5h10.5V23H1z" fill="#00A4EF"/><path d="M12.5 12.5H23V23H12.5z" fill="#FFB900"/>
                    </svg>
                    <!-- GitLab -->
                    <svg v-else-if="p.driver === 'gitlab'" class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="#FC6D26">
                      <path d="m23.6004 9.5927-.0337-.0862L20.3.9814a.851.851 0 0 0-.3362-.405.8748.8748 0 0 0-.9997.0539.8748.8748 0 0 0-.29.4399l-2.2055 6.748H7.5375l-2.2057-6.748a.8573.8573 0 0 0-.29-.4412.8748.8748 0 0 0-.9997-.0537.8585.8585 0 0 0-.3362.4049L.4332 9.5015l-.0325.0862a6.0657 6.0657 0 0 0 2.0119 7.0105l.0113.0087.03.0213 4.976 3.7264 2.462 1.8633 1.4995 1.1321a1.0085 1.0085 0 0 0 1.2197 0l1.4995-1.1321 2.4619-1.8633 5.006-3.7489.0125-.01a6.0682 6.0682 0 0 0 2.0094-7.003z"/>
                    </svg>
                    <!-- SAML 2.0 -->
                    <ShieldCheck v-else-if="p.driver === 'saml'" class="w-5 h-5 shrink-0" style="color:#059669" />
                    <!-- OIDC / OpenID Connect -->
                    <svg v-else class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="#F78C40">
                      <path d="M14.54.889l-3.63 1.773v18.17c-4.15-.52-7.27-2.78-7.27-5.5 0-2.58 2.8-4.75 6.63-5.41v-2.31C4.42 8.322 0 11.502 0 15.332c0 3.96 4.74 7.24 10.91 7.78l3.63-1.71V.888m.64 6.724v2.31c1.43.25 2.71.7 3.76 1.31l-1.97 1.11 7.03 1.53-.5-5.21-1.87 1.06c-1.74-1.06-3.96-1.81-6.45-2.11z"/>
                    </svg>
                    {{ p.name }}
                  </button>
                </div>
              </div>
            </template>

            <!-- Register link -->
            <p v-if="registrationEnabled" class="text-center mt-6 text-sm" style="color:#64748b">
              {{ t('auth.registerLink') }}
              <button type="button" class="font-semibold hover:underline" style="color:rgb(var(--brand-600))" @click="mode = 'register'">{{ t('auth.registerAction') }}</button>
            </p>
            </template>
          </template>

          <!-- Branded footer: links, support, credit line -->
          <footer class="mt-auto pt-10 flex flex-wrap justify-center gap-x-4 gap-y-1 text-xs" style="color:#94a3b8" data-test="login-footer">
            <a v-for="(link, i) in footerLinks" :key="i" :href="link.url" target="_blank" rel="noopener noreferrer" class="hover:underline">{{ link.label }}</a>
            <a v-if="branding.data.support_url" :href="branding.data.support_url" target="_blank" rel="noopener noreferrer" class="hover:underline">{{ t('whiteLabel.login.support') }}</a>
            <a v-else-if="branding.data.support_email" :href="`mailto:${branding.data.support_email}`" class="hover:underline">{{ branding.data.support_email }}</a>
            <span v-if="branding.isDefaultName" data-test="login-credit">{{ t('whiteLabel.login.byCompany', { product: branding.productName, company: branding.companyName }) }}</span>
            <template v-else>
              <span data-test="login-credit">{{ t('whiteLabel.login.copyright', { year, company: branding.companyName }) }}</span>
              <span v-if="poweredBy" data-test="login-powered-by">{{ t('whiteLabel.login.poweredBy') }}</span>
            </template>
          </footer>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.login-bg {
  background-color: #030712;
  background-image:
    radial-gradient(ellipse 80% 60% at 15% 40%, rgb(var(--brand-600) / .12) 0%, transparent 65%),
    radial-gradient(ellipse 60% 50% at 85% 60%, rgb(var(--brand-900) / .09) 0%, transparent 65%),
    radial-gradient(ellipse 40% 40% at 55% 90%, rgb(var(--brand-700) / .07) 0%, transparent 55%),
    url("data:image/svg+xml,%3Csvg width='36' height='36' viewBox='0 0 36 36' xmlns='http://www.w3.org/2000/svg'%3E%3Ccircle cx='1' cy='1' r='0.9' fill='rgba(255%2C255%2C255%2C0.045)'/%3E%3C/svg%3E");
  background-size: auto, auto, auto, 36px 36px;
}
.login-orb { position: absolute; border-radius: 50%; pointer-events: none; filter: blur(100px); will-change: transform; }
.login-orb-1 { width: 50%; height: 50%; top: -10%; left: -8%; background: rgb(var(--brand-600) / .22); animation: lo1 20s ease-in-out infinite; }
.login-orb-2 { width: 42%; height: 42%; bottom: -10%; right: -8%; background: rgb(var(--brand-800) / .18); animation: lo2 25s ease-in-out infinite; }
.login-orb-3 { width: 30%; height: 30%; top: 40%; left: 40%; background: rgb(var(--brand-500) / .06); animation: lo1 35s ease-in-out infinite reverse; }
@keyframes lo1 { 0%,100% { transform: translate(0,0) scale(1); } 50% { transform: translate(5%,4%) scale(1.08); } }
@keyframes lo2 { 0%,100% { transform: translate(0,0) scale(1); } 50% { transform: translate(-5%,-4%) scale(1.06); } }
.login-card { background: #fff; box-shadow: 8px 0 40px rgba(0,0,0,.18); }
.login-card :deep(.login-title) { color: #0f172a; letter-spacing: -.5px; } .login-card :deep(.login-subtitle) { color: #64748b; line-height: 1.6; }
.login-card :deep(.login-label) { color: #374151; letter-spacing: .08em; } .login-card :deep(.login-input) { background: #f8fafc; color: #0f172a; border: 1.5px solid #e2e8f0; outline: none; }
.login-card :deep(.login-input)::placeholder { color: #cbd5e1; }
.login-card :deep(.login-input):focus { background: #fff; border-color: rgb(var(--brand-600)); box-shadow: 0 0 0 3px rgb(var(--brand-600) / .12); }
.login-card :deep(.login-eye) { color: #94a3b8; } .login-card :deep(.login-eye):hover { color: #475569; }
.user-type-active { border-color: rgb(var(--brand-600)); color: rgb(var(--brand-600)); background: rgb(var(--brand-600) / .04); }
.user-type-inactive { border-color: #e2e8f0; color: #374151; background: #f8fafc; } .user-type-inactive:hover { border-color: #cbd5e1; color: #111827; }
.user-type-radio { display: block; width: 1.1rem; height: 1.1rem; border-radius: 50%; border: 2px solid; flex-shrink: 0; }
.user-type-radio-active { border-color: rgb(var(--brand-600)); box-shadow: inset 0 0 0 3px #fff, inset 0 0 0 5px rgb(var(--brand-600)); }
.user-type-radio-inactive { border-color: #9ca3af; } .user-type-desc { color: inherit; opacity: 0.65; }
.login-card :deep(.login-submit) { background: linear-gradient(135deg,rgb(var(--brand-600)),rgb(var(--brand-700))); letter-spacing: .02em; box-shadow: 0 4px 14px rgb(var(--brand-600) / .35); }
.login-card :deep(.login-submit):hover:not(:disabled) { background: linear-gradient(135deg,rgb(var(--brand-500)),rgb(var(--brand-600))); box-shadow: 0 6px 20px rgb(var(--brand-600) / .45); transform: translateY(-1px); }
.login-card :deep(.login-submit):active:not(:disabled) { transform: translateY(0); }
.oauth-btn { background: #f8fafc; color: #1e293b; border: 1.5px solid #e2e8f0; } .oauth-btn:hover { background: #f1f5f9; border-color: #cbd5e1; transform: translateY(-1px); box-shadow: 0 2px 8px rgba(0,0,0,.06); }
.slide-fade-enter-active, .slide-fade-leave-active { transition: opacity .4s ease, transform .4s ease; }
.slide-fade-enter-from { opacity: 0; transform: translateY(14px); } .slide-fade-leave-to { opacity: 0; transform: translateY(-14px); }
</style>
