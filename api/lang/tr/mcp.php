<?php

return [
    'server_not_found' => 'Bu proje bir MCP sunucusu olarak sunulmuyor.',
    'unsupported_type' => 'Yalnızca xaas, faas ve caas projeleri MCP sunucusu olarak sunulabilir.',
    'http_trigger_required' => 'Bir MCP sunucusu http tetikleyicisi gerektirir.',
    'key_rotated' => 'Yeni ağ geçidi anahtarı oluşturuldu. Sunucuya ulaşması için projeyi dağıtın; önceki anahtar :days gün daha çalışır.',
    'message_invalid' => 'Mesaj bir JSON-RPC 2.0 isteği ya da bildirimi olmalı.',
    'message_too_large' => 'Mesaj çok büyük.',
    'request_not_found' => 'Bilinmeyen yetkilendirme isteği.',
    'request_expired' => 'Bu yetkilendirme isteğinin süresi doldu ya da zaten yanıtlandı. Bağlantıyı uygulamadan yeniden başlatın.',
    'lifetime_invalid' => 'Sunulan token sürelerinden birini seçin.',
    'switch_organization' => 'Önce bu MCP sunucusunun organizasyonuna geçin.',
    'call_not_permitted' => 'Bu MCP sunucusunu çağırma yetkiniz yok (mcp.servers.call gerekir).',
    'client_not_connected' => 'Bu uygulama burada sizin adınıza bir token tutmuyor.',
];
