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
Secret env vars: APP_KEY (+ APP_PREVIOUS_KEYS during a key rotation), REVERB_APP_SECRET, DB_PASSWORD
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
{{- if eq .Values.database.driver "pgsql" }}
- name: DB_PASSWORD
  valueFrom:
    secretKeyRef:
      name: {{ .Values.secretName }}
      key: DB_PASSWORD
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
initContainer image (fix-permissions / fix-pgdata) — the ghcr.io/runrsh/reqs mirror instead of docker.io
*/}}
{{- define "runr.initImage" -}}
{{- printf "%s:%s" .Values.initImage.repository .Values.initImage.tag | quote -}}
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
Pod colocation: schedules the pods sharing an RWO PVC onto the same node
*/}}
{{- define "runr.pod-colocation" -}}
{{- if .Values.podColocation }}
affinity:
  podAffinity:
    requiredDuringSchedulingIgnoredDuringExecution:
      - labelSelector:
          matchLabels:
            runr/storage-group: api
        topologyKey: kubernetes.io/hostname
{{- end }}
{{- end }}

{{/*
Colocation label (added to the pod labels)
*/}}
{{- define "runr.colocation-label" -}}
{{- if .Values.podColocation }}
runr/storage-group: api
{{- end }}
{{- end }}

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
UI image: modules are selected at build time, so a multi-tenant install uses a separate UI variant
*/}}
{{- define "runr.uiImage" -}}
{{- $tag := .Values.ui.image.tag -}}
{{- if eq .Values.edition "multi-tenant" -}}{{- $tag = printf "%s%s" $tag .Values.ui.image.multiTenantSuffix -}}{{- end -}}
{{- printf "%s:%s" .Values.ui.image.repository $tag -}}
{{- end -}}

{{/*
TLS of the console Ingress → JSON {mode, issuer, secret, fallback} (ingress.yaml, tls-secret.yaml, NOTES.txt).
  letsencrypt — cert-manager issues the certificate: the Ingress gets the cert-manager.io/cluster-issuer annotation
                (clusterIssuer, letsencrypt-prod if empty) + tls (secretName, runr-tls if empty).
                Without the cert-manager.io/v1 API in the cluster, fallback=true: behaves like none.
  custom      — uploaded certificate/privateKey → kubernetes.io/tls Secret (secretName, runr-tls-custom if empty).
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
{{- else if eq $mode "" -}}
{{- $_ := set $out "issuer" ($tls.clusterIssuer | default "") -}}
{{- if $tls.enabled -}}
{{- $_ := set $out "secret" ($tls.secretName | default "runr-tls") -}}
{{- end -}}
{{- else if ne $mode "none" -}}
{{- fail (printf "ingress.tls.mode %q is not supported: use letsencrypt, custom or none" $mode) -}}
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
