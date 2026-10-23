<?php

// Gateway'lerin API ürünü (docs/api-product.md)
return [
    'key' => [
        'unknown_route' => 'Bu route tüketicinin gateway\'ine ait değil.',
        'not_usable' => 'Yalnız etkin bir anahtar yenilenebilir; bu anahtar iptal edilmiş ya da süresi dolmuş.',
    ],
    'plan' => [
        'not_usable' => 'Bu plan başka bir gateway\'e ait.',
        'in_use' => 'Bu planı tüketiciler ya da etkin anahtarlar kullanıyor. Önce onları başka bir plana taşıyın.',
        'name_taken' => 'Burada bu adla bir plan zaten var.',
        'burst_without_rate' => 'Burst için saniye başına bir hız gerekir.',
        'burst_below_rate' => 'Burst, saniye başına hızdan düşük olamaz.',
    ],
    'consumer' => [
        'subject_taken' => 'Bu gateway\'in başka bir tüketicisi bu JWT subject\'ine sahip.',
    ],
    'gateway' => [
        'region_unusable' => ':region bölgesinde bir gateway\'in çalışabileceği bir küme yok.',
    ],
    'domain' => [
        'taken' => 'Bu alan adı bir gateway ya da proje tarafından kullanılıyor.',
        'nothing_pending' => 'Doğrulama bekleyen bir alan adı yok.',
        'active' => 'Alan adı doğrulandı ve gateway\'e yönlendirildi.',
    ],
    'gateway_only' => [
        'no_domains' => 'Proje yalnız gateway route\'u üzerinden erişilebilir; özel alan adları route\'un kimlik doğrulamasını atlatırdı.',
        'has_domains' => 'Önce projenin özel alan adlarını kaldırın: gateway\'i atlatırlar.',
    ],
    'route' => [
        'jwks_required' => 'JWT kimlik doğrulaması, token yayıncısının JWKS URL\'sini gerektirir.',
        'taken' => 'Projenin :environment ortamının zaten bir gateway route\'u var: onu değiştirin ya da kaldırın.',
        'path_taken' => 'Gateway\'in :path yolunda zaten bir route\'u var.',
        'region_mismatch' => ':environment ortamı :region bölgesinde çalışıyor: ona giden bir route o bölgedeki bir gateway\'e aittir.',
    ],
    'auth_service' => [
        'no_callback_url' => 'RunR, bölgenin gördüğü adresini bilmiyor. Bölgenin agent URL\'sini ya da RUNR_GATEWAY_AUTH_CALLBACK_URL\'yi ayarlayın.',
        'starting' => 'API kimlik doğrulaması bölgede başlıyor. Hazır olana kadar anahtar ve JWT route\'ları 503 auth_unavailable yanıtı verir.',
        'not_ready' => 'API kimlik doğrulaması bölgede hazır değil (hazır gateway-auth pod\'u yok; RunR\'a ulaşamıyor olabilir). Hazır olana kadar anahtar ve JWT route\'ları 503 auth_unavailable yanıtı verir.',
        'install_failed' => 'API kimlik doğrulaması bölgeye kurulamadı: :error. Kurulana kadar anahtar ve JWT route\'ları 503 auth_unavailable yanıtı verir.',
        'middleware_failed' => ':path route\'unun kimlik doğrulaması bölgeye uygulanamadı; uygulanana kadar route sunulmaz.',
        'ingress_failed' => 'Gateway\'in route\'ları bölgeye uygulanamadı; hiçbir anahtar ya da JWT route\'u denetimsiz yanıt vermesin diye bölgeden kaldırıldı.',
    ],
    'usage' => [
        'range' => 'En çok :days günlük, "from" değeri "to" değerinden önce olan bir aralık seçin.',
    ],
    'developer_page' => [
        'not_found' => 'Bu geliştirici sayfası yok.',
    ],
];
