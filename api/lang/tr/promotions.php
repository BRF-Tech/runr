<?php

// Ortamlar arası yükseltme bildirimleri (docs/environments.md#notifications)
return [
    'pending_approval_title' => ':project: v:version, :target ortamına geçmek için onayınızı bekliyor',
    'pending_approval_body' => ':requester, :project projesinin v:version sürümünü :source ortamından :target ortamına yükseltmek istiyor.',
    'approved_title' => ':project: v:version sürümünün :target ortamına yükseltilmesi onaylandı',
    'approved_body' => ':decider, :project projesinin v:version sürümünü :source ortamından :target ortamına yükseltme isteğinizi onayladı; şimdi dağıtılıyor.',
    'rejected_title' => ':project: v:version sürümünün :target ortamına yükseltilmesi reddedildi',
    'rejected_body' => ':decider, :project projesinin v:version sürümünü :source ortamından :target ortamına yükseltme isteğinizi reddetti.',
    'deployed_title' => ':project: v:version artık :target ortamında çalışıyor',
    'deployed_body' => ':project projesinin v:version sürümünü :source ortamından :target ortamına yükseltme isteğiniz dağıtıldı.',
    'failed_title' => ':project: v:version sürümünün :target ortamına yükseltilmesi başarısız oldu',
    'failed_body' => ':project projesinin v:version sürümünü :source ortamından :target ortamına yükseltme isteğiniz başarısız oldu.',
    'message' => 'Mesaj: :text',
    'comment' => 'Yorum: :text',
    'reason' => 'Neden: :text',
    'open' => 'Aç',
    'someone' => 'Birisi',
];
