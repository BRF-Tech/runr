<?php

// Runtime yamaları ve kullanım ömrü hatırlatmaları (docs/runtimes.md#runtime-patches)
return [
    'levels' => [
        'routine' => 'Rutin',
        'security' => 'Güvenlik',
        'critical' => 'Kritik',
        'zero-day' => 'Sıfırıncı gün',
    ],

    'applying' => 'Canlı sürüm :tag yaması üzerine yeniden dağıtılıyor.',
    'queued' => 'v:version, :tag runtime yaması üzerine yeniden derleniyor; derleme hazır olunca dağıtılır.',
    'queued_rollback' => 'v:version, daha önce çalıştığı runtime imajı üzerine yeniden derleniyor (başarısız bir yama geçişinin geri alınması).',

    'errors' => [
        'not_applicable' => 'Runtime yamaları yalnızca bir RunR runtime\'ı üzerindeki kod projelerine uygulanır.',
        'project_stopped' => 'Proje durdurulmuş: önce devam ettirin.',
        'no_patch' => 'Bu runtime sürümü için bir runtime yaması yok.',
        'not_deployed' => 'Projenin canlıda bir dağıtımı yok: bir sonraki dağıtım en yeni yamayı (ya da sabitlenen yamayı) kullanır.',
        'already_running' => 'Proje zaten :tag üzerinde çalışıyor.',
        'migration_running' => 'Projenin bir yama geçişi hâlâ sürüyor.',
        'not_pinnable' => 'Bir proje yalnızca runtime sürümünün en yeni :keep yamasından birine sabitlenebilir.',
        'no_deadline' => 'Yalnızca kritik ve sıfırıncı gün yamalarının son tarihi vardır.',
    ],

    'notify' => [
        'open' => 'RunR\'da aç',
        'patch_title' => ':runtime için :level runtime yaması :tag',
        'patch_title_admin' => '{1} :level runtime yaması :tag: :count proje geride|[2,*] :level runtime yaması :tag: :count proje geride',
        'patch_body' => ":reason\nCVE'ler: :cves\nProjeler: :projects",
        'patch_deadline' => 'Son tarih: :deadline — o zamana kadar geride kalan projeler yamanın üzerine yeniden dağıtılır (yeni revizyon hazır olmazsa geri alınır).',
        'migrated_title' => ':project artık :tag üzerinde çalışıyor',
        'migrated_body' => 'RunR, yamanın son tarihinde :project projesini :tag runtime yamasına (:runtime) yeniden dağıttı.',
        'migration_failed_title' => ':tag yaması :project projesine uygulanamadı',
        'migration_failed_body' => ':project projesinin :tag runtime yamasına (:runtime) yeniden dağıtımı başarısız oldu: :reason',
        'migration_rolled_back_body' => ':project projesinin :tag runtime yamasına (:runtime) yeniden dağıtımı başarısız oldu ve daha önce çalıştığı runtime imajına geri alındı; sabitlemeyi kaldırana kadar proje orada sabit kalır. Neden: :reason',
        'rollback_failed' => 'Geri alma da başlatılamadı: :error',
        'pin_dropped_title' => ':project projesinin sabitlendiği runtime yaması artık yok',
        'pin_dropped_body' => ':project, runtime sürümünün en yeni yamaları arasında artık bulunmayan :pin yamasına sabitlenmişti. Sabitleme kaldırıldı; proje artık :tag üzerinde çalışıyor.',
        'feed_rejected_title' => 'Runtime yama akışı reddedildi (:status)',
        'feed_rejected_body' => ":feed runtime yama akışı reddedildi ve yok sayıldı; RunR son geçerli akışı kullanmaya devam ediyor.\n:error",
        'eol_title' => '{1} :runtime bir hafta içinde kullanım ömrünün sonuna geliyor|[2,*] :runtime :weeks hafta içinde kullanım ömrünün sonuna geliyor',
        'eol_body' => '{1} :runtime :date tarihinde (bir hafta içinde) kullanım ömrünün sonuna geliyor: üzerindeki projeler o tarihten sonra dağıtılamaz. Daha yeni bir sürüme taşıyın: :projects|[2,*] :runtime :date tarihinde (:weeks hafta içinde) kullanım ömrünün sonuna geliyor: üzerindeki projeler o tarihten sonra dağıtılamaz. Daha yeni bir sürüme taşıyın: :projects',
        'alert_resolved' => 'Kuruluşta artık :tag yamasının gerisinde kalan proje yok.',
    ],
];
