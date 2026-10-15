import { describe, expect, it } from 'vitest'
import { clientSnippets, serverName, TOKEN_PLACEHOLDER } from '@/modules/mcp/utils/snippets'
import { argumentsFromForm, formFields, initialValues, initializeMessage, responseFor, rpc } from '@/modules/mcp/utils/inspector'

const URL = 'https://console.example.com/mcp'
const byId = (list) => Object.fromEntries(list.map((s) => [s.id, s.code]))

describe('client snippets', () => {
  it('one-click OAuth: the URL only', () => {
    const s = byId(clientSnippets(URL, { auth: 'oauth' }))
    expect(s.claudeCode).toBe(`claude mcp add --transport http runr ${URL}`)
    expect(JSON.parse(s.cursor)).toEqual({ mcpServers: { runr: { url: URL } } })
    expect(JSON.parse(s.vscode)).toEqual({ servers: { runr: { type: 'http', url: URL } } })
    expect(JSON.parse(s.claudeDesktop)).toEqual({ mcpServers: { runr: { command: 'npx', args: ['-y', 'mcp-remote', URL] } } })
    expect(s.generic).toContain('OAuth 2.1')
  })

  it('API token: an Authorization header everywhere', () => {
    const s = byId(clientSnippets(URL, { auth: 'token', token: '12|abc' }))
    expect(s.claudeCode).toBe(`claude mcp add --transport http runr ${URL} --header "Authorization: Bearer 12|abc"`)
    expect(JSON.parse(s.cursor).mcpServers.runr.headers).toEqual({ Authorization: 'Bearer 12|abc' })
    expect(JSON.parse(s.vscode).servers.runr.headers).toEqual({ Authorization: 'Bearer 12|abc' })
    const desktop = JSON.parse(s.claudeDesktop).mcpServers.runr
    expect(desktop.args).toContain('--header')
    expect(desktop.env).toEqual({ RUNR_AUTH_HEADER: 'Bearer 12|abc' })
  })

  it('uses a placeholder until a token exists, and a valid server name', () => {
    const s = byId(clientSnippets(URL, { auth: 'token', name: 'Weather Tools!' }))
    expect(s.claudeCode).toContain(TOKEN_PLACEHOLDER)
    expect(s.claudeCode).toContain(' weather-tools ')
    expect(serverName('')).toBe('runr')
  })

  it('public servers: no credentials', () => {
    const s = byId(clientSnippets(URL, { auth: 'public' }))
    expect(s.claudeCode).not.toContain('--header')
    expect(s.generic).toContain('none')
  })
})

describe('inspector helpers', () => {
  it('builds requests with increasing ids and notifications without one', () => {
    const a = rpc('tools/list', {})
    const b = rpc('tools/list', {})
    expect(b.id).toBeGreaterThan(a.id)
    const n = rpc('notifications/initialized', undefined, { notification: true })
    expect(n).toEqual({ jsonrpc: '2.0', method: 'notifications/initialized' })
    expect(initializeMessage().params).toMatchObject({ protocolVersion: '2025-11-25', clientInfo: { name: 'RunR inspector' } })
  })

  it('finds the response to a request among the messages', () => {
    const msgs = [{ jsonrpc: '2.0', method: 'notifications/progress', params: {} }, { jsonrpc: '2.0', id: 7, result: { ok: 1 } }]
    expect(responseFor(msgs, 7)).toEqual(msgs[1])
    expect(responseFor(msgs, 8)).toBeNull()
  })

  const schema = {
    type: 'object',
    properties: {
      city:  { type: 'string', description: 'City' },
      days:  { type: 'integer' },
      ratio: { type: 'number' },
      metric: { type: 'boolean', default: true },
      unit:  { type: 'string', enum: ['c', 'f'] },
      level: { type: 'integer', enum: [1, 2] },
      tags:  { type: 'array', items: { type: 'string' } },
    },
    required: ['city'],
  }

  it('turns an input schema into form fields', () => {
    const fields = formFields(schema)
    expect(fields.map((f) => [f.name, f.kind, f.required])).toEqual([
      ['city', 'string', true], ['days', 'integer', false], ['ratio', 'number', false], ['metric', 'boolean', false],
      ['unit', 'enum', false], ['level', 'enum', false], ['tags', 'json', false],
    ])
    expect(initialValues(fields)).toMatchObject({ city: '', metric: true })
  })

  it('builds typed arguments and reports errors', () => {
    const fields = formFields(schema)
    const ok = argumentsFromForm(fields, { city: 'Ankara', days: '3', ratio: '0.5', metric: true, unit: 'c', level: '2', tags: '["a"]' })
    expect(ok.errors).toEqual({})
    expect(ok.args).toEqual({ city: 'Ankara', days: 3, ratio: 0.5, metric: true, unit: 'c', level: 2, tags: ['a'] })

    const bad = argumentsFromForm(fields, { city: '', days: '2.5', tags: '[' })
    expect(bad.errors).toEqual({ city: 'required', days: 'number', tags: 'json' })
  })

  it('leaves untouched optional booleans out', () => {
    const fields = formFields({ type: 'object', properties: { dry: { type: 'boolean' } } })
    expect(argumentsFromForm(fields, { dry: false }).args).toEqual({})
    expect(argumentsFromForm(fields, { dry: false }, new Set(['dry'])).args).toEqual({ dry: false })
  })
})
