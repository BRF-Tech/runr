<?php

// Bölgeler: region:reconcile'ın bıraktıkları (App\Services\RegionReconcile\ReconcileRecord, docs/upgrade.md)
return [
    'reconcile' => [
        'unfinished_title' => 'Bölgeler henüz RunR :version sürümünde değil',
        'unfinished_body' => 'region:reconcile tamamlanmadı (çalıştırma :run, deneme :attempt): :regions bölge geride kaldı. Bölge durumu (Platform → Knative Bölgeleri → bölge → Genel) adımları gösterir, yükseltmenin runr-region-reconcile-* Job\'unun logu ayrıntıları; gösterilenleri düzeltip php artisan region:reconcile çalıştırın.',
        'unreachable_title' => 'Bazı bölge cluster\'ları yanıt vermedi',
        'unreachable_body' => 'region:reconcile :regions bölgeye ulaşamadı (çalıştırma :run). Bu bölgeler olduğu gibi kalır; cluster\'ları yanıt verdiğinde php artisan region:reconcile çalıştırın.',
    ],

    // Bir organizasyonun kendi bölgesinin kubeconfig'i (App\Services\Kubeconfigs\KubeconfigPolicy,
    // docs/security.md#tenant-region-kubeconfigs)
    'kubeconfig' => [
        'invalid' => 'Kubeconfig geçerli bir YAML ya da JSON değil.',
        'too_large' => 'Kubeconfig :kb KB\'tan büyük.',
        'not_a_config' => 'Kubeconfig bir kubectl yapılandırması değil (apiVersion v1, kind Config).',
        'exec' => ':field RunR\'ın sunucularında bir program çalıştırır (kimlik bilgisi eklentisi). Bir organizasyonun bölgesi kubeconfig\'teki bir ServiceAccount token\'ı ya da istemci sertifikasıyla bağlanır.',
        'file' => ':field RunR\'ın sunucularında bir dosya okur. Verinin kendisini kubeconfig\'e koyun (*-data alanları): kubectl config view --minify --flatten bunları yazar.',
        'proxy' => ':field RunR\'ın bağlantısını bir proxy üzerinden geçirir. Bir organizasyonun bölgesine doğrudan bağlanılır.',
        'impersonation' => ':field (başka bir hesap adına işlem) kabul edilmez. Hesabın kendi kimlik bilgilerini kullanın.',
        'field' => ':field bir organizasyonun bölgesinin kubeconfig\'inde kabul edilmez. Cluster: server, certificate-authority-data, tls-server-name ve insecure-skip-tls-verify; kullanıcı: token, client-certificate-data ve client-key-data ya da username ve password; context: cluster, user ve namespace.',
        'unused' => 'Kubeconfig yalnızca geçerli context\'ini, onun cluster\'ını ve kullanıcısını içerebilir; :entry kullanılmıyor. kubectl config view --minify --flatten böyle bir kubeconfig yazar.',
        'duplicate' => ':entry birden fazla kez geçiyor.',
        'no_context' => 'current-context kubeconfig\'teki bir context\'i göstermiyor.',
        'missing' => 'Kubeconfig\'te geçerli context için :entry yok.',
        'value' => ':field geçersiz bir değer taşıyor.',
        'key_pair' => 'client-certificate-data ve client-key-data birlikte verilir.',
        'server' => 'Cluster server\'ı API sunucusunun https:// adresi olmalı (yalnızca host, port ve yol).',
        'server_blocked' => 'Cluster server\'ı :host: :reason Bir organizasyonun bölgesi API sunucusunun herkese açık bir adresini kullanır.',
        'none' => 'Bölgenin RunR\'ın kullandığı bir kubeconfig\'i yok.',
        'unusable' => 'RunR bu bölgenin kubeconfig\'ini kullanmıyor: :reason Kubeconfig\'i değiştirin (Platform → Knative Bölgeleri → bölge → Genel).',
        // Bir organizasyonun kullanıcılarının gördüğü bağlantı hataları (platform yöneticileri ayrıntıyı görür)
        'unreachable' => 'Cluster\'ın API sunucusuna ulaşılamadı (bağlantı ya da TLS el sıkışması kurulamadı).',
        'rejected' => 'Cluster\'ın API sunucusu kubeconfig\'in kimlik bilgilerini reddetti (HTTP :status).',
        'unexpected' => 'Cluster\'ın API sunucusu bir Kubernetes API sunucusu gibi yanıt vermedi (HTTP :status).',
        'failed' => 'Cluster\'a yapılan istek başarısız oldu.',
    ],
];
