// Boyutlandırma önerileri (docs/monitoring.md#rightsizing): proje sayfasındaki kart ve
// organizasyonun listesi (/rightsizing).
export default {
  rightsizing: {
    title:        'Boyutlandırma',
    link:         '{count} boyut önerisi',
    linkSaving:   '{count} boyut önerisi · aylık {amount}',

    titleDown:    'Bu proje daha küçük çalışabilir',
    titleUp:      'Bu projenin daha fazla kaynağa ihtiyacı var',
    titleMixed:   'Bu projenin boyutu değişmeli',

    memoryDown:   'Bellek: {current} MB istiyor ama son {days} günde en fazla {used} MB kullandı → {recommended} MB yeterli.',
    memoryUp:     'Bellek: {current} MB az ({why}) → {recommended} MB.',
    cpuDown:      'CPU: {current}m istiyor ama son {days} günde {used}m kullandı (95. yüzdelik) → {recommended}m yeterli.',
    cpuUp:        'CPU: {current}m az ({why}) → {recommended}m.',

    saving:       'ayda yaklaşık {amount} daha az',
    cost:         'ayda yaklaşık {amount} daha fazla',
    usageBasis:   'kullanıma göre faturalanır: para değil kapasite boşaltır',
    fixedBasis:   'tahsis {resources} küçülebilir (ayda yaklaşık {amount})',
    fixedGrow:    'tahsis {resources} büyümeli (ayda yaklaşık {amount})',
    frees:        '{resources} boşaltır',
    takes:        '{resources} daha fazla kullanır',
    perPods:      '× {pods} pod',

    apply:        'Uygula',
    snooze:       '30 gün ertele',
    dismiss:      'Kapat',
    showEvidence: 'Kanıt',
    hideEvidence: 'Kanıtı gizle',

    applied:      '{version} olarak kaydedildi. Yeni boyutla çalışması için projeyi deploy edin.',
    pendingDeploy: 'Önerilen boyut kaydedildi. Çalışması için projeyi deploy edin.',
    snoozed:      '30 gün boyunca gizlendi.',
    dismissed:    'Kapatıldı. Yalnızca önerilen boyut değişirse yeniden gösterilir.',
    blocked:      'Şu anda uygulanırsa reddedilir: {message}',
    confirmApply: '{project} {current} yerine {recommended} olsun mu? Değişiklik yeni bir sürüm olarak kaydedilir; bir sonraki deploy onu çalıştırır.',

    reasons: {
      memory_over_provisioned: 'kullanılmayan bellek',
      cpu_over_provisioned:    'kullanılmayan CPU',
      memory_oom_killed:       'bellek yetmedi (OOM)',
      memory_near_limit:       'bellek sınıra yakın',
      cpu_throttled:           'CPU kısıtlandı',
    },

    evidence: {
      window:      'Dönem',
      windowValue: '{from} – {to}: ölçüm olan {days} gün, {sessions} oturum',
      memoryMax:   'Bellek, en yüksek',
      memoryP95:   'Bellek, 95. yüzdelik',
      cpuMax:      'CPU, en yüksek',
      cpuP95:      'CPU, 95. yüzdelik',
      oomKills:    'OOM sonlandırmaları',
      throttled:   'CPU kısıtlaması',
      throttledValue: 'periyotların yüzde {percent} kadarı',
      rule:        'Bellek = en yüksek × {memoryMargin}, CPU = 95. yüzdelik × {cpuMargin}, bir sonraki boyuta yukarı yuvarlanır.',
    },

    page: {
      title:      'Boyutlandırma önerileri',
      subtitle:   'Boyutu, pod\'larının son günlerde kullandığından farklı olan projeler. Her gece yeniden hesaplanır.',
      empty:      'Açık öneri yok: çalışan projelerin boyutu uygun ya da RunR kullanımlarını hâlâ topluyor.',
      project:    'Proje',
      now:        'Şu an',
      recommended: 'Önerilen',
      why:        'Neden',
      frees:      'Boşaltır',
      monthly:    'Aylık',
      summary:    '{count} öneri · toplam ayda yaklaşık {amount}',
      summaryResources: '{count} öneri · {memory} MB bellek ve {cpu}m CPU boşaltır',
    },
  },
}
