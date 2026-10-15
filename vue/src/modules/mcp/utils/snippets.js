// Connection snippets for MCP clients (docs/mcp.md, "Connect a client"). Generated here, never
// stored in the locale catalogs: they contain braces and colons vue-i18n would interpret.

export const TOKEN_PLACEHOLDER = '<your-runr-token>'

/** A server name MCP clients accept: lower-case letters, digits, - and _. */
export function serverName(name) {
  const cleaned = String(name || 'runr').toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '')
  return cleaned || 'runr'
}

const json = (value) => JSON.stringify(value, null, 2)

/**
 * Snippets for one MCP endpoint.
 *
 *   auth  'oauth'  — one-click: the client discovers RunR's authorization server
 *         'token'  — an API token in the Authorization header
 *         'public' — no credentials (a public hosted server)
 *
 * @returns {{ id: string, language: string, code: string }[]}
 */
export function clientSnippets(url, { name = 'runr', auth = 'oauth', token = null } = {}) {
  const id = serverName(name)
  const bearer = `Bearer ${token || TOKEN_PLACEHOLDER}`
  const withHeader = auth === 'token'

  const claudeCode = withHeader
    ? `claude mcp add --transport http ${id} ${url} --header "Authorization: ${bearer}"`
    : `claude mcp add --transport http ${id} ${url}`

  const desktopArgs = ['-y', 'mcp-remote', url]
  const desktop = { command: 'npx', args: desktopArgs }
  if (withHeader) {
    desktopArgs.push('--header', 'Authorization:${RUNR_AUTH_HEADER}')
    desktop.env = { RUNR_AUTH_HEADER: bearer }
  }

  const cursor = { url }
  const vscode = { type: 'http', url }
  if (withHeader) {
    cursor.headers = { Authorization: bearer }
    vscode.headers = { Authorization: bearer }
  }

  const generic = [
    `URL:        ${url}`,
    'Transport:  Streamable HTTP',
    withHeader
      ? `Header:     Authorization: ${bearer}`
      : auth === 'oauth'
        ? 'Auth:       OAuth 2.1 (discovered from the URL)'
        : 'Auth:       none',
  ].join('\n')

  return [
    { id: 'claudeCode',     language: 'shell', code: claudeCode },
    { id: 'claudeDesktop', language: 'json',  code: json({ mcpServers: { [id]: desktop } }) },
    { id: 'cursor',         language: 'json',  code: json({ mcpServers: { [id]: cursor } }) },
    { id: 'vscode',         language: 'json',  code: json({ servers: { [id]: vscode } }) },
    { id: 'generic',        language: 'text',  code: generic },
  ]
}
