// Helpers of the MCP inspector (project "MCP" tab): JSON-RPC messages and a form generated from
// a tool's inputSchema.

export const PROTOCOL_VERSION = '2025-11-25'

let nextId = 1

/** A JSON-RPC request (with an id) or, with notification = true, a notification. */
export function rpc(method, params = undefined, { notification = false } = {}) {
  const message = { jsonrpc: '2.0' }
  if (!notification) message.id = nextId++
  message.method = method
  if (params !== undefined) message.params = params
  return message
}

export function initializeMessage() {
  return rpc('initialize', {
    protocolVersion: PROTOCOL_VERSION,
    capabilities: {},
    clientInfo: { name: 'RunR inspector', version: '1.0.0' },
  })
}

/** The response to request `id` among the messages the server sent. */
export function responseFor(messages, id) {
  return (messages || []).find(m => m && m.id === id && ('result' in m || 'error' in m)) || null
}

/**
 * Form fields of a JSON Schema object: scalars get typed inputs, enums a select, objects and
 * arrays a JSON text area.
 *
 * @returns {{ name, kind: 'string'|'number'|'integer'|'boolean'|'enum'|'json', required, description, options, default }[]}
 */
export function formFields(schema) {
  const properties = schema?.properties || {}
  const required = new Set(schema?.required || [])

  return Object.entries(properties).map(([name, prop]) => {
    const type = Array.isArray(prop?.type) ? prop.type.find(t => t !== 'null') : prop?.type
    let kind = 'json'
    if (Array.isArray(prop?.enum)) kind = 'enum'
    else if (['string', 'number', 'integer', 'boolean'].includes(type)) kind = type
    return {
      name,
      kind,
      required: required.has(name),
      description: prop?.description || '',
      options: Array.isArray(prop?.enum) ? prop.enum : [],
      default: prop?.default,
    }
  })
}

/** Initial values of the generated form (defaults where the schema has them). */
export function initialValues(fields) {
  const values = {}
  for (const f of fields) {
    if (f.default === undefined) values[f.name] = f.kind === 'boolean' ? false : ''
    else values[f.name] = f.kind === 'json' ? JSON.stringify(f.default, null, 2) : f.default
  }
  return values
}

/**
 * The arguments object of a tools/call from the form. Empty optional fields are left out.
 *
 * @returns {{ args: object, errors: Record<string, string> }} errors: field → 'required' | 'number' | 'json'
 */
export function argumentsFromForm(fields, values, touchedBooleans = new Set()) {
  const args = {}
  const errors = {}

  for (const f of fields) {
    const raw = values[f.name]
    if (f.kind === 'boolean') {
      if (f.required || touchedBooleans.has(f.name) || raw === true) args[f.name] = !!raw
      continue
    }

    const empty = raw === '' || raw === null || raw === undefined
    if (empty) {
      if (f.required) errors[f.name] = 'required'
      continue
    }

    if (f.kind === 'number' || f.kind === 'integer') {
      const n = Number(raw)
      if (!Number.isFinite(n) || (f.kind === 'integer' && !Number.isInteger(n))) errors[f.name] = 'number'
      else args[f.name] = n
    } else if (f.kind === 'json') {
      try { args[f.name] = typeof raw === 'string' ? JSON.parse(raw) : raw } catch { errors[f.name] = 'json' }
    } else if (f.kind === 'enum') {
      // a <select> yields strings: give numeric enums their number back
      const match = f.options.find(o => String(o) === String(raw))
      args[f.name] = match === undefined ? raw : match
    } else {
      args[f.name] = raw
    }
  }

  return { args, errors }
}
