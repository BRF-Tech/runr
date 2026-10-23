<?php

return [

    // ── Deploy politika kapısı ──
    'gate' => [
        'blocked' => 'Deploy, :region bölgesinin tedarik zinciri politikası tarafından engellendi: :reasons.',
        'pending' => ':version sürümünün güvenlik kontrolleri sürüyor (:reasons). Tamamlandığında tekrar deploy edin.',
        'hint_override' => 'Bulguları düzeltip yeniden build alın ya da bir gerekçeyle politika istisnası kullanarak deploy edin.',
        'hint_fix' => 'Bulguları düzeltip yeniden build alın ya da politika istisnası yetkisi olan bir yöneticiye başvurun.',
        'critical' => ':count CRITICAL zafiyet (izin verilen: :limit)',
        'high' => ':count HIGH zafiyet (izin verilen: :limit)',
        'fixable_only' => '(yalnızca düzeltilmiş sürümü olan bulgular sayılıyor)',
        'scan_missing' => 'imajın zafiyet taraması yok',
        'scan_pending' => 'zafiyet taraması sürüyor',
        'signature_missing' => 'imaj bölge anahtarıyla imzalanmamış',
        'signature_untrusted' => 'imaj imzası bölgenin güvendiği bir anahtardan değil',
        'signature_pending' => 'imaj imzası oluşturuluyor',
        'override_reason_required' => 'Politika istisnası için bir gerekçe yazın (en az 3 karakter).',
    ],

    // ── Raporlar ──
    'report' => [
        'running' => 'Bu sürümün tedarik zinciri çalışması sürüyor.',
        'not_applicable' => 'Bu sürümün kontrol edilecek kendi imajı yok (kodu bağlanmış runtime imajında çalışıyor) ya da bölge bunu kontrol etmiyor.',
        'none' => 'Bu sürümün tedarik zinciri raporu yok.',
        'no_sbom' => 'Bu imaj için bu biçimde SBOM yok.',
        'unknown_format' => 'Bilinmeyen SBOM biçimi (spdx-json veya cyclonedx-json).',
        'wasm_skipped' => 'Bir Spin uygulamasının (WebAssembly OCI artifact) syft ve Trivy\'nin okuyacağı bir konteyner dosya sistemi yok: bölge anahtarıyla imzalanır, politika kapısı onun için tarama istemez.',
    ],

    // ── İmza anahtarı ──
    'signing' => [
        'generating' => 'Bu bölge için anahtar üretimi sürüyor.',
        'no_cluster' => 'Bölgede anahtar üretilecek bir küme yok.',
        'none' => 'Bölgenin imza anahtarı yok.',
    ],

    // ── Tarayıcı veritabanı ──
    'scanner_db' => [
        'no_registry' => 'Tarayıcı veritabanı kopyası için bölgenin bir container registry\'si olmalı.',
    ],

    // ── Admission ──
    'admission' => [
        'key_required' => 'Admission politikasını kurmadan önce bölge imza anahtarını üretin.',
        'registry_required' => 'Admission politikası bölge registry\'sine ihtiyaç duyar.',
    ],

    // ── Doğrulama ──
    'validation' => [
        'public_key' => 'Her güvenilen anahtar bir PEM açık anahtar olmalı (-----BEGIN PUBLIC KEY-----).',
        'registries_required' => 'Bu CaaS politikası için en az bir registry belirtin.',
        'keys_required' => 'İmzalı CaaS imajları için en az bir güvenilen açık anahtar ekleyin.',
    ],

    // ── Veri yerleşimi ──
    'residency' => [
        'denied' => 'Kuruluşunuz verilerini :allowed içinde tutuyor — :region bölgesi :action için kullanılamaz.',
        'location' => ':location konumu',
        'migration_location_required' => ':region bölgesi :location veri konumuna sabitlenmiş. Taşımak için yeni kümenin :location içinde olduğunu onaylayın (data_location).',
        'migration_location_mismatch' => ':region bölgesi :location veri konumuna sabitlenmiş; yeni küme :given olarak belirtildi. Bölge verisi konumunun dışına çıkamaz.',
        'default_not_allowed' => 'Varsayılan bölge, izin verilen bölgelerden ya da konumlardan biri olmalı.',
        'nothing_to_update' => 'Güncellenecek bir şey yok.',
        'actions' => [
            'project.create' => 'yeni projeler',
            'project.move' => 'proje taşıma',
            'deploy' => 'deploy',
            'preview' => 'önizlemeler',
            'environment' => 'ortamlar',
            'sandbox.create' => 'sandbox',
            'binding.create' => 'bağlantılar',
            'model_service.deploy' => 'model servisleri',
            'model.snapshot' => 'model indirmeleri',
            'image.upload' => 'imaj yüklemeleri',
            'region.migration' => 'bölge taşıma',
            'project.global_region' => 'global projelerin bölgeleri',
        ],
    ],
];
