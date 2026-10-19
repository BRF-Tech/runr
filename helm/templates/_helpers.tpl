{{/*
Common labels
*/}}
{{- define "runr.labels" -}}
app.kubernetes.io/managed-by: {{ .Release.Service }}
app.kubernetes.io/instance: {{ .Release.Name }}
helm.sh/chart: {{ .Chart.Name }}-{{ .Chart.Version }}
{{- end }}

{{/*
Install namespace: the Helm release namespace when values.namespace is empty (marketplace install)
*/}}
{{- define "runr.namespace" -}}
{{- default .Release.Namespace .Values.namespace -}}
{{- end -}}

{{/*
ImagePullSecrets: values.imagePullSecrets (names or {name: ...}) plus, when
imagePullSecretsFromDefaultServiceAccount is on, the imagePullSecrets of the install namespace's
"default" ServiceAccount (lookup). Marketplace installers often attach their pull secret only to the
default SA while RunR pods run as the runr-api SA (or without one), so the secret is carried to every
pod (hooks, jobs and the UI included) without knowing its name. The lookup is empty under helm template / --dry-run.
A missing key counts as on: `helm upgrade --reuse-values` keeps the old chart's values (the new key is absent).
*/}}
{{- define "runr.imagePullSecrets" -}}
{{- $names := list -}}
{{- range .Values.imagePullSecrets -}}
{{- $name := "" -}}
{{- if kindIs "string" . -}}{{- $name = . -}}{{- else -}}{{- $name = get . "name" -}}{{- end -}}
{{- if and $name (not (has $name $names)) -}}{{- $names = append $names $name -}}{{- end -}}
{{- end -}}
{{- if ne .Values.imagePullSecretsFromDefaultServiceAccount false -}}
{{- $sa := lookup "v1" "ServiceAccount" (include "runr.namespace" .) "default" -}}
{{- range (dig "imagePullSecrets" (list) $sa) -}}
{{- $name := get . "name" -}}
{{- if and $name (not (has $name $names)) -}}{{- $names = append $names $name -}}{{- end -}}
{{- end -}}
{{- end -}}
{{- with $names }}
imagePullSecrets:
{{- range . }}
  - name: {{ . | quote }}
{{- end }}
{{- end }}
{{- end }}

{{/*
envFrom: ConfigMap reference
*/}}
{{- define "runr.envFrom" -}}
envFrom:
  - configMapRef:
      name: runr-config
{{- end -}}

{{/*
Where a password comes from → JSON {name, key} or {} when none is configured. Takes (dict "root" .
"values" <database | mail | redis> "key" <key in the chart's Secret> "required" bool):
  <values>.existingSecret set → that Secret, key <values>.existingSecretPasswordKey (default "password");
  <values>.password set       → the chart's Secret (secretName), key <key> (secret.yaml writes it);
  required (the database)     → the chart's Secret, key <key> (generated, or put there yourself with
                                secret.create=false);
  otherwise                   → {} (no env var: e.g. an SMTP server without authentication).
Values reused from releases before these keys (`--reuse-values`) have none of them.
*/}}
{{- define "runr.passwordRef" -}}
{{- $v := .values | default dict -}}
{{- if $v.existingSecret -}}
{{- toJson (dict "name" $v.existingSecret "key" ($v.existingSecretPasswordKey | default "password")) -}}
{{- else if or $v.password .required -}}
{{- toJson (dict "name" .root.Values.secretName "key" .key) -}}
{{- else -}}
{}
{{- end -}}
{{- end -}}

{{/*
Secret env vars: APP_KEY (+ APP_PREVIOUS_KEYS during a key rotation), REVERB_APP_SECRET, DB_PASSWORD,
MAIL_PASSWORD / REDIS_PASSWORD when configured (runr.passwordRef)
*/}}
{{- define "runr.secret-env" -}}
- name: APP_KEY
  valueFrom:
    secretKeyRef:
      name: {{ .Values.secretName }}
      key: APP_KEY
{{- /* Comma-separated earlier keys: values encrypted with them still decrypt (docs/security.md) */}}
- name: APP_PREVIOUS_KEYS
  valueFrom:
    secretKeyRef:
      name: {{ .Values.secretName }}
      key: APP_PREVIOUS_KEYS
      optional: true
