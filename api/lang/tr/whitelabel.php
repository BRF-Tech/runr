<?php

/*
| White-label: marka, kendi kendine kayıt, planlar, ödemeler, tahsilat süreci (docs/white-label.md)
*/
return [
    'tenant_required' => 'Bu işlem için etkin bir organizasyon gerekir.',

    'signup' => [
        'registration_disabled' => 'Kayıt kapalı.',
        'invite_only' => 'Hesaplar yalnızca davetle açılır. Yöneticinizden davet isteyin.',
        'domain_not_allowed' => 'Bu e-posta alan adı ile kayıt yapılamaz.',
        'captcha_failed' => 'CAPTCHA doğrulaması başarısız oldu. Lütfen tekrar deneyin.',
        'verification_sent' => 'Gelen kutunuzu kontrol edin: e-posta adresinizi doğrulamanız için bir bağlantı gönderdik.',
        'verification_resent' => 'Bu adres için bekleyen bir kayıt varsa yeni doğrulama bağlantısı gönderildi.',
        'verification_invalid' => 'Bu doğrulama bağlantısı geçerli değil. Yeni bir bağlantı isteyin.',
        'verification_expired' => 'Bu doğrulama bağlantısının süresi doldu. Yeni bir bağlantı isteyin.',
        'email_taken' => 'Bu e-posta adresiyle bir hesap zaten var.',
        'username_taken' => 'Bu kullanıcı adı alınmış. Lütfen başka bir kullanıcı adıyla yeniden kayıt olun.',
    ],

    'branding' => [
        'unknown_asset' => 'Bilinmeyen marka dosyası.',
        'invalid_url' => 'http(s) adresi ya da bu sunucudaki bir yol kullanın.',
        'unsafe_svg' => 'SVG betik ya da gömülü içerik barındırıyor, kullanılamaz.',
        'tenant_overrides_disabled' => 'Organizasyon markalaması platform yöneticisi tarafından kapatıldı.',
    ],

    'mail' => [
        'verify_subject' => ':product için e-posta adresinizi doğrulayın',
        'verify_heading' => 'E-posta adresinizi doğrulayın',
        'verify_greeting' => 'Merhaba :name,',
        'verify_intro' => ':product kaydınız için teşekkürler. Hesabınızı etkinleştirmek için e-posta adresinizi doğrulayın.',
        'verify_button' => 'E-posta adresini doğrula',
        'verify_expiry' => 'Bağlantı :hours saat geçerlidir.',
        'verify_ignore' => 'Kayıt olmadıysanız bu e-postayı yok sayabilirsiniz.',
        'support' => 'Destek:',
        'rights' => 'Tüm hakları saklıdır.',
        'powered_by' => 'RunR altyapısıyla',
    ],

    'onboarding' => [
        'default_tenant_name' => ':name çalışma alanı',
        'region_not_offered' => 'Bu bölge yeni organizasyonlara açık değil.',
        'not_allowed' => 'Kurulumu yalnızca organizasyonun sahibi ya da bir yöneticisi tamamlayabilir.',
    ],

    'plans' => [
        'disabled' => 'Bu platformda planlar etkin değil.',
        'not_available' => 'Bu plan kullanılamıyor.',
        'interval_not_available' => 'Bu planın seçilen faturalama dönemi için fiyatı yok.',
        'unknown_limit' => 'Bilinmeyen plan limiti: :keys',
        'unknown_feature' => 'Bilinmeyen plan özelliği: :keys',

        'limit_max_projects' => 'Planınız :max projeye izin veriyor (:used kullanımda). Daha fazlası için planınızı yükseltin.',
        'limit_gpu_quota' => 'Planınız :max GPU\'ya izin veriyor (:used kullanımda). Daha fazlası için planınızı yükseltin.',
        'limit_invocations_per_month' => 'Planınızın bu ayki :max çağrı hakkı doldu (:used). Yeni dağıtımlar gelecek ay ya da plan yükseltmesinden sonra yapılabilir.',
        'limit_storage_quota_mb' => 'Planınızın :max MB depolama alanı dolu (:used MB). Planınızı yükseltin ya da eski projeleri kaldırın.',
        'feature_not_included' => ':feature planınıza dahil değil.',

        'feature_custom_domains' => 'Özel alan adları',
        'feature_private_connections' => 'Özel veritabanı bağlantıları',
        'feature_gpu' => 'GPU iş yükleri',
        'feature_api_tokens' => 'API anahtarları',
        'feature_sso' => 'Tek oturum açma (SSO)',
        'feature_metered_usage' => 'Kullanıma göre faturalama',
        'feature_priority_support' => 'Öncelikli destek',
    ],
];
