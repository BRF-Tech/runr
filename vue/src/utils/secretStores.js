// Secret stores (docs/secret-stores.md): the provider catalog the console knows when the API does
// not send one, the payload of the store form and the trust setup snippets per provider. A store
// never gets a credential: it trusts the region cluster's service account issuer and the service
// account RunR names (GET /secret-stores/{id}/trust).

export const PROVIDERS = ['vault', 'aws', 'gcp', 'azure']

/** Fields whose value is long text (a textarea), not one line. */
export const MULTILINE_SETTINGS = ['ca_bundle']

/** The form's settings for a provider: every setting the API lists, empty. */
export function emptySettings(provider, providers) {
  const fields = providerSettings(provider, providers)
  return Object.fromEntries(Object.keys(fields).map(key => [key, '']))
}

/** name → required of a provider's settings (from GET /secret-stores `providers`). */
export function providerSettings(provider, providers) {
  return (providers ?? []).find(p => p.provider === provider)?.settings ?? {}
}

/** The auth methods of a provider (the first is the default). */
export function providerAuth(provider, providers) {
  return (providers ?? []).find(p => p.provider === provider)?.auth ?? []
}

/** POST / PUT body: trimmed settings, empty ones left out, no unknown field. */
export function storePayload(form, providers, { editing = false } = {}) {
  const allowed = Object.keys(providerSettings(form.provider, providers))
  const settings = {}
  for (const key of allowed) {
    const value = typeof form.settings?.[key] === 'string' ? form.settings[key].trim() : ''
    if (value !== '' || editing) settings[key] = value
  }
  const payload = { name: form.name.trim(), auth: form.auth, settings }
  if (!editing) payload.provider = form.provider
  const interval = Number(form.refresh_interval)
  payload.refresh_interval = form.refresh_interval === '' || form.refresh_interval == null || Number.isNaN(interval) ? null : interval
  return payload
}

/** A store name: lowercase letters, digits and "-", like the API's rule. */
export function isValidStoreName(name) {
  return /^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/.test(name ?? '') && name.length <= 63
}

/** The host part of an issuer URL ("https://oidc.example.com/cluster" → "oidc.example.com/cluster"). */
export function issuerHost(issuer) {
  return String(issuer ?? '').replace(/^https?:\/\//, '').replace(/\/$/, '')
}

/**
 * Setup snippets for the store's administrator: [{ key, text }] — key names the step
 * (secretStores.trust.snippets.<key> in the locales), text is what to run or paste.
 */
export function trustSnippets(store, trust) {
  if (!store || !trust) return []
  const s = store.settings ?? {}
  const sa = trust.service_account
  const ns = trust.namespace
  const sub = trust.subject ?? `system:serviceaccount:${ns}:${sa}`
  const role = s.role || 'runr'

  if (store.provider === 'vault' && store.auth === 'jwt') {
    const mount = s.auth_mount || 'jwt'
    const aud = trust.audience || 'vault'
    return [
      {
        key: 'vaultJwtConfigKeys',
        text: `bao write auth/${mount}/config \\\n  jwt_validation_pubkeys=@keys.pem`,
      },
      {
        key: 'vaultJwtConfigDiscovery',
        text: `bao write auth/${mount}/config \\\n  oidc_discovery_url="${trust.issuer}"`,
      },
      {
        key: 'vaultJwtRole',
        text: `bao write auth/${mount}/role/${role} \\\n  role_type=jwt \\\n  user_claim=sub \\\n  bound_subject="${sub}" \\\n  bound_audiences="${aud}" \\\n  token_policies=<policy> \\\n  token_ttl=10m`,
      },
    ]
  }

  if (store.provider === 'vault') {
    const mount = s.auth_mount || 'kubernetes'
    return [
      {
        key: 'vaultKubernetesConfig',
        text: `bao write auth/${mount}/config \\\n  kubernetes_host=<API server address of the region's cluster> \\\n  kubernetes_ca_cert=@ca.crt`,
      },
      {
        key: 'vaultKubernetesRole',
        text: `bao write auth/${mount}/role/${role} \\\n  bound_service_account_names=${sa} \\\n  bound_service_account_namespaces=${ns} \\\n  token_policies=<policy> \\\n  token_ttl=10m`,
      },
    ]
  }

  if (store.provider === 'aws') {
    const host = issuerHost(trust.issuer)
    const aud = trust.audience || 'sts.amazonaws.com'
    return [
      {
        key: 'awsOidcProvider',
        text: `aws iam create-open-id-connect-provider \\\n  --url ${trust.issuer} \\\n  --client-id-list ${aud}`,
      },
      {
        key: 'awsTrustPolicy',
        text: JSON.stringify({
          Version: '2012-10-17',
          Statement: [{
            Effect: 'Allow',
            Principal: { Federated: `arn:aws:iam::<account>:oidc-provider/${host}` },
            Action: 'sts:AssumeRoleWithWebIdentity',
            Condition: { StringEquals: { [`${host}:sub`]: sub, [`${host}:aud`]: aud } },
          }],
        }, null, 2),
      },
    ]
  }

  if (store.provider === 'gcp') {
    return [
      {
        key: 'gcpProvider',
        text: `gcloud iam workload-identity-pools providers create-oidc <provider> \\\n  --location=global --workload-identity-pool=<pool> \\\n  --issuer-uri="${trust.issuer}" \\\n  --jwk-json-path=jwks.json \\\n  --attribute-mapping="google.subject=assertion.sub"`,
      },
      {
        key: 'gcpBinding',
        text: `gcloud secrets add-iam-policy-binding <secret> \\\n  --role=roles/secretmanager.secretAccessor \\\n  --member="principal://iam.googleapis.com/projects/<number>/locations/global/workloadIdentityPools/<pool>/subject/${sub}"`,
      },
    ]
  }

  if (store.provider === 'azure') {
    return [
      {
        key: 'azureFederated',
        text: `az identity federated-credential create \\\n  --name runr-${sa} \\\n  --identity-name <managed identity> --resource-group <group> \\\n  --issuer "${trust.issuer}" \\\n  --subject "${sub}" \\\n  --audiences api://AzureADTokenExchange`,
      },
    ]
  }

  return []
}
