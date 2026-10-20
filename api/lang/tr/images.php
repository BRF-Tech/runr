<?php

// Yüklenen imajlar ve runtime'ların upload kaynağı (docs/images.md)
return [
    'registry_unavailable' => ':region bölgesinin hazır bir dahili registry\'si yok: yüklenen imajlar bölgenin dahili (Zot) registry\'sine gönderilir. Ayarlar → Bölgeler → Registry\'den kurun.',
    'upload_open' => ':name imajının bu bölgeye yüklenmesi zaten sürüyor; önce bitirin ya da iptal edin.',
    'quota_exceeded' => 'Yükleme kuruluşunuzun imaj alanına sığmıyor: :limit alanın :used kadarı kullanılıyor, :requested isteniyor. Artık gerekmeyen imajları silin.',
    'bad_name' => 'İmaj adı repository[:tag] biçiminde olmalı — en fazla üç / ile ayrılmış parçada küçük harf, rakam ve . _ -; en fazla 128 karakterlik bir etiket (harf, rakam, . _ -).',
    'bad_chunk' => ':offset konumundaki parça yüklemeye uymuyor: parça başına en fazla :chunk bayt, dosyanın :size baytı içinde.',
    'chunk_failed' => 'Bölge parçayı kabul etmedi (:status): :message',
    'incomplete' => ':size baytın :received kadarı alındı; önce kalanını gönderin.',
    'not_ready' => 'Yükleme durumu :status, parça kabul edilmiyor.',
    'receiver_error' => 'Bölgedeki yükleme alıcısının yanıtı: :message',
    'receiver_not_created' => 'Yükleme alıcısı bölgede oluşturulamadı.',
    'receiver_stopped' => 'Bölgedeki yükleme alıcısı durdu.',
    'receiver_image' => 'Yükleme alıcısı imajı :image bölgede çekilemedi (:reason). İnternetsiz bölgelerde imajın erişilebilen bir registry\'de olması gerekir (RUNR_IMAGE_UPLOAD_IMAGE).',
    'push_failed' => 'İmaj bölge registry\'sine gönderilemedi.',
    'push_timeout' => 'Bölge registry\'sine gönderim zamanında bitmedi.',
    'expired' => 'Yükleme süresi içinde tamamlanmadığı için iptal edildi.',
    'in_use' => ':name hâlâ şunlar tarafından kullanılıyor: :used. Önce onları başka bir imaja yönlendirin.',
    'caas_not_found' => 'Yüklenen imaj :image bu bölgede yok (ya da başka bir kuruluşa ait). Bölgenin yüklenen imajlarından birini seçin.',
    'caas_not_in_registry' => 'Yüklenen :name imajı :region bölgesinin registry\'sinde yok: bölge, imaj yüklendikten sonra başka bir cluster\'a taşındı. İmajı bölgeye yeniden yükleyin, sonra seçin.',
    'runtime_upload_missing' => ':runtime runtime\'ının :version sürümü yüklenen :reference imajını kullanıyor; bu imaj :region bölgesine yüklenmemiş. Oraya yükleyin (Ayarlar → İmajlar ya da runr images push --region :region).',
    'runtime_upload_regions' => ':reference imajını adlandırıyor; bu imaj şu bölge(ler)e yüklenmemiş: :regions',
    'runtime_upload_unknown' => ':reference imajını adlandırıyor; bu, runtime sahibinin yüklediği bir imaj değil',
    'wasm_upload_unknown' => 'wasm.upload :reference adını veriyor; bu, :region bölgesinde yüklenmiş bir WebAssembly modülü değil.',
    'wasm_upload_kind' => 'wasm.upload :reference adını veriyor; bu bir :kind yüklemesi, WebAssembly modülü (.wasm) olmalı.',
];
