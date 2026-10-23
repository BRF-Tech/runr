import { describe, it, expect } from 'vitest'
import { storePayload, trustSnippets, isValidStoreName, issuerHost, emptySettings, providerAuth } from '@/utils/secretStores'

// Secret stores (docs/secret-stores.md): the store form's payload (no unknown field, no
// credential) and the trust setup snippets per provider.

const PROVIDERS = [
  { provider: 'vault', auth: ['kubernetes', 'jwt'], settings: { server: true, role: true, auth_mount: false, audience: false } },
  { provider: 'aws', auth: ['workload_identity'], settings: { region: true, role_arn: true } },
]

const TRUST = {
  issuer: 'https://oidc.example.test/cluster-1',
  namespace: 'runr-projects',
  service_account: 'runr-ss-abc',
  subject: 'system:serviceaccount:runr-projects:runr-ss-abc',
  audience: 'sts.amazonaws.com',
}

describe('secret store form', () => {
  it('sends the provider\'s settings only, trimmed, empty ones left out', () => {
    const payload = storePayload({
      name: ' team-vault ', provider: 'vault', auth: 'kubernetes', refresh_interval: '600',
      settings: { server: ' https://vault.example.test ', role: 'runr', auth_mount: '', token: 'placeholder' },
    }, PROVIDERS)
    expect(payload).toEqual({
      name: 'team-vault', provider: 'vault', auth: 'kubernetes', refresh_interval: 600,
      settings: { server: 'https://vault.example.test', role: 'runr' },
    })
  })

  it('leaves the provider out of an update and clears emptied settings', () => {
    const payload = storePayload({ name: 'team-vault', provider: 'vault', auth: 'jwt', refresh_interval: '', settings: { server: 'https://v', role: 'r', auth_mount: '' } }, PROVIDERS, { editing: true })
    expect(payload.provider).toBeUndefined()
    expect(payload.settings).toEqual({ server: 'https://v', role: 'r', auth_mount: '', audience: '' })
    expect(payload.refresh_interval).toBeNull()
  })

  it('knows the provider\'s fields and auth methods', () => {
    expect(emptySettings('aws', PROVIDERS)).toEqual({ region: '', role_arn: '' })
    expect(providerAuth('vault', PROVIDERS)).toEqual(['kubernetes', 'jwt'])
    expect(providerAuth('unknown', PROVIDERS)).toEqual([])
  })

  it('validates names like the API', () => {
    expect(isValidStoreName('team-vault')).toBe(true)
    expect(isValidStoreName('Team')).toBe(false)
    expect(isValidStoreName('-x')).toBe(false)
    expect(isValidStoreName('a'.repeat(64))).toBe(false)
  })
})

describe('trust snippets', () => {
  it('Vault / OpenBao Kubernetes auth: a role bound to the service account and namespace', () => {
    const snippets = trustSnippets({ provider: 'vault', auth: 'kubernetes', settings: { role: 'orders' } }, TRUST)
    const role = snippets.find(s => s.key === 'vaultKubernetesRole').text
    expect(role).toContain('auth/kubernetes/role/orders')
    expect(role).toContain('bound_service_account_names=runr-ss-abc')
    expect(role).toContain('bound_service_account_namespaces=runr-projects')
  })

  it('Vault / OpenBao JWT auth: the issuer keys or discovery, a role bound to subject and audience', () => {
    const snippets = trustSnippets({ provider: 'vault', auth: 'jwt', settings: { auth_mount: 'jwt-eu', role: 'runr' } }, { ...TRUST, audience: 'vault' })
    expect(snippets.map(s => s.key)).toEqual(['vaultJwtConfigKeys', 'vaultJwtConfigDiscovery', 'vaultJwtRole'])
    expect(snippets[0].text).toContain('auth/jwt-eu/config')
    expect(snippets[0].text).toContain('jwt_validation_pubkeys=@keys.pem')
    expect(snippets[1].text).toContain('oidc_discovery_url="https://oidc.example.test/cluster-1"')
    expect(snippets[2].text).toContain('bound_subject="system:serviceaccount:runr-projects:runr-ss-abc"')
    expect(snippets[2].text).toContain('bound_audiences="vault"')
  })

  it('AWS: an OIDC provider for the issuer and a trust policy on sub and aud', () => {
    const [provider, policy] = trustSnippets({ provider: 'aws', auth: 'workload_identity', settings: {} }, TRUST)
    expect(provider.text).toContain('--url https://oidc.example.test/cluster-1')
    const doc = JSON.parse(policy.text)
    expect(doc.Statement[0].Condition.StringEquals).toEqual({
      'oidc.example.test/cluster-1:sub': 'system:serviceaccount:runr-projects:runr-ss-abc',
      'oidc.example.test/cluster-1:aud': 'sts.amazonaws.com',
    })
  })

  it('GCP and Azure: the issuer and the subject', () => {
    const gcp = trustSnippets({ provider: 'gcp', auth: 'workload_identity', settings: {} }, TRUST)
    expect(gcp[0].text).toContain('--issuer-uri="https://oidc.example.test/cluster-1"')
    expect(gcp[0].text).toContain('google.subject=assertion.sub')
    const azure = trustSnippets({ provider: 'azure', auth: 'workload_identity', settings: {} }, TRUST)
    expect(azure[0].text).toContain('--subject "system:serviceaccount:runr-projects:runr-ss-abc"')
    expect(azure[0].text).toContain('api://AzureADTokenExchange')
  })

  it('nothing without a store or a trust answer', () => {
    expect(trustSnippets(null, TRUST)).toEqual([])
    expect(trustSnippets({ provider: 'vault', auth: 'kubernetes' }, null)).toEqual([])
    expect(issuerHost('https://oidc.example.test/x/')).toBe('oidc.example.test/x')
  })
})