- name: REVERB_APP_SECRET
  valueFrom:
    secretKeyRef:
      name: {{ .Values.secretName }}
      key: REVERB_APP_SECRET
{{- range $env, $ref := dict "DB_PASSWORD" (include "runr.passwordRef" (dict "root" $ "values" $.Values.database "key" "DB_PASSWORD" "required" true) | fromJson) "MAIL_PASSWORD" (include "runr.passwordRef" (dict "root" $ "values" $.Values.mail "key" "MAIL_PASSWORD") | fromJson) "REDIS_PASSWORD" (include "runr.passwordRef" (dict "root" $ "values" $.Values.redis "key" "REDIS_PASSWORD") | fromJson) }}
{{- if $ref.name }}
- name: {{ $env }}
  valueFrom:
    secretKeyRef:
      name: {{ $ref.name | quote }}
      key: {{ $ref.key | quote }}
{{- end }}
{{- end }}
- name: ADMIN_PASSWORD
  valueFrom:
    secretKeyRef:
      name: {{ .Values.secretName }}
      key: ADMIN_PASSWORD
      optional: true
{{- /* Billing events signing secret (docs/billing.md) and signup CAPTCHA secret (docs/white-label.md), optional */}}
{{- range $key := list "BILLING_WEBHOOK_SECRET" "SIGNUP_CAPTCHA_SECRET" }}
- name: {{ $key }}
  valueFrom:
    secretKeyRef:
      name: {{ $.Values.secretName }}
      key: {{ $key }}
      optional: true
{{- end }}
{{- /* Branding logos given as a reference to an existing ConfigMap / Secret key (values branding.*) */}}
{{- $brand := .Values.branding | default dict }}
{{- $logo := $brand.logo | default dict }}
{{- $mail := $brand.email | default dict }}
{{- range $name, $ref := dict "BRANDING_LOGO_LIGHT" $logo.light "BRANDING_LOGO_DARK" $logo.dark "BRANDING_LOGO_MARK" $logo.mark "BRANDING_FAVICON" $brand.favicon "BRANDING_EMAIL_LOGO" $mail.logo }}
{{- if kindIs "map" $ref }}
{{- $kind := ternary "configMapKeyRef" "secretKeyRef" (hasKey $ref "configMapKeyRef") }}
{{- $src := index $ref $kind }}
- name: {{ $name }}
  valueFrom:
    {{ $kind }}:
      name: {{ $src.name | quote }}
      key: {{ $src.key | quote }}
      optional: {{ $src.optional | default false }}
{{- end }}
{{- end }}
{{- end -}}

{{/*
Image reference in RunR's image namespace ghcr.io/brf-tech/runr: a reference of RunR's earlier
namespaces — values an install set, or the previous chart's defaults that `helm upgrade
--reuse-values` reuses — is the same image there (App\Support\Images\RegistryMove), a leading
"runr-" / "runr/" of the name dropped:
  ghcr.io/runrsh/<name> (up to 0.5.0)                         → ghcr.io/brf-tech/runr/<name>
  ghcr.io/brf-tech/<RunR's package> (0.5.1: reqs/…, exec-…,
  runr-…, hf-serve, image-upload, containerd-shim-spin, …)     → ghcr.io/brf-tech/runr/<name>
Exact prefix only: other packages of ghcr.io/brf-tech and other registries stay as given.
*/}}
{{- define "runr.image" -}}
{{- $ref := regexReplaceAll "^ghcr\\.io/runrsh/(runr[/-])?" (toString .) "ghcr.io/brf-tech/runr/" -}}
{{- $ref = regexReplaceAll "^ghcr\\.io/brf-tech/runr-" $ref "ghcr.io/brf-tech/runr/" -}}
{{- regexReplaceAll "^ghcr\\.io/brf-tech/(reqs/|exec-|(hf-serve|image-upload|containerd-shim-spin|ai-gpu-caas|nginx-test)([:@/]|$))" $ref "ghcr.io/brf-tech/runr/${1}" -}}
{{- end -}}

