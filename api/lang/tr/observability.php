<?php

return [
    'tracing' => [
        'no_cluster' => 'Bölgenin küme bağlantısı (kubeconfig) yok.',
        'no_region' => 'Projenin bölgesi yok.',
        'not_ready' => 'Projenin bölgesindeki izleme (tracing) altyapısı hazır değil. Bölge ayarlarından (Gözlemlenebilirlik) kurun.',
        'region_not_ready' => 'İzleme için bölgenin izleme altyapısı gerekir: önce bölge ayarlarından kurun.',
        'external_backend' => 'Bu bölge izlerini :endpoint adresine aktarıyor. İzleri o sistemde açın.',
        'tempo_unavailable' => 'Bölgenin Tempo servisi yanıt vermedi. Biraz sonra tekrar deneyin.',
        'trace_not_found' => 'Bu projeye ait iz bulunamadı (bölgenin saklama süresi dolmuş olabilir).',
        'invalid_range' => '"from", "to" değerinden önce olmalı.',
        'header_name_invalid' => 'Başlık adları yalnız harf, rakam ve tire içerebilir.',
        'install_started' => 'İzleme altyapısı kuruluyor.',
        'uninstall_started' => 'İzleme altyapısı kaldırılıyor.',
    ],

    'replay' => [
        'no_endpoint' => 'Tekrar, isteği projenin HTTP uç noktasına gönderir; bu projede uç nokta yok (kuyruk veya zamanlanmış tetikleyici).',
        'code_runtimes_only' => 'İstek yakalama RunR çalışma sarmalayıcısında çalışır; WebAssembly fonksiyonları dışındaki kod projelerinde (XaaS / FaaS) kullanılabilir.',
        'header_invalid' => 'Geçersiz başlık adı.',
    ],

    'uptime' => [
        'no_endpoint' => 'Projenin HTTP uç noktası yok: kontrole tam bir URL verin.',
        'timeout' => ':seconds sn içinde yanıt gelmedi.',
        'no_response' => 'Yanıt yok.',
        'unexpected_status' => 'Durum :status, beklenen :expected.',
        'body_mismatch' => 'Yanıt gövdesi beklenen kalıpla eşleşmiyor.',
        'invalid_regex' => 'Geçersiz düzenli ifade.',
        'expected_status_invalid' => 'Durum kodu (200), aralık (200-299) veya sınıf (2xx) kullanın; virgülle ayırın.',
        'too_many' => 'Bir projede en fazla :max erişilebilirlik kontrolü olabilir.',
        'region_unknown' => 'Bilinmeyen bölge.',
        'incident_title' => ':name erişilemiyor',
        'incident_message' => 'Başarısız kontrolleri inceliyoruz.',
        'incident_resolved' => 'Servis yeniden yanıt veriyor.',
        'alert_title' => ':project projesinin ":name" kontrolü başarısız',
        'alert_resolved' => '":name" kontrolü yeniden başarılı.',
    ],

    'status_page' => [
        'default_title' => 'Servis durumu',
        'slug_taken' => 'Bu adres başka bir durum sayfası tarafından kullanılıyor.',
        'domain_taken' => 'Bu alan adı başka bir durum sayfası tarafından kullanılıyor.',
        'not_found' => 'Durum sayfası bulunamadı.',
    ],

    'alerts' => [
        'test_title' => 'RunR test alarmı',
        'test_text' => '":channel" alarm kanalı çalışıyor.',
        'resolved_text' => 'Durum normale döndü.',
        'no_recipients' => 'Kanalın alıcısı yok.',
        'channel_unknown' => 'Bilinmeyen alarm kanalı.',
        'project_unknown' => 'Bilinmeyen proje.',
        'retry_failed_only' => 'Yalnız başarısız alarm gönderimleri yeniden denenebilir.',
        'metric_title' => [
            'error_rate' => ':project projesinde yüksek hata oranı',
            'p95_latency' => ':project projesinde yüksek p95 gecikmesi',
            'cold_start_rate' => ':project projesinde yüksek soğuk başlangıç oranı',
        ],
        'metric_text' => 'Son :window dakikada :value (eşik :threshold).',
        'metric_resolved' => ':value değerine döndü.',
        'resource_text' => ':region bölgesinin :resource rezervasyonu %:percentage (eşik %:threshold).',
    ],

    'url' => [
        'invalid' => 'Geçersiz URL.',
        'invalid_scheme' => 'Yalnız http:// ve https:// adreslerine izin verilir.',
        'https_required' => 'URL https:// kullanmalı.',
        'credentials' => 'Kullanıcı adı veya parola içeren URL\'lere izin verilmez.',
        'private' => 'URL özel, loopback veya küme içi bir adresi gösteriyor.',
        'unresolvable' => 'Alan adı çözümlenemiyor.',
    ],
];
