<?php

/*
| Bot koruması: anonim formların CAPTCHA'sı ve yerel yedeği (docs/security.md#bot-protection)
*/
return [
    'fallback' => 'Doğrulama servisine ulaşılamıyor. Tarayıcınız onun yerine kısa bir hesap yapıyor.',
    'off' => 'Bot koruması kapalı.',

    // 422 / 409 / 503 {captcha: <neden>}
    'reasons' => [
        'required' => 'Bot kontrolünü tamamlayın.',
        'invalid' => 'Bot kontrolü geçmedi ya da süresi doldu. Lütfen tekrar deneyin.',
        'fallback_inactive' => 'Doğrulama servisi yeniden yanıt veriyor. Bot kontrolünü yeniden tamamlayın.',
        'provider' => 'Bot kontrolü tarayıcınızda yüklenemedi, ama doğrulama servisine ulaşılabiliyor: bu sayfa için reklam ya da içerik engelleyicinizi kapatıp tekrar deneyin.',
        'unavailable' => 'Doğrulama servisine ulaşılamıyor. Biraz sonra tekrar deneyin.',
        'unprotected' => 'Bu formda bot kontrolü yok.',
        'bad_payload' => 'Tarayıcı kontrolünün yanıtı okunamadı. Lütfen tekrar deneyin.',
        'bad_algorithm' => 'Tarayıcı kontrolünün yanıtı okunamadı. Lütfen tekrar deneyin.',
        'bad_salt' => 'Tarayıcı kontrolünün yanıtı okunamadı. Lütfen tekrar deneyin.',
        'expired' => 'Tarayıcı kontrolünün süresi doldu. Lütfen tekrar deneyin.',
        'bad_number' => 'Tarayıcı kontrolünün yanıtı hatalı. Lütfen tekrar deneyin.',
        'bad_signature' => 'Tarayıcı kontrolünün yanıtı hatalı. Lütfen tekrar deneyin.',
        'bad_hash' => 'Tarayıcı kontrolünün yanıtı hatalı. Lütfen tekrar deneyin.',
        'replayed' => 'Bu tarayıcı kontrolü zaten kullanıldı. Lütfen tekrar deneyin.',
    ],

    // Yöneticilerin alarmı: 10 dakikada birden fazla yerel kontrol (CaptchaFallbackAlert)
    'alert' => [
        'title' => 'Bot koruması: CAPTCHA sağlayıcısına ulaşılamıyor',
        'body' => 'Son 10 dakikada :count kez :provider yerine tarayıcı kontrolü devreye girdi: sağlayıcı ya da API’den ona çıkış arızalı. Giriş ve kayıt formları bu sırada çalışmaya devam ediyor. Yedeğin durumu Yönetim → Erişim ve güvenlik → Bot koruması sayfasında.',
    ],
    // Yanlış yapılandırmanın alarmı (CaptchaConfigAlert): formlar tarayıcı kontrolüyle çalışır
    'misconfigured' => [
        'title' => 'Bot koruması: CAPTCHA yapılandırması yanlış',
        'body' => ':problem Giriş ve kayıt formları düzeltilene kadar tarayıcı kontrolüyle çalışmaya devam ediyor. Anahtarları Yönetim → Erişim ve güvenlik → Bot koruması sayfasında düzeltin.',
        'reasons' => [
            'site_key_missing' => ':provider site anahtarı eksik.',
            'secret_missing' => ':provider gizli anahtarı eksik.',
            'secret_unreadable' => 'Kayıtlı :provider gizli anahtarı çözülemiyor (uygulama anahtarı değişmiş): yeniden girin.',
            'secret_rejected' => ':provider gizli anahtarı reddediyor: anahtar yanlış ya da site anahtarına ait değil.',
        ],
    ],
    'providers' => [
        'turnstile' => 'Cloudflare Turnstile',
        'recaptcha' => 'Google reCAPTCHA',
        'hcaptcha' => 'hCaptcha',
        'none' => 'sağlayıcı yok',
    ],

    'settings' => [
        'site_key_required' => 'Sağlayıcı için site anahtarı gerekir.',
        'secret_required' => 'Sağlayıcı için gizli anahtar gerekir.',
    ],
];