{{/*
initContainer image (fix-permissions / fix-pgdata) — the ghcr.io/brf-tech/runr/reqs mirror instead of docker.io
*/}}
{{- define "runr.initImage" -}}
{{- include "runr.image" (printf "%v:%v" .Values.initImage.repository .Values.initImage.tag) | quote -}}
{{- end -}}

{{/*
initContainer: api-storage permission fix (www-data UID 82)
*/}}
{{- define "runr.storage-init" -}}
{{- if .Values.api.storage.fixPermissions }}
initContainers:
  - name: fix-permissions
    image: {{ include "runr.initImage" . }}
    command: ["sh", "-c", "chown -R 82:82 /storage && chmod -R 775 /storage"]
    volumeMounts:
      - name: api-storage
        mountPath: /storage
{{- end }}
{{- end }}

{{/*
initContainer: postgres lost+found fix + PGDATA subdir
*/}}
{{- define "runr.postgres-init" -}}
{{- if .Values.database.postgres.fixPgdata }}
initContainers:
  - name: fix-pgdata
    image: {{ include "runr.initImage" . }}
    command: ["sh", "-c", "rm -rf /data/lost+found && mkdir -p /data/pgdata && chown -R 70:70 /data/pgdata"]
    volumeMounts:
      - name: postgres-data
        mountPath: /data
{{- end }}
{{- end }}

{{/*
Access modes of the API volume (api.storage.accessModes) as JSON; values reused from releases before
the key (`--reuse-values`) have none: ReadWriteOnce, the mode every earlier release created.
*/}}
{{- define "runr.apiStorageAccessModes" -}}
{{- toJson ((.Values.api.storage | default dict).accessModes | default (list "ReadWriteOnce")) -}}
{{- end -}}

{{/*
"true" when the API volume is ReadWriteMany: the pods that mount it can run on any node, so they are
not colocated and the API's PodDisruptionBudget can let a drain move them.
*/}}
{{- define "runr.apiStorageShared" -}}
{{- if has "ReadWriteMany" (include "runr.apiStorageAccessModes" . | fromJsonArray) -}}true{{- end -}}
{{- end -}}

{{/*
"true" when the pods that mount the API volume are colocated: podColocation on and the volume
ReadWriteOnce (a ReadWriteOnce volume is attached to one node at a time).
*/}}
{{- define "runr.colocate" -}}
{{- if and .Values.podColocation (not (include "runr.apiStorageShared" .)) -}}true{{- end -}}
{{- end -}}

{{/*
An existing PersistentVolumeClaim keeps its access modes and StorageClass: Kubernetes refuses to change
them. Takes (dict "root" . "name" <claim> "modes" <list or nil> "class" <string> "field" <values path>).
When the claim exists (lookup: only when Helm runs against the cluster) and the values ask for other
modes or another StorageClass, the install stops with a message instead of the API server's
"spec is immutable"; docs/install.md describes moving the API volume to ReadWriteMany.
*/}}
{{- define "runr.pvcGuard" -}}
{{- $pvc := lookup "v1" "PersistentVolumeClaim" (include "runr.namespace" .root) .name -}}
{{- if $pvc -}}
{{- $spec := $pvc.spec | default dict -}}
{{- $have := sortAlpha ($spec.accessModes | default list) -}}
{{- if and .modes (ne (toJson $have) (toJson (sortAlpha .modes))) -}}
{{- fail (printf "%s.accessModes %s: the PersistentVolumeClaim %s exists with %s, and Kubernetes does not change the access modes of a claim. Keep %s, or move the volume to a new claim as docs/install.md (Storage) describes" .field (toJson .modes) .name (toJson $have) (toJson $have)) -}}
{{- end -}}
{{- if and .class $spec.storageClassName (ne (toString .class) (toString $spec.storageClassName)) -}}
{{- fail (printf "%s.storageClassName %q: the PersistentVolumeClaim %s exists with StorageClass %q, and Kubernetes does not change the StorageClass of a claim. Set %q or remove the value" .field .class .name $spec.storageClassName $spec.storageClassName) -}}
{{- end -}}
{{- end -}}
{{- end -}}

