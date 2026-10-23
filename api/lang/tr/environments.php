<?php

// Proje ortamları ve terfi (docs/environments.md), yapılandırmasıyla geri alma (docs/versioning.md)
return [
    'unknown' => 'Projenin ":name" adında bir ortamı yok.',
    'deleted' => ':name ortamı silindi.',
    'name_invalid' => 'Ortam adı küçük harfli bir DNS etiketidir: harf, rakam ve tire; harfle başlar, en fazla :max karakter.',
    'name_reserved' => '":name" ayrılmış bir addır: projenin kendi iş yükü, önizlemeleri ve kaynakları kullanır.',
    'name_taken' => 'Projenin ":name" adında bir ortamı zaten var.',
    'workload_taken' => ':workload iş yükü adını başka bir proje ya da ortam kullanıyor; başka bir ad seçin.',
    'project_without_region' => 'Projenin bölgesi yok: ortama bir bölge verin.',
    'region_unavailable' => 'Bu bölge ortam çalıştıramaz.',
    'region_live' => 'Ortamda çalışan bir dağıtım var: başka bir bölgeye taşımadan önce onu (ya da ortamı) silin.',
    'region_endpoints' => 'Ortamın bölgesinde bir gateway route\'u ya da özel veritabanı bağlantıları var: başka bir bölgeye taşımadan önce onları kaldırın.',
    'primary_not_deletable' => 'Production ortamı projenin kendisidir: bunun yerine projeyi silin.',
    'primary_secrets' => 'Production ortamının sırları projenin gizli değişkenleridir.',
    'branch_invalid' => 'Bu geçerli bir dal adı değil.',
    'branch_is_production' => ':branch dalı production\'a dağıtır (projenin dağıtım dalı).',
    'branch_taken' => ':branch dalı zaten :environment ortamına dağıtıyor.',
    'capacity' => 'Ortam bölgesine sığmıyor: :problems',
    'quota' => 'Kuruluş kotasında ortam için yer kalmadı: :message',
    'promote_same' => 'Bir ortam kendisine terfi ettirilemez.',
    'promote_nothing_live' => ':environment ortamında çalışan bir şey yok: önce oraya bir sürüm dağıtın.',
    'promote_last' => ':environment hattın son ortamı: terfi edilecek ortamı belirtin.',
    'promote_image_changed' => 'v:version terfi istendikten sonra yeniden derlendi: yeni imajı dağıtmak için terfiyi yeniden isteyin.',
    'self_approval' => 'Bir terfiyi, onu isteyenden başka biri onaylar.',
    'promotion_not_open' => 'Terfi :status durumunda: artık değiştirilemez.',
    'rollback_version_gone' => 'O dağıtımın sürümü artık yok.',
];
