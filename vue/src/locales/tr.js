import common from './tr/common'
import projects from './tr/projects'
import projectForms from './tr/projectForms'
import settings from './tr/settings'
import regionDetail from './tr/regionDetail'
import tutorial from './tr/tutorial'
import notificationsLocale from './tr/notifications'
import accountLocale from './tr/account'
import aiProjects from './tr/aiProjects'
import security from './tr/security'
import runtimesLocale from './tr/runtimes'
import wasmLocale from './tr/wasm'
import rbac from './tr/rbac'
import bindingsLocale from './tr/bindings'
import workflowsLocale from './tr/workflows'
import aiLocale from './tr/ai'
import gitDeploy from './tr/git'
import mcpLocale from './tr/mcp'

export default {
  ...common,
  ...workflowsLocale,
  ...gitDeploy,
  ...projects,
  ...projectForms,
  ...tutorial,
  ...notificationsLocale,
  ...accountLocale,
  ...aiProjects,
  ...security,
  ...runtimesLocale,
  ...wasmLocale,
  ...rbac,
  // ai: the retired-type notice + GPU / Models screens
  ai: { ...aiProjects.ai, ...aiLocale.ai },
  ...bindingsLocale,
  ...mcpLocale,
  settings: {
    ...settings.settings,
    ...regionDetail.settings,
  },
  tenancy: {
    regionMode_managed:  'Yönetilen',
    regionMode_infinite: 'Sınırsız',
    dnsInstructions:     'DNS sağlayıcınıza aşağıdaki CNAME kayıtlarını ekleyin:',
  },
  users: {
    tab:               'Kullanıcılar',
    title:             'Kullanıcı Yönetimi',
    searchPlaceholder: 'Ad veya e-posta ile ara...',
    addBtn:            '+ Kullanıcı Ekle',
    addTitle:          'Yeni Kullanıcı',
    name:              'Ad',
    username:          'Kullanıcı Adı',
    email:             'E-posta',
    password:          'Şifre',
    noUsers:           'Kullanıcı bulunamadı.',
    created:           'Kullanıcı oluşturuldu.',
    updated:           'Kullanıcı güncellendi.',
    deleted:           'Kullanıcı silindi.',
    deleteTitle:       'Kullanıcıyı Sil',
    deleteConfirm:     '"{name}" kullanıcısını silmek istediğinize emin misiniz?',
    reset2FA:          '2FA Sıfırla',
    reset2FATitle:     'İki Faktörlü Doğrulamayı Sıfırla',
    reset2FAConfirm:   '"{name}" için 2FA\'yı sıfırlamak istediğinize emin misiniz? Tekrar kurması gerekecektir.',
    reset2FASuccess:   '2FA sıfırlandı.',
  },
  tokens: {
    tab:            'API Token',
    title:          'API Token Yönetimi',
    createTitle:    'Yeni Token Oluştur',
    createBtn:      'Token Oluştur',
    name:           'Token Adı',
    namePlaceholder:'Ör: CI/CD, Mobil Uygulama',
    expiresAt:      'Süre',
    expiresOn:      '{date} tarihinde sona erer',
    abilities:      'İzinler',
    fullAccess:     'Tam Erişim',
    customAccess:   'Özel',
    noTokens:       'Henüz token oluşturulmamış.',
    createdBanner:  'Token oluşturuldu! Bu değeri bir daha göremezsiniz.',
    createdWarning: 'Bu token sadece bir kez gösterilir. Güvenli bir yere kaydedin.',
    deleteTitle:    'Token Sil',
    deleteConfirm:  '"{name}" tokenını silmek istediğinize emin misiniz?',
    created:        'Oluşturulma',
    expires:        'Bitiş',
    lastUsed:       'Son kullanım',
    expired:        'Süresi doldu',
    noExpiry:       'Süresiz',
    duration_30d:   '30 gün',
    duration_90d:   '90 gün',
    duration_180d:  '6 ay',
    duration_1y:    '1 yıl',
    justNow:        'Az önce',
    minutesAgo:     '{n} dk önce',
    hoursAgo:       '{n}sa önce',
    daysAgo:        '{n}g önce',
    createdBy:    '{name} tarafından',
    ability_projects_read:    'Projeleri Görüntüle',
    ability_projects_write:   'Projeleri Düzenle',
    ability_deployments_read: 'Deployment Görüntüle',
    ability_deployments_write:'Deploy Et',
    ability_settings_read:    'Ayarları Görüntüle',
    ability_settings_write:   'Ayarları Düzenle',
  },
  metrics: {
    range:         'Aralık',
    avgCpu:        'Ort. CPU',
    avgMemory:     'Ort. Bellek',
    avgRps:        'Ort. RPS',
    totalSessions: 'Toplam Oturum',
    cpuCores:      'CPU (çekirdek)',
    memoryMb:      'Bellek (MB)',
    rps:           'İstek/sn',
    noData:        'Bu dönem için metrik verisi yok',
    history:       'Metrik Geçmişi',
  },
}
