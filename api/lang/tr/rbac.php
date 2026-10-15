<?php

// Roller ve yetkiler (docs/permissions.md)
return [
    'forbidden' => 'Bu işlem için yetkiniz yok (gereken: :permission).',
    'route_not_mapped' => 'Bu uç nokta için yetki kuralı tanımlı değil, istek reddedildi.',
    'platform_admin_required' => 'Bu işlemi yalnızca platform yöneticileri yapabilir.',
    'not_a_member' => 'Bu kullanıcı organizasyonun üyesi değil.',
    'cannot_change_own_role' => 'Kendi rolünüzü değiştiremezsiniz.',
    'unknown_role' => 'Böyle bir rol yok.',
    'last_owner' => 'Organizasyonda en az bir sahip bulunmalı.',
    'owner_only' => 'Sahip rolünü yalnızca sahipler verebilir ya da geri alabilir.',
    'cannot_grant_role' => '":role" rolünü veremez ya da geri alamazsınız: sizde olmayan yetkiler içeriyor.',
    'owner_not_assignable' => 'Sahip rolü yalnızca üyelere doğrudan verilebilir.',
    'cannot_grant_permissions' => 'Sizde olmayan yetkileri veremezsiniz.',
    'unknown_permissions' => 'Bilinmeyen yetkiler: :permissions.',
    'unknown_subject' => 'Bu kullanıcı ya da grup organizasyonda yok.',
    'role_key_taken' => 'Bu adda bir rol zaten var.',
    'role_deleted' => 'Rol silindi. Üyeleri artık varsayılan role sahip.',
    'builtin_read_only' => 'Yerleşik roller değiştirilemez.',
    'group_deleted' => 'Grup silindi.',
    'member_role_updated' => 'Rol güncellendi.',
    'access_revoked' => 'Erişim kaldırıldı.',
    'token_abilities_invalid' => 'Bir token yalnızca sizde olan yetkileri taşıyabilir: :abilities.',
    'mapping_deleted' => 'Grup eşlemesi silindi.',
    'unknown_provider' => 'Böyle bir oturum açma sağlayıcısı yok.',
    'mapping_needs_target' => 'Bir grup, bir rol ya da ikisini birden seçin.',
];