{{/*
Placement of a RunR pod: nodeSelector, affinity and tolerations. Takes (dict "root" . "colocate" bool
"affinity" <the component's own affinity, optional>).
- placement.nodeSelector / placement.affinity / placement.tolerations as the operator set them;
  gateway.affinity / api.affinity / ui.affinity, when set, replace placement.affinity for that
  component's pods;
- placement.tolerateDedicatedWorkers: the toleration of the dedicated-worker taint
  (runr.dev/dedicated=projects:NoSchedule, a region's worker selection on this cluster) and a
  preferred node affinity for the other nodes, both added to the operator's: a pod whose volume
  (PostgreSQL, the API storage) is bound to a node selected as a dedicated worker later starts
  there again;
- colocate + runr.colocate (podColocation and a ReadWriteOnce API volume): the pods sharing the API
  storage on the same node (pod affinity).
*/}}
{{- define "runr.placement" -}}
{{- $p := .root.Values.placement | default dict -}}
{{- $affinity := deepCopy (.affinity | default ($p.affinity | default dict)) -}}
{{- $tolerations := list -}}
{{- range ($p.tolerations | default list) }}
{{- $tolerations = append $tolerations . -}}
{{- end }}
{{- /* on unless set to false: values reused from a release before the switch existed have none */ -}}
{{- $tolerate := true -}}
{{- if hasKey $p "tolerateDedicatedWorkers" }}
{{- $tolerate = $p.tolerateDedicatedWorkers -}}
{{- end }}
{{- if $tolerate }}
{{- $toleration := dict "key" "runr.dev/dedicated" "operator" "Equal" "value" "projects" "effect" "NoSchedule" -}}
{{- if not (has $toleration $tolerations) }}
{{- $tolerations = append $tolerations $toleration -}}
{{- end }}
{{- $node := $affinity.nodeAffinity | default dict -}}
{{- $preferred := $node.preferredDuringSchedulingIgnoredDuringExecution | default list -}}
{{- $other := dict "weight" 100 "preference" (dict "matchExpressions" (list (dict "key" "runr.dev/dedicated" "operator" "NotIn" "values" (list "projects")))) -}}
{{- $_ := set $node "preferredDuringSchedulingIgnoredDuringExecution" (append $preferred $other) -}}
{{- $_ := set $affinity "nodeAffinity" $node -}}
{{- end }}
{{- if and .colocate (include "runr.colocate" .root) }}
{{- $pod := $affinity.podAffinity | default dict -}}
{{- $required := $pod.requiredDuringSchedulingIgnoredDuringExecution | default list -}}
{{- $group := dict "labelSelector" (dict "matchLabels" (dict "runr/storage-group" "api")) "topologyKey" "kubernetes.io/hostname" -}}
{{- $_ := set $pod "requiredDuringSchedulingIgnoredDuringExecution" (append $required $group) -}}
{{- $_ := set $affinity "podAffinity" $pod -}}
{{- end }}
{{- with $p.nodeSelector }}
nodeSelector:
  {{- toYaml . | nindent 2 }}
{{- end }}
{{- with $affinity }}
affinity:
  {{- toYaml . | nindent 2 }}
{{- end }}
{{- with $tolerations }}
tolerations:
  {{- toYaml . | nindent 2 }}
{{- end }}
{{- end }}

{{/*
Colocation label (added to the pod labels)
*/}}
{{- define "runr.colocation-label" -}}
{{- if include "runr.colocate" . }}
runr/storage-group: api
{{- end }}
{{- end }}

{{/*
replicas of a Deployment with an optional HorizontalPodAutoscaler. Takes (dict "root" . "name"
<Deployment> "replicas" <value> "hpa" <the component's hpa values>).
Without the HPA: the value. With it the HPA owns the count, so an upgrade must not reset it: the
Deployment's current count when it exists (lookup: an unchanged field is no change), otherwise no
replicas field (the first install, and tools that render without cluster access such as helm
template; the HPA raises the Deployment to minReplicas).
*/}}
{{- define "runr.replicas" -}}
{{- if (.hpa | default dict).enabled -}}
{{- $live := lookup "apps/v1" "Deployment" (include "runr.namespace" .root) .name -}}
{{- with (($live | default dict).spec | default dict).replicas -}}
replicas: {{ int . }}
{{- end -}}
{{- else -}}
replicas: {{ .replicas }}
{{- end -}}
{{- end -}}

