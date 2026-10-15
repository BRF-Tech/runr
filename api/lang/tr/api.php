<?php

return [

    // ── Auth ──
    'auth' => [
        'login_failed' => 'Geçersiz kullanıcı adı/e-posta veya şifre.',
        'logged_out' => 'Çıkış yapıldı.',
    ],

    // ── User ──
    'user' => [
        'updated' => 'Kullanıcı güncellendi.',
        'cannot_delete_self' => 'Kendinizi silemezsiniz.',
        'deleted' => 'Kullanıcı silindi.',
        '2fa_not_enabled' => 'Bu kullanıcı için 2FA etkin değil.',
        '2fa_reset' => '2FA sıfırlandı.',
    ],

    // ── API Token ──
    'token' => [
        'not_found' => 'Token bulunamadı.',
        'deleted' => 'Token silindi.',
    ],

    // ── Region (Knative) ──
    'region' => [
        'demo_not_modifiable' => 'Demo bölge değiştirilemez.',
        'platform_region_read_only' => 'Platform bölgeleri platform yöneticileri tarafından yönetilir.',
        'region_admin_required' => 'Bu bölgeyi yalnız kiracı sahibi ya da kiracı yöneticisi değiştirebilir.',
        'platform_admin_required' => 'Bölgeleri yalnız platform yöneticileri değiştirebilir.',
        'kubeconfig_missing' => 'Kubeconfig yapılandırılmamış.',
        'kubeconfig_not_found' => 'Kubeconfig bulunamadı.',
        'install_in_progress' => 'Kurulum zaten devam ediyor.',
        'uninstall_in_progress' => 'Kaldırma zaten devam ediyor.',
        'operator_required' => 'Önce Operator kurulmalıdır.',
        'serving_uninstall_first' => 'Operator kaldırılmadan önce Serving kaldırılmalıdır.',
        'operation_in_progress' => 'İşlem zaten devam ediyor.',
        'region_removed' => 'Bölge kaldırıldı.',
        'workers_updated' => 'Worker\'lar güncellendi.',
        'detection_failed' => 'Algılama başarısız: :message',
        'namespace_check_failed' => 'Namespace kontrol edilemiyor: :message',
        'namespace_exists' => "':namespace' namespace'i kümede zaten mevcut.",
        'namespace_create_failed' => 'Namespace oluşturulamadı: :message',
        'versions_fetch_failed' => 'Sürümler alınamıyor: :message',
        'k8s_version_required' => 'Kubernetes 1.:required+ gerekli. Algılanan: 1.:detected.',
        'connect_failed' => 'Kümeye bağlanılamıyor: :message',
        'storage_classes_failed' => 'Depolama sınıfları listelenemedi: :message',
        'function_tls_updated' => 'Fonksiyon alan adı TLS\'i güncellendi.',
        'function_tls_pem_invalid' => 'Geçerli bir PEM :marker olmalı (düz PEM ya da base64).',
        'function_tls_console_unavailable' => 'Konsol sertifikasını yeniden kullanmak için bu bölgenin konsolla aynı kümede çalışması gerekir.',
        'function_tls_console_not_configured' => 'Konsolda özel bir sertifika yapılandırılmamış (ingress.tls.mode=custom).',
        'function_tls_sync_failed' => 'Fonksiyon alan adı TLS\'i uygulanamadı: :message',
        'function_tls_store_conflict' => ':namespace ad alanında zaten bir Traefik TLSStore "default" var. Traefik küme başına tek bir varsayılan TLSStore kullanır; fonksiyon alan adı TLS\'i yalnız o TLSStore\'un bulunduğu yerde ayarlanır.',
        'function_tls_store_replace_required' => ':namespace içinde RunR\'ın oluşturmadığı bir TLSStore "default" var; konsol sertifikasını da sunuyor olabilir. Değiştirmek için replace_existing: true gönderin.',
        'function_tls_key_mismatch' => 'Özel anahtar bu sertifikaya ait değil.',
        'function_tls_expired' => 'Sertifikanın süresi :date tarihinde doldu.',
        'function_tls_domain_mismatch' => 'Sertifika *.:domain adını kapsamıyor (sertifikadaki adlar: :names).',
        'function_tls_no_domain' => 'Bölgenin sertifikanın karşılaştırılacağı bir fonksiyon alan adı (knative_domain) yok.',
        'monitoring_url_invalid' => 'Bölge kümesinde bir Service adresi olmalı: http(s)://<servis>.<ad-alanı>.svc[.cluster.local]:<port>[/yol] (en çok 255 karakter).',
        'monitoring_endpoint_forbidden' => 'İzleme uç noktasını yalnız platform yöneticisi ya da kiracı sahibi ayarlayabilir.',
    ],

    // ── Demo ──
    'demo' => [
        'cannot_seed_demo' => 'Demo bölgede demo oluşturulamaz.',
        'already_exists' => 'Demo projeler zaten mevcut veya oluşturuluyor.',
        'already_removing' => 'Zaten kaldırılıyor.',
        'serving_not_ready' => 'Bu bölgede Serving hazır değil.',
        'redis_not_ready' => 'Bu bölgede Redis hazır değil.',
        'registry_not_ready' => 'Bu bölgede Registry hazır değil.',
        'no_default_version' => ':runtime için varsayılan sürüm bulunamadı.',
    ],

    // ── Project ──
    'project' => [
        'eol_save' => 'Bu proje kullanım ömrü dolmuş bir runtime kullanıyor. Kaydetmeden önce runtime sürümünü güncelleyin.',
        'eol_deploy' => 'Bu proje kullanım ömrü dolmuş bir runtime kullanıyor. Dağıtmadan önce runtime sürümünü güncelleyin.',
        'runtime_not_available' => "':value' runtime'ı mevcut değil.",
        'version_not_available' => ':runtime için :version sürümü mevcut değil.',
        'region_status' => 'Bu bölge :status.',
        'region_no_kubeconfig' => 'Bu bölgede kubeconfig yapılandırılmamış.',
        'region_maintenance' => 'bakımda',
        'region_inactive' => 'pasif',
        'version_not_ready' => 'Bu sürüm dağıtıma hazır değil. Build durumu: :status.',
        'version_pruned' => 'v:version sürümünün build imajı registry saklama kuralıyla silindi, artık dağıtılamaz. Yeni bir sürüm için kodu yeniden kaydedin.',
        'queue_requires_redis' => 'Kuyruk tetikleyicisi seçili bölgede yapılandırılmış Redis gerektirir.',
        'slug_invalid' => 'Ad, harfle başlayan ve yalnızca harf, rakam ve tire içeren bir Kubernetes adı vermelidir (şu an ":slug").',
        'slug_too_long' => 'Ad çok uzun: Kubernetes adı ":slug" :length karakter, en fazla :max olabilir.',
        'slug_taken' => '":slug" Kubernetes adını başka bir proje kullanıyor; farklı bir ad seçin.',
        'cron_invalid' => 'Zamanlama 5 alanlı bir cron ifadesi (dakika saat gün ay haftanın-günü) ya da @hourly/@daily/@weekly/@monthly/@yearly olmalıdır; saniye, saat dilimi ve L/W/# desteklenmez.',
        'env_not_map' => 'Ortam değişkenleri AD => değer biçiminde olmalıdır.',
        'env_too_many' => 'En fazla :max ortam değişkeni tanımlanabilir.',
        'env_key_invalid' => 'Geçersiz ortam değişkeni adı ":key": harf, rakam ve "_" kullanın, rakamla başlamasın.',
        'env_key_reserved' => '":key" ortam değişkenini platform ayarlar, değiştirilemez.',
        'env_value_invalid' => '":key" ortam değişkeninin değeri metin olmalıdır.',
        'env_too_large' => 'Ortam değişkenleri toplamda en fazla :max KiB olabilir.',
        'cpu_invalid' => 'CPU sıfırdan büyük bir Kubernetes miktarı olmalıdır, örn. "250m" ya da "0.5".',
        'timeout_too_long' => 'Zaman aşımı en fazla :max saniye olabilir (:region bölgesinin istek zaman aşımı).',
        'service_type_unsupported' => 'Yalnızca XaaS, FaaS ve CaaS projeleri oluşturulabilir, değiştirilebilir ve dağıtılabilir.',
    ],

    // ── Bölge kapasitesi (WithinResourceLimit) ──
    'resource_limit' => [
        'cpu_exceeded' => 'CPU limiti aşılıyor. Kullanılabilir: :availablem (toplam: :limitm).',
        'memory_exceeded' => 'Memory limiti aşılıyor. Kullanılabilir: :available MB (toplam: :limit MB).',
    ],

    // ── Proje özel alan adları ──
    'domain' => [
        'invalid' => 'api.example.com gibi tam bir alan adı girin (küçük harf, rakam ve tire; uluslararası adlar xn-- biçiminde).',
        'wildcard' => 'Joker (wildcard) alan adları desteklenmez; her alan adını ayrı ekleyin.',
        'taken' => 'Bu alan adı zaten bir projeye bağlı.',
        'reserved' => 'Bu alan adı platforma ait (bir bölge fonksiyon alan adı ya da konsol alan adı), bağlanamaz.',
        'no_knative_service' => 'Özel alan adları bir Knative Service çalıştıran projeler içindir (container, fonksiyon ya da kod projesi).',
        'not_http' => 'Özel alan adları yalnızca HTTP tetiklemeli projelere hizmet verir; bu proje ":trigger" tetikleyicisini kullanıyor ve herkese açık bir URL\'si yok.',
        'no_region' => 'Özel alan adı bağlamak için projeyi bir bölgeye atayın.',
        'demo_region' => 'Demo bölgede özel alan adı kullanılamaz.',
        'region_unavailable' => 'Projenin bölgesine bu değişiklik için ulaşılamıyor.',
        'txt_missing' => ':name TXT kaydı ":value" değeriyle bulunamadı.',
        'not_pointing' => ':domain bu projeyi göstermiyor. Şu kayıtlardan birini oluşturun: :targets.',
        'no_target' => 'Bölgenin, alan adının yönlendirileceği bir dış adresi yok.',
        'verify_failed' => 'Alan adı doğrulanamadı.',
        'active' => 'Alan adı doğrulandı ve yönlendiriliyor.',
        'apply_failed' => 'Alan adının bölgede yönlendirilmesi başarısız oldu: :message',
        'routed_elsewhere' => ':domain bu bölgede zaten başka bir projeye yönlendiriliyor.',
        'region_changed' => 'Proje başka bir bölgeye taşındı: DNS kaydını güncelleyip yeniden doğrulayın.',
        'remove_failed' => 'Alan adının bölgeden kaldırılması başarısız oldu (:objects).',
        'tls_updated' => 'TLS ayarı güncellendi.',
        'tls_apply_failed' => 'TLS ayarının bölgeye uygulanması başarısız oldu: :message',
        'key_mismatch' => 'Özel anahtar bu sertifikaya ait değil.',
        'certificate_host_mismatch' => 'Sertifika :domain alan adını kapsamıyor.',
        'cert_manager_not_configured' => 'Bu bölge için bir cert-manager ClusterIssuer tanımlı değil (bölge ayarı cert_manager_cluster_issuer).',
        'cert_manager_unavailable' => 'Bölge kümesinde cert-manager (cert-manager.io/v1) ya da ":issuer" ClusterIssuer\'ı bulunmuyor.',
        'certificate_pending' => 'cert-manager sertifikayı düzenliyor; hazır olana kadar HTTPS bölgenin varsayılan sertifikasını kullanır. :reason',
        'tls_note_none' => 'HTTP üzerinden sunulur. HTTPS, bu alan adını kapsamayan bölge varsayılan sertifikasıyla yanıt verir.',
        'tls_note_custom' => 'HTTPS yüklenen sertifikayı kullanır (:date tarihine kadar geçerli).',
        'tls_note_cert_manager' => 'HTTPS, cert-manager tarafından düzenlenen bir sertifika kullanır (ClusterIssuer :issuer).',
    ],

    // ── Code ──
    'code' => [
        'saved' => 'Kod kaydedildi.',
        'files_saved' => 'Dosyalar kaydedildi.',
        'main_file_delete' => 'Ana dosya (:file) silinemez.',
        'handler_required' => 'Ana dosya bir handler fonksiyonu içermelidir.',
        'too_large' => 'Kod çok büyük (:size bayt); bir dağıtıma en fazla :max bayt sığar.',
        'file_name_invalid' => 'Geçersiz dosya adı ":name": yalnızca harf, rakam, ".", "-" ve "_" kullanın (klasör yok).',
    ],

    // ── Registry ──
    'registry' => [
        'cannot_modify_demo' => 'Demo bölge değiştirilemez.',
        'token_expired' => 'Kurulum token\'ı bulunamadı veya süresi dolmuş.',
        'cannot_retry' => 'Yeniden denenemez: kurulum hata durumunda değil.',
    ],

    // ── Redis ──
    'redis' => [
        'installation_queued' => 'Redis kurulumu kuyruğa alındı.',
        'removal_queued' => 'Redis kaldırma kuyruğa alındı.',
    ],

    // ── Region Migration ──
    'migration' => [
        'in_progress' => 'Taşıma zaten devam ediyor.',
        'started' => 'Taşıma başlatıldı.',
        'not_dns_phase' => 'Taşıma DNS kontrol aşamasında değil.',
        'completed' => 'Taşıma tamamlandı.',
        'cannot_rollback' => 'Mevcut durumda taşıma geri alınamaz.',
        'rolled_back' => 'Taşıma geri alındı.',
    ],

    // ── Tenant ──
    'tenant' => [
        'deleted' => 'Tenant silindi.',
        'deletion_started' => 'Tenant silme işlemi başladı.',
        'deletion_running' => 'Bu tenant zaten siliniyor.',
        'deleting' => 'Bu tenant siliniyor; değişiklik kabul edilmiyor.',
        'deletion_failed' => 'Tenant silme tamamlanamadı: :failures',
        'access_denied' => 'Erişim reddedildi.',
        'no_context' => 'Aktif tenant bağlamı yok.',
        'only_owners_admins' => 'Sadece sahipler ve yöneticiler tenant kimliğine bürünebilir.',
        'unauthorized' => 'Yetkisiz tenant erişimi.',
        'admin_required' => 'Yönetici erişimi gerekli.',
        'not_member' => 'Bu tenant\'ın üyesi değilsiniz.',
        'not_found' => 'Tenant bulunamadı.',
        'region_not_assignable' => 'Tenant\'a ait bölgeler atanamaz.',
        'no_region_access' => 'Tenant\'ın bu bölgeye erişimi yok.',
        'quota_exceeded' => 'Kota aşıldı.',
        'already_impersonating' => 'Zaten kimliğe bürünme oturumu aktif. Önce mevcut oturumu durdurun.',
        'impersonation_started' => 'Kimliğe bürünme başlatıldı.',
        'no_impersonation' => 'Aktif kimliğe bürünme oturumu yok.',
        'impersonation_ended' => 'Kimliğe bürünme sonlandırıldı.',
        'cpu_quota_exceeded' => 'CPU kotası kalan kapasiteyi aşıyor (:remaining m kullanılabilir).',
        'memory_quota_exceeded' => 'Bellek kotası kalan kapasiteyi aşıyor (:remaining MB kullanılabilir).',
    ],

    // ── Tenant User ──
    'tenant_user' => [
        'already_in' => 'Kullanıcı zaten tenant\'ta.',
        'added' => 'Kullanıcı eklendi.',
        'invitation_pending' => 'Davet zaten beklemede.',
        'invitation_sent' => 'Davet gönderildi.',
        'cannot_change_owner' => 'Sahip rolü değiştirilemez.',
        'cannot_change_self' => 'Kendi rolünüzü değiştiremezsiniz.',
        'role_updated' => 'Rol güncellendi.',
        'cannot_remove_owner' => 'Sahip kaldırılamaz.',
        'cannot_remove_self' => 'Kendinizi kaldıramazsınız.',
        'removed' => 'Kullanıcı tenant\'tan kaldırıldı.',
        'invitation_cancelled' => 'Davet iptal edildi.',
        'invitation_resent' => 'Davet yeniden gönderildi.',
        'cannot_change_admin' => 'Kendi yönetici durumunuzu değiştiremezsiniz.',
        'admin_updated' => 'Yönetici durumu güncellendi.',
    ],

    // ── Invitation ──
    'invitation' => [
        'not_found' => 'Davet bulunamadı veya zaten kullanılmış.',
        'expired' => 'Davetin süresi dolmuş.',
        'invalid_password' => 'Geçersiz şifre.',
        'accepted' => 'Davet kabul edildi.',
    ],

    // ── OAuth ──
    'oauth' => [
        'invalid_code' => 'Giriş bağlantısının süresi dolmuş ya da bağlantı zaten kullanılmış. Yeniden giriş yapın.',
        'provider_deleted' => 'Sağlayıcı silindi.',
        'unauthorized' => 'Yetkisiz.',
        'account_unlinked' => 'Sosyal hesap bağlantısı kaldırıldı.',
        'provider_not_found' => 'Sağlayıcı bulunamadı veya devre dışı.',
        'no_email' => 'Sağlayıcı bir e-posta adresi döndürmedi.',
        'account_not_found' => 'Hesap bulunamadı. Yöneticinize başvurun.',
        'invalid_state' => 'Geçersiz veya süresi dolmuş durum parametresi.',
    ],

    // ── SAML 2.0 ──
    'saml' => [
        'invalid_certificate' => 'Geçerli bir PEM X.509 sertifikası değil.',
        'invalid_private_key' => 'Geçerli, şifresiz bir PEM özel anahtarı değil.',
        'key_mismatch' => 'Özel anahtar SP sertifikasına ait değil.',
        'sp_certificate_required' => 'AuthnRequest imzalamak için SP sertifikası gerekir.',
        'sp_key_required' => 'AuthnRequest imzalamak için SP özel anahtarı gerekir.',
        'invalid_response' => 'SAML yanıtı reddedildi.',
        'unknown_request' => 'SAML yanıtı burada başlatılmış bir girişe ait değil veya daha önce kullanıldı.',
    ],

    // ── Logs ──
    'logs' => [
        'demo_no_logs' => "Demo bölge — pod'lar sanal olarak çalışır, gerçek log mevcut değil.\n",
        'no_kubeconfig' => 'Bölge kubeconfig yapılandırılmamış.',
        'pods_cleaned' => "Pod'lar temizlendi — bu dağıtım için loglar artık mevcut değil.\n",
        'no_pods' => "Pod bulunamadı. Servis sıfıra ölçeklenmiş olabilir veya henüz başlamamış olabilir.\n",
        'no_output' => "Henüz log çıktısı yok.\n",
    ],

    // ── Shell ──
    'shell' => [
        'requires_active' => 'Shell erişimi aktif veya çalışan bir dağıtım gerektirir.',
        'no_kubeconfig' => 'Bölge kubeconfig yapılandırılmamış.',
        'demo_not_allowed' => 'Demo bölgelerde shell erişimi kullanılamaz.',
        'missing_token' => 'Token eksik.',
        'session_expired' => 'Oturum bulunamadı veya süresi dolmuş.',
        'invalid_config' => 'Geçersiz oturum yapılandırması.',
        'wake_failed' => 'Uyandırma işlemi başlatılamadı.',
        'wake_ended' => 'Uyandırma işlemi beklenmedik şekilde sonlandı.',
        'pod_not_found' => 'Pod bulunamadı.',
        'start_failed' => 'Shell başlatılamadı.',
        'idle_closed' => '[Oturum hareketsizlik nedeniyle kapatıldı]',
        'log_missing_token' => 'Token eksik.',
        'log_session_expired' => 'Oturum bulunamadı veya süresi dolmuş.',
        'log_start_failed' => 'Log akışı başlatılamadı.',
    ],

    // ── Account ──
    'account' => [
        'profile_updated' => 'Profil güncellendi.',
        'avatar_uploaded' => 'Avatar yüklendi.',
        'avatar_removed' => 'Avatar kaldırıldı.',
        'wrong_password' => 'Mevcut şifre yanlış.',
        'password_changed' => 'Şifre değiştirildi.',
        'disabled' => 'Hesap devre dışı bırakıldı.',
        'deleted' => 'Hesap silindi.',
        'account_disabled' => 'Hesabınız devre dışı bırakılmış.',
        'invalid_2fa_code' => 'Geçersiz doğrulama kodu.',
        '2fa_enabled' => 'İki faktörlü kimlik doğrulama etkinleştirildi.',
        '2fa_disabled' => 'İki faktörlü kimlik doğrulama devre dışı bırakıldı.',
        'cannot_revoke_current' => 'Mevcut oturum iptal edilemez.',
        'session_revoked' => 'Oturum iptal edildi.',
        'all_sessions_revoked' => 'Diğer tüm oturumlar iptal edildi.',
        'preferences_updated' => 'Bildirim tercihleri güncellendi.',
        '2fa_required' => 'İki faktörlü kimlik doğrulama kodu gerekli.',
        'invalid_recovery_code' => 'Geçersiz kurtarma kodu.',
    ],

    // ── Contact ──
    'contact' => [
        'sent' => 'Mesajınız başarıyla gönderildi.',
    ],

    // ── Destek talepleri (bildirim başlıkları) ──
    'tickets' => [
        'created_title' => 'Yeni destek talebi: :subject',
        'reply_title' => 'Destek talebine yanıt: :subject',
        'status_title' => 'Destek talebinizin durumu güncellendi: :subject',
        'staff_reply_title' => 'Destek talebinize yanıt geldi: :subject',
    ],

    // ── Özel bağlantılar (Skupper) ──
    'private_connection' => [
        'no_region' => 'Projenin kubeconfig tanımlı bir bölgesi olmalı.',
        'skupper_unavailable' => ':region bölgesinde Skupper v2 (skupper.io/v2alpha1) kurulu değil.',
        'routing_key_in_use' => 'Bu routing key bölgede başka bir kiracı tarafından kullanılıyor.',
        'env_prefix_taken' => 'Projenin başka bir özel bağlantısı aynı ortam değişkeni önekini kullanıyor.',
        'region_unreachable' => 'Bölgeye ulaşılamadı: :error',
        'apply_failed' => 'Özel bağlantı uygulanamadı: :error',
        'remove_failed' => 'Özel bağlantı bölgeden kaldırılamadı. Tekrar deneyin.',
        'deleted' => 'Özel bağlantı kaldırıldı.',
    ],

    // ── Bağlamalar (docs/bindings.md) ──
    'bindings' => [
        'region_unavailable' => ':region bölgesinde bağlamalar için kullanılabilir bir küme (kubeconfig) yok.',
        'region_missing' => 'Bağlamanın bölgesi yok.',
        'region_mismatch' => ':binding bağlaması :project projesinden farklı bir bölgede.',
        'project_without_region' => 'Projeye bağlama oluşturmak için önce bir bölge seçilmeli.',
        'service_not_ready' => ':region bölgesinin :service veri servisi kurulu değil ya da hazır değil.',
        'service_not_installed' => ':service veri servisi kurulu değil.',
        'service_busy' => ':service veri servisi kuruluyor ya da kaldırılıyor. İşlem bitince tekrar deneyin.',
        'service_in_use' => ':service veri servisinde hâlâ :count bağlama var. Önce onları silin.',
        'service_install_queued' => ':service veri servisi kuruluyor.',
        'service_uninstall_queued' => ':service veri servisi kaldırılıyor.',
        'service_timeout' => ':service zamanında hazır olmadı.',
        'service_pods_not_ready' => 'Servisin bütün pod\'ları hazır değil.',
        'quota_exceeded' => 'Bağlama limitine ulaşıldı (:key: :limit içinden :used kullanılıyor, :requested isteniyor).',
        'not_active' => ':name bağlaması siliniyor.',
        'not_ready' => ':name bağlaması :status durumunda, hazır değil.',
        'not_deleting' => ':name bağlaması silinmek üzere işaretli değil.',
        'already_attached' => ':name bağlaması bu projeye zaten bağlı.',
        'env_conflict' => 'Bu ortam değişkenlerini projenin başka bir bağlaması zaten tanımlıyor: :names. Başka bir önek kullanın.',
        'confirm_name' => 'Silmeyi onaylamak için bağlamanın adını yazın.',
        'deletion_scheduled' => 'Bağlama ayrıldı ve silinmek üzere işaretlendi. Geri yüklemezseniz verisi :date tarihinde silinir.',
        'restored' => 'Bağlama geri yüklendi. Kullanmak için projelere yeniden bağlayın.',
        'detached' => 'Bağlama projeden ayrıldı.',
        'rotation_queued' => 'Kimlik bilgileri yenileniyor. Bağlı fonksiyonlar yenileriyle yeniden başlar.',
        'rotation_failed' => 'Kimlik bilgisi yenileme başarısız: :error',
        'purge_failed' => 'Bağlama verisi silinemedi: :error',
        'over_quota' => 'Boyut limitinin üzerinde: veri silinene ya da limit artırılana kadar yazma reddedilir.',
        'storage_shrink' => 'Ayrılmış Postgres kümesinin depolama alanı yalnızca büyüyebilir.',
        'values_external_only' => 'Yalnızca harici bağlamaların değerleri olur.',
        'invalid_env_key' => ':key geçerli bir ortam değişkeni adı değil (A-Z, 0-9, _; harfle başlar).',
        'dedicated_postgres_only' => 'Ayrılmış plan yalnızca Postgres bağlamalarında var.',
        'kv_region_redis_missing' => 'Bölgede hazır bir Redis yok (Bölge → Redis).',
        'kv_region_password_required' => 'Bölge Redis\'i üzerindeki KV bağlamaları için Redis parolası gerekir: parola olmadan namespace\'teki her pod tam erişime sahip olur.',
        'kv_unreachable' => 'Redis\'e bölgeden ulaşılamadı: :error',
        'kv_no_free_database' => 'Bölge Redis\'inde boş mantıksal veritabanı (1–15) kalmadı.',
        'kv_operation_failed' => 'Redis işlemi :operation başarısız: :error',
        'object_admin_failed' => 'Nesne deposu yönetim API çağrısı başarısız: :error',
        'object_operation_failed' => 'Nesne deposu işlemi başarısız: :error',
        'object_bucket_missing' => ':bucket bucket\'ı nesne deposunda yok.',
        'object_unreachable' => 'Nesne deposuna ulaşılamadı.',
        'object_used_by_backups' => 'Postgres yedekleri nesne deposunu kullanıyor. Önce yedekleri kapatın.',
        'backups_need_object_store' => 'Postgres yedekleri için bölgenin nesne deposu gerekir (Bölge → Veri servisleri).',
        'cnpg_install_failed' => 'CloudNativePG kurulamadı: :error',
        'postgres_cluster_failed' => ':cluster Postgres kümesi oluşturulamadı.',
        'postgres_cluster_missing' => ':cluster Postgres kümesi yok.',
        'postgres_sql_failed' => 'Postgres işlemi başarısız: :error',
    ],

    // ── Faturalama ──
    'billing' => [
        'invalid_period' => 'Dönemi YYYY-MM biçiminde verin.',
        'range_too_long' => 'Aralık en fazla :days gün olabilir.',
        'purchase_ref_taken' => 'Bu external_ref başka bir tenant ya da başka bir satın alma türü için kayıtlı.',
        'credit_amount_required' => 'Kredi için 0\'dan büyük bir tutar ve para birimi gerekir.',
        'addon_limits_required' => 'Ek paket için 0\'dan büyük en az bir limit gerekir.',
        'ends_at_past' => 'ends_at gelecekte bir zaman olmalı.',
        'webhook_not_configured' => 'Faturalama webhook\'u yapılandırılmamış (Ayarlar → Planlar ve Faturalama ya da BILLING_WEBHOOK_URL).',
    ],

    // ── Rate limiting ──
    'rate_limit' => [
        'exceeded' => 'Çok fazla istek. :seconds saniye sonra tekrar deneyin.',
    ],

    // ── Runtimes (runtime eklentileri) ──
    'runtimes' => [
        'unknown' => "':id' runtime'ı kullanılamıyor.",
        'disabled' => ':name runtime\'ı platform yöneticisi tarafından devre dışı bırakıldı.',
        'not_in_region' => ':name runtime\'ı bu bölgede sunulmuyor.',
        'trigger_unsupported' => ':name runtime\'ı :trigger tetikleyicisini desteklemiyor.',
        'sandbox_unsupported' => ':name runtime\'ı, bu bölgenin :type projeleri için kullandığı :sandbox sandbox\'ında çalışmıyor.',
        'id_taken' => "':id' kimliğine sahip bir runtime zaten var.",
        'id_immutable' => "Runtime kimliği değiştirilemez (':id').",
        'runtime_class_platform_only' => 'runtimeClass yalnızca platform runtime\'larında kullanılabilir.',
        'invalid_manifest' => 'Runtime manifest\'i geçerli değil.',
        'file_name_invalid' => 'Geçersiz dosya adı ":name": yalnızca harf, rakam, ".", "-" ve "_" kullanın (klasör yok).',
        'files_too_large' => 'Yüklenen dosyalar çok büyük: toplamda en fazla :kb KB.',
        'builtin_readonly' => 'Yerleşik runtime\'lar sürümle gelir: yalnızca kullanılabilirlikleri (etkin, bölgeler) değiştirilebilir.',
        'builtin_delete' => 'Yerleşik runtime\'lar silinemez; bunun yerine devre dışı bırakın.',
        'in_use' => 'Bu runtime\'ı :count proje kullanıyor; önce onları taşıyın ya da silin.',
        'no_source_versions' => 'Bu runtime\'ın dockerfile ya da git kaynağından derlenen bir sürümü yok.',
        'test_deploy_description' => ':name runtime\'ının test dağıtımı',
        'custom_not_allowed' => 'Bu platformda özel runtime\'lar etkin değil.',
    ],

    // ── Gizli ortam değişkenleri ──
    'secret' => [
        'value_too_large' => 'Gizli bir değer en fazla :max KiB olabilir.',
        'too_many' => 'Bir projenin en fazla :max gizli değişkeni olabilir.',
        'name_is_plain' => '":key" bu projede zaten düz bir ortam değişkeni; önce oradan kaldırın.',
        'plain_is_secret' => '":key" bu projenin gizli değişkeni; Gizli değişkenler bölümünden değiştirin.',
    ],

    // ── Denetim kaydı ──
    'audit' => [
        'settings_forbidden' => 'Denetim kaydı saklama süresini yalnız platform yöneticisi değiştirebilir.',
    ],

    // ── Misc ──
    'all_fields_required' => 'Tüm alanlar zorunludur.',
    'internal_error' => 'Dahili hata.',
];
