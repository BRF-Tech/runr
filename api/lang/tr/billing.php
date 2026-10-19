<?php

/*
| Faturalandırma modülü (docs/billing.md)
*/

return [
    'types' => [
        'payg' => 'kullandıkça öde',
        'fixed' => 'sabit kaynak',
    ],

    'tenant_context_required' => 'Bu yalnızca bir organizasyonun (tenant) içinde kullanılabilir.',
    'tenant_not_found' => 'Organizasyon bulunamadı.',
    'region_not_found' => 'Bölge bu organizasyonda bulunamadı.',
    'region_prices_owned_by_platform' => 'Paylaşılan bir platform bölgesi platformun fiyatlarıyla gelir. Bunun yerine kendi kar marjınızı belirleyin.',
    'region_type_owned_by_platform' => 'Paylaşılan bir platform bölgesi platformun faturalandırma türüyle gelir.',
    'region_type_locked' => 'Bu bölgede :count etkin sabit kaynak tahsisi varken faturalandırma türü değiştirilemez. Önce bu projeleri sonlandırın ya da taşıyın.',
    'region_now_payg' => 'Bölge kullandıkça öde türüne geçirildi.',
    'price_in_effect' => 'Bu fiyat sürümü yürürlükte ve faturalara yansıdı. Bunun yerine daha ileri tarihli yeni bir sürüm ekleyin.',
    'markup_in_effect' => 'Bu kar marjı sürümü yürürlükte. Bunun yerine daha ileri tarihli yeni bir sürüm ekleyin.',
    'markup_shared_regions_only' => 'Kar marjı yalnızca paylaşılan platform bölgelerine uygulanır.',
    'offer_not_pending' => 'Bu bölge teklifi kabul beklemiyor.',

    'region_not_fixed' => 'Projenin bölgesi kullandıkça öde türünde: sabit kaynak talebi gerekmez.',
    'gpu_not_available' => 'Projenin bölgesinde GPU sunulmuyor.',
    'replaced_by_newer_request' => 'Daha yeni bir talep bunun yerini aldı.',
    'request_not_pending' => 'Bu talep zaten karara bağlandı.',
    'project_moved' => 'Proje artık bu talebin bölgesinde değil.',
    'project_left_region' => 'Proje bölgeden ayrıldı.',
    'project_forbidden' => 'Bunu yalnızca projenin sahibi, faturalarına erişimi olan üyeler ve faturalandırma ekibi görür.',

    'fixed_allocation_required' => 'Bu proje sabit kaynaklı bir bölgede çalışıyor: dağıtımdan önce projenin Faturalandırma sekmesinden kaynak talep edin ve onayı bekleyin.',
    'fixed_allocation_exceeded' => 'Proje en yüksek ölçeğinde (:pods pod, önizlemeler dahil) :cpu m CPU ve :memory MB bellek istiyor; onaylı kaynakları :allowed_cpu m CPU ve :allowed_memory MB. max_scale değerini ya da boyutu düşürün veya Faturalandırma sekmesinden daha fazla kaynak talep edin.',
    'fixed_gpus_exceeded' => 'Proje en yüksek ölçeğinde :gpus GPU istiyor; onaylı kaynakları :allowed GPU içeriyor. max_scale değerini düşürün ya da Faturalandırma sekmesinden daha fazla kaynak talep edin.',
    'fixed_max_scale_required' => 'Sabit kaynaklı bir bölgede projenin bir en yüksek ölçeği (max_scale) olmalı: en büyük boyutu onaylı kaynaklara sığmalı.',
    'fixed_scale_down_first' => 'Proje şu an en fazla :cpu m CPU, :memory MB bellek ve :gpus GPU ile çalışıyor (en yüksek ölçeğinde, önizlemeler dahil); talep bundan küçük. Önce projeyi küçültün (max_scale değerini ya da boyutu düşürüp dağıtın), sonra küçük kaynakları talep edin.',
    'fixed_running_unbounded' => 'Proje en yüksek ölçek (max_scale) olmadan çalışıyor: Knative istediği kadar pod başlatabilir, bu yüzden hiçbir kaynak çalışan projeyle karşılaştırılamaz. Önce max_scale değerini belirleyip projeyi yeniden dağıtın, sonra kaynakları talep edin (ya da onaylayın).',
    'region_no_longer_fixed' => 'Bölge artık sabit kaynaklı bir bölge değil: bu talep onaylanamaz.',

    'bill_open' => 'Faturanın ayı henüz bitmedi. Ödeme bağlantısı ve ödendi durumu kapanmış faturalarda belirlenir.',
    'webhook_not_configured' => 'Faturalandırma webhook\'u yapılandırılmamış (faturalandırma ayarları ya da BILLING_WEBHOOK_URL).',
    'webhook_unreachable' => 'Webhook yanıt vermedi: bağlantı kurulamadı.',
    'webhook_timeout' => 'Webhook zamanında yanıt vermedi.',
    'project_moved_to_tenant' => 'Proje, dönüştürüldüğü organizasyon tarafından faturalandırılıyor.',
    'legacy_currency_mismatch' => 'RunR 0.5.7\'den aktarılan fiyatlar :legacy cinsindendi, ancak BILLING_CURRENCY :currency: fiyatlar :currency tutarı olarak okunuyor. BILLING_CURRENCY\'yi :legacy yapın ya da Bölgeler ve fiyatlar\'daki her fiyatı gözden geçirin.',

    'notify' => [
        'open' => 'RunR\'da aç',
        'offer_title' => ':region bölgesi organizasyonunuzla paylaşıldı',
        'offer_body' => 'Platform :region bölgesini (:type) organizasyonunuzla paylaştı. Kullanıcılarınızın orada proje oluşturabilmesi için fiyatları inceleyin, kar marjınızı belirleyin ve teklifi kabul edin.',
        'request_title' => ':project için sabit kaynak talep edildi',
        'request_body' => ':project, aylık :price karşılığında :resources talep ediyor. Talebi onaylayın ya da reddedin.',
        'approved_title' => ':project için sabit kaynak onaylandı',
        'approved_body' => ':project için aylık :price karşılığında :resources onaylandı ve bugünden itibaren faturalandırılıyor.',
        'rejected_title' => ':project için sabit kaynak reddedildi',
        'rejected_body' => ':project için :resources talebi (aylık :price) reddedildi: :reason',
    ],
];