{{/*
NetworkPolicy egress rule: DNS (UDP and TCP 53) to any resolver — the cluster DNS Service and its
pods carry different labels per distribution
*/}}
{{- define "runr.networkPolicyDns" -}}
- ports:
    - port: 53
      protocol: UDP
    - port: 53
      protocol: TCP
{{- end -}}

{{/*
Values the chart does not support stop the install with a message (rendered by configmap.yaml).
*/}}
{{- define "runr.validate" -}}
{{- $db := .Values.database | default dict -}}
{{- if ne (toString ($db.driver | default "pgsql")) "pgsql" -}}
{{- fail (printf "database.driver %q is not supported by the Helm chart: the API, Horizon, scheduler and Reverb run as separate pods that write to the database at the same time, which SQLite cannot serve. Leave database.driver at pgsql: the chart's PostgreSQL (database.host empty) or your own (database.host)" (toString $db.driver)) -}}
{{- end -}}
{{- if not .Values.secret.create -}}
{{- range $field, $section := dict "database" $db "mail" (.Values.mail | default dict) "redis" (.Values.redis | default dict) -}}
{{- if $section.password -}}
{{- fail (printf "%s.password is written to the chart's Secret, which secret.create=false leaves to you: put the password into a Secret and set %s.existingSecret (and %s.existingSecretPasswordKey), or use secret.create=true" $field $field $field) -}}
{{- end -}}
{{- end -}}
{{- end -}}
{{- if lt (int .Values.reverb.replicas) 1 -}}
{{- fail "reverb.replicas must be at least 1: Reverb serves the console events, browser shells and region agents" -}}
{{- end -}}
{{- end -}}

{{/*
Checksum annotation for ConfigMap change detection
+ deploy timestamp for forcing rollout on redeploy (latest tag)
*/}}
{{- define "runr.checksum" -}}
checksum/config: {{ include (print $.Template.BasePath "/configmap.yaml") . | sha256sum }}
{{- with .Values.deployTimestamp }}
deploy-timestamp: {{ . | quote }}
{{- end }}
{{- end -}}

{{/*
Edition: edition=multi-tenant → Tenancy + Auth Provider on; single-tenant → both follow tenancy.enabled / authProvider.enabled
*/}}
{{- define "runr.tenancyEnabled" -}}
{{- or .Values.tenancy.enabled (eq .Values.edition "multi-tenant") -}}
{{- end -}}

{{- define "runr.authProviderEnabled" -}}
{{- or .Values.authProvider.enabled (eq .Values.edition "multi-tenant") -}}
{{- end -}}

{{/*
Ticket module (values ticket.enabled). `--reuse-values` with older values has no ticket key →
"true", the module state of the releases before the value existed (entrypoint default)
*/}}
{{- define "runr.ticketEnabled" -}}
{{- $ticket := .Values.ticket | default dict -}}
{{- if hasKey $ticket "enabled" }}{{ ternary "true" "false" (eq (toString $ticket.enabled) "true") }}{{ else }}true{{ end -}}
{{- end -}}

{{/*
Billing module (values billing.*). `--reuse-values` with older values has no billing.enabled →
"false" (the module did not exist), no currency → USD
*/}}
{{- define "runr.billingEnabled" -}}
{{- $billing := .Values.billing | default dict -}}
{{- ternary "true" "false" (eq (toString $billing.enabled) "true") -}}
{{- end -}}

{{- define "runr.billingCurrency" -}}
{{- $billing := .Values.billing | default dict -}}
{{- $billing.currency | default "USD" | upper -}}
{{- end -}}

{{/*
The module switches as explicit env of the hook Jobs (migrations, bootstrap). A pre-upgrade hook
runs before Helm applies the new ConfigMap: through envFrom runr-config it would see the previous
release's switches — an upgrade that turns billing on would migrate without the Billing module
(no billing tables in the tenant schemas). env wins over envFrom.
*/}}
{{- define "runr.module-env" -}}
- name: TENANCY_ENABLED
  value: {{ include "runr.tenancyEnabled" . | quote }}
