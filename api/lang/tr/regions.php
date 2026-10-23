<?php

// Bölgeler: region:reconcile'ın bıraktıkları (App\Services\RegionReconcile\ReconcileRecord, docs/upgrade.md)
return [
    'reconcile' => [
        'unfinished_title' => 'Bölgeler henüz RunR :version sürümünde değil',
        'unfinished_body' => 'region:reconcile tamamlanmadı (çalıştırma :run, deneme :attempt): :regions bölge geride kaldı. Bölge durumu (Platform → Knative Bölgeleri → bölge → Genel) adımları gösterir, yükseltmenin runr-region-reconcile-* Job\'unun logu ayrıntıları; gösterilenleri düzeltip php artisan region:reconcile çalıştırın.',
        'unreachable_title' => 'Bazı bölge cluster\'ları yanıt vermedi',
        'unreachable_body' => 'region:reconcile :regions bölgeye ulaşamadı (çalıştırma :run). Bu bölgeler olduğu gibi kalır; cluster\'ları yanıt verdiğinde php artisan region:reconcile çalıştırın.',
    ],
];
