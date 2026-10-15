<?php

// Sandbox API mesajları (docs/sandbox-api.md)
return [
    'not_found' => 'Sandbox bulunamadı.',
    'not_running' => 'Sandbox durumu: :status.',
    'cluster_error' => 'Küme isteği reddetti: :detail',
    'tenant_gone' => 'Organizasyon artık mevcut değil.',

    'region_unknown' => '":region" bölgesi sandbox için kullanılamıyor.',
    'region_disabled' => ':region bölgesinde sandbox kapalı.',
    'no_region' => 'Sandbox açık bir bölge yok.',
    'region_gone' => 'Sandbox\'ın bölgesi artık kullanılamıyor.',

    'no_class' => ':region bölgesinde kullanılabilir bir sandbox sınıfı yok: oraya gVisor ya da Kata Containers kurun veya bölgenin sandbox ayarlarında runc\'ye izin verin.',
    'class_not_allowed' => '":class" sandbox sınıfına burada izin verilmiyor. İzin verilenler: :allowed.',
    'runc_not_allowed' => 'runc yalnızca bölge runc\'ye izin verdiğinde varsayılan sınıf olabilir.',

    'custom_images_disabled' => 'Özel imajlar kapalı; bir runtime seçin.',
    'version_unknown' => ':runtime runtime\'ının ":version" sürümü yok.',
    'cpu_invalid' => 'cpu "500m", 0.5 ya da "1" biçiminde olmalı.',
    'memory_invalid' => 'memory MiB (512) ya da "512Mi" / "1Gi" gibi bir miktar olmalı.',
    'disk_invalid' => 'disk MiB (1024) ya da "1Gi" gibi bir miktar olmalı.',
    'cpu_range' => 'cpu 50m ile :maxm arasında olmalı.',
    'memory_range' => 'memory 64 ile :max MiB arasında olmalı.',
    'disk_range' => 'disk 64 ile :max MiB arasında olmalı.',
    'ttl_range' => 'ttl_seconds 10 ile :max arasında olmalı.',
    'env_key_invalid' => '":key" geçerli bir ortam değişkeni adı değil.',
    'base64_invalid' => 'İçerik geçerli base64 değil.',
    'files_too_large' => 'Dosyalar toplamda :max bayttan büyük.',
    'files_failed' => 'Sandbox dosyaları yazılamadı: :detail',

    'network_invalid' => 'network "none" ya da {"egress_allow": [...]} olmalı.',
    'network_entry_invalid' => '":entry" çıkış kuralı bir adres, CIDR ya da (isteğe bağlı portlu) host adı değil.',
    'network_unresolvable' => ':host host adı çözümlenemiyor.',
    'network_denied_range' => '":entry" çıkış kuralı sandbox\'ların asla erişemediği bir aralık (bulut metadata).',
    'network_too_many' => 'En fazla :max çıkış kuralına izin verilir.',

    'quota_concurrent' => 'Organizasyon zaten en fazla :max sandbox çalıştırıyor.',
    'quota_cpu' => 'Organizasyonun sandbox CPU kotası (:maxm, kullanımda :usedm) doldu.',
    'quota_memory' => 'Organizasyonun sandbox bellek kotası (:max MiB, kullanımda :used MiB) doldu.',
    'quota_seconds' => 'Organizasyon bu ayın :max sandbox saniyesini kullandı.',
    'quota_seconds_reached' => 'Durduruldu: organizasyon bu ayın sandbox saniyelerini kullandı.',
    'quota_region' => 'Organizasyonun bu bölgedeki kotası doldu.',
    'quota_pool' => 'Organizasyonun sıcak havuzları toplamda en fazla :max sandbox tutabilir.',

    'namespace_failed' => ':namespace sandbox namespace\'i hazırlanamadı.',
    'policy_failed' => ':namespace namespace\'inin ağ politikası uygulanamadı.',
    'secret_failed' => 'Sandbox\'ın env Secret\'ı :namespace namespace\'ine yazılamadı.',
    'pod_failed' => 'Sandbox pod\'u oluşturulamadı.',
    'pod_gone' => 'Sandbox pod\'u artık yok.',
    'start_timeout' => 'Sandbox zamanında başlamadı.',
    'start_failed' => 'Sandbox başlatılamadı: :error',

    'exec_cmd_required' => 'cmd boş olmayan bir metin ya da argüman listesi olmalı.',
    'language_unknown' => '":language" dili desteklenmiyor. Desteklenenler: :known ya da exec komutu olan bir runtime.',
    'ws_code_too_large' => 'Exec WebSocket üzerinden gönderilen kod 120 KB ile sınırlı; daha büyük kod için POST /run kullanın.',
    'ws_missing_token' => 'Token eksik.',
    'ws_session_expired' => 'Exec oturumu bulunamadı ya da süresi doldu.',
    'ws_start_failed' => 'Komut başlatılamadı.',

    'path_invalid' => '":path" yolu mutlak olmalı.',
    'path_root' => 'Kök dizin silinemez.',
    'mode_invalid' => 'mode "0644" gibi sekizlik bir dosya modu olmalı.',
    'file_too_large' => 'Dosya :max baytlık sınırdan büyük.',
    'file_op_failed' => ':path üzerindeki dosya işlemi başarısız oldu.',

    'process_start_failed' => 'Süreç başlatılamadı: :detail',
    'process_unknown' => 'Süreç bulunamadı.',
    'signal_invalid' => 'signal şunlardan biri olmalı: :signals.',

    'pool_unknown' => 'Sıcak havuz bulunamadı.',
    'pool_size_exceeds' => 'Havuzun sandbox boyutu bölgenin sandbox başına sınırlarının üstünde.',
    'pool_start_timeout' => 'Sıcak bir sandbox zamanında başlamadı.',

    'platform_admin_required' => 'Bunu yalnızca platform yöneticileri değiştirebilir.',
];