- name: AUTH_PROVIDER_ENABLED
  value: {{ include "runr.authProviderEnabled" . | quote }}
- name: TICKET_ENABLED
  value: {{ include "runr.ticketEnabled" . | quote }}
- name: BILLING_ENABLED
  value: {{ include "runr.billingEnabled" . | quote }}
- name: BILLING_CURRENCY
  value: {{ include "runr.billingCurrency" . | quote }}
{{- end -}}

{{/*
UI image: modules are selected at build time, so a multi-tenant install uses a separate UI variant:
<tag><multiTenantSuffix> (published as <version>-mt). The moving tag latest has no such variant: a
multi-tenant install with tag latest gets the chart's release, <appVersion><multiTenantSuffix>.
An empty suffix keeps the tag as it is (values of earlier chart versions reused by `--reuse-values`
carry "", their default).
*/}}
{{- define "runr.uiImage" -}}
{{- $tag := toString .Values.ui.image.tag -}}
{{- if eq .Values.edition "multi-tenant" -}}
{{- $suffix := toString (.Values.ui.image.multiTenantSuffix | default "") -}}
{{- if and $suffix (eq $tag "latest") -}}{{- $tag = .Chart.AppVersion -}}{{- end -}}
{{- $tag = printf "%s%s" $tag $suffix -}}
{{- end -}}
{{- include "runr.image" (printf "%v:%v" .Values.ui.image.repository $tag) -}}
{{- end -}}

{{/*
TLS of the console Ingress → JSON {mode, issuer, secret, fallback} (ingress.yaml, tls-secret.yaml, NOTES.txt).
  letsencrypt — cert-manager issues the certificate: the Ingress gets the cert-manager.io/cluster-issuer annotation
                (clusterIssuer, letsencrypt-prod if empty) + tls (secretName, runr-tls if empty).
                Without the cert-manager.io/v1 API in the cluster, fallback=true: behaves like none.
  custom      — uploaded certificate/privateKey → kubernetes.io/tls Secret (secretName, runr-tls-custom if empty).
  secret      — a kubernetes.io/tls Secret you manage (secretName, required): tls on the Ingress, nothing created.
  none        — no tls on the Ingress; the ingress controller serves HTTPS with its own default certificate.
  ""          — pre-0.3.6 values (and `helm upgrade --reuse-values` with them): enabled / clusterIssuer /
                secretName apply as before (the annotation if clusterIssuer is set, tls if enabled).
*/}}
{{- define "runr.ingressTls" -}}
{{- $tls := .Values.ingress.tls | default dict -}}
{{- $mode := $tls.mode | default "" -}}
{{- $out := dict "mode" $mode "issuer" "" "secret" "" "fallback" false -}}
{{- if eq $mode "letsencrypt" -}}
{{- if .Capabilities.APIVersions.Has "cert-manager.io/v1" -}}
{{- $_ := set $out "issuer" ($tls.clusterIssuer | default "letsencrypt-prod") -}}
{{- $_ := set $out "secret" ($tls.secretName | default "runr-tls") -}}
{{- else -}}
{{- $_ := set $out "fallback" true -}}
{{- end -}}
{{- else if eq $mode "custom" -}}
{{- $_ := set $out "secret" ($tls.secretName | default "runr-tls-custom") -}}
{{- else if eq $mode "secret" -}}
{{- if not $tls.secretName -}}
{{- fail "ingress.tls.mode=secret: set ingress.tls.secretName to your kubernetes.io/tls Secret in the release namespace" -}}
{{- end -}}
{{- $_ := set $out "secret" $tls.secretName -}}
{{- else if eq $mode "" -}}
{{- $_ := set $out "issuer" ($tls.clusterIssuer | default "") -}}
{{- if $tls.enabled -}}
{{- $_ := set $out "secret" ($tls.secretName | default "runr-tls") -}}
{{- end -}}
{{- else if ne $mode "none" -}}
{{- fail (printf "ingress.tls.mode %q is not supported: use letsencrypt, custom, secret or none" $mode) -}}
{{- end -}}
{{- toJson $out -}}
{{- end -}}

