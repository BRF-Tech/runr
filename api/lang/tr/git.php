<?php

// Git tabanlı dağıtım (App\Http\Controllers\Api\Git*, App\Services\GitDeploy)
return [
    'forbidden' => 'Bu projede :permission yetkiniz yok.',
    'already_connected' => 'Bu proje zaten bir depoya bağlı. Önce bağlantıyı kaldırın.',
    'repository_unreachable' => 'Bu kimlik bilgileriyle depo okunamıyor: :error',
    'webhook_failed' => 'Depoya webhook eklenemedi: :error',
    'provider_unreachable' => 'Sağlayıcı yanıt vermedi: :error',
    'provider_unknown' => 'Bu git sağlayıcısı kullanılamıyor.',
    'provider_in_use' => 'Bu sağlayıcıya bağlı :count proje var. Önce bağlantılarını kaldırın.',
    'private_key_invalid' => 'Özel anahtar geçerli bir PEM anahtarı değil.',
    'installation_unknown' => 'Bu GitHub App kurulumu organizasyonunuza bağlı değil. Önce uygulamayı kurun.',
    'oauth_expired' => 'GitLab oturumu sona erdi. Yeniden giriş yapın.',
    'oauth_not_configured' => 'Bu GitLab sunucusunda OAuth uygulaması yok. Erişim jetonu kullanın.',
    'token_required' => 'Erişim jetonu gerekli.',
    'nothing_to_redeploy' => 'Dağıtım dalında henüz dağıtılacak bir commit yok.',
    'preview_not_redeployable' => 'Yalnızca hazır ya da başarısız bir önizleme yeniden dağıtılabilir.',
    'policy_pending' => ':version güvenlik denetimleri sürüyor (:reasons); bittiğinde dağıtım kendiliğinden devam eder.',
    'policy_blocked_hint' => 'Bulguları düzeltip yeniden push edin ya da sürümü konsoldan politika istisnası ve gerekçeyle dağıtın.',
];