{{/*
Uploaded PEM → Secret data (base64). Input: dict value / field / marker.
The PEM is accepted as plain text or base64; a PEM collapsed onto one line (a password field strips line breaks)
is repaired by rebuilding the BEGIN/END lines. If it is empty or not a PEM, the install stops with a clear error.
The value is never written to any output (NOTES included).
*/}}
{{- define "runr.pemB64" -}}
{{- $v := trim (replace "\r" "" (toString (.value | default ""))) -}}
{{- if not $v -}}
{{- fail (printf "ingress.tls.mode=custom: %s is empty (PEM required)" .field) -}}
{{- end -}}
{{- if not (contains "-----BEGIN" $v) -}}
{{- $v = trim (b64dec (regexReplaceAll `\s` $v "")) -}}
{{- end -}}
{{- if not (and (contains "-----BEGIN" $v) (contains .marker $v)) -}}
{{- fail (printf "ingress.tls.mode=custom: %s is not a PEM %s" .field .marker) -}}
{{- end -}}
{{- $v = regexReplaceAll `\s*(-----BEGIN [A-Z0-9 ]+-----)\s*` $v "\n${1}\n" -}}
{{- $v = regexReplaceAll `\s*(-----END [A-Z0-9 ]+-----)\s*` $v "\n${1}\n" -}}
{{- printf "%s\n" (trim $v) | b64enc -}}
{{- end -}}

{{/*
Disk sizes: storage.application / storage.database (the "Advanced settings" fields of marketplace install
forms) override api.storage.size / database.postgres.storage when set. Old values reused with `--reuse-values` have no storage key.
*/}}
{{- define "runr.apiStorageSize" -}}
{{- (.Values.storage | default dict).application | default .Values.api.storage.size -}}
{{- end -}}

{{- define "runr.databaseStorageSize" -}}
{{- (.Values.storage | default dict).database | default .Values.database.postgres.storage -}}
{{- end -}}

{{/*
Organization CPU / RAM ceiling: quota.cpu (vCPU) / quota.memoryGiB (GiB) → env QUOTA_CPU / QUOTA_MEMORY_GIB
(api config/quota.php). 0 or empty → "" (no ceiling). Old values reused with `--reuse-values` have no quota.
*/}}
{{- define "runr.quotaCpu" -}}
{{- toString ((.Values.quota | default dict).cpu | default "") -}}
{{- end -}}

{{- define "runr.quotaMemoryGiB" -}}
{{- toString ((.Values.quota | default dict).memoryGiB | default "") -}}
{{- end -}}

{{/*
Client IP: clientIp.trustedProxies → env TRUSTED_PROXIES (api config/trustedproxy.php)
and the gateway's Caddy trusted_proxies; clientIp.proxyHops → env TRUSTED_PROXY_HOPS, 0 = automatic:
2 with the chart's Ingress (ingress controller + runr-gateway), 1 without (gateway exposed directly).
Old values reused with `--reuse-values` have no clientIp → the defaults (private_ranges, automatic).
*/}}
{{- define "runr.trustedProxies" -}}
{{- $c := .Values.clientIp | default dict -}}
{{- if hasKey $c "trustedProxies" -}}
{{- toString $c.trustedProxies -}}
{{- else -}}
private_ranges
{{- end -}}
{{- end -}}

{{- define "runr.proxyHops" -}}
{{- $hops := int ((.Values.clientIp | default dict).proxyHops | default 0) -}}
{{- if gt $hops 0 -}}
{{- $hops -}}
{{- else if and .Values.ingress.enabled .Values.domain -}}
2
{{- else -}}
1
{{- end -}}
{{- end -}}

{{/* trustedProxies in Caddy's syntax: space-separated, "*" → every address */}}
{{- define "runr.caddyTrustedProxies" -}}
{{- $out := list -}}
{{- range (include "runr.trustedProxies" . | replace "," " " | splitList " " | compact) -}}
{{- if or (eq . "*") (eq . "**") -}}
{{- $out = concat $out (list "0.0.0.0/0" "::/0") -}}
{{- else if eq . "PRIVATE_SUBNETS" -}}
{{- $out = append $out "private_ranges" -}}
{{- else -}}
{{- $out = append $out . -}}
{{- end -}}
{{- end -}}
{{- join " " $out -}}
{{- end -}}
