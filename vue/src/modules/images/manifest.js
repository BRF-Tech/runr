/**
 * Adds a version with an `upload:` source to the YAML text of a runtime manifest (Build & Run →
 * Runtimes, docs/runtimes.md#versions): appended to the top-level `versions:` list, or a new list
 * when the manifest has none. The text is edited as lines so the rest of the manifest — comments
 * included — stays as the user wrote it.
 *
 * @param {string} text
 * @param {{ version: string, upload: string, makeDefault?: boolean }} entry
 * @returns {string}
 */
export function appendUploadVersion(text, { version, upload, makeDefault = false }) {
  const item = [
    `  - version: ${JSON.stringify(String(version))}`,
    `    upload: ${String(upload)}`,
    ...(makeDefault ? ['    default: true'] : []),
  ]

  const lines = String(text ?? '').replace(/\r\n/g, '\n').split('\n')
  const start = lines.findIndex(l => /^versions:\s*(\[\s*\])?\s*(#.*)?$/.test(l))

  if (start === -1) {
    const body = lines.join('\n').replace(/\n*$/, '')
    return `${body}${body ? '\n' : ''}versions:\n${item.join('\n')}\n`
  }

  // the block ends before the next top-level key or comment (or the end of the text)
  let end = start + 1
  while (end < lines.length && (lines[end] === '' || /^[\s-]/.test(lines[end]))) end++
  // keep trailing blank lines after the list
  let insert = end
  while (insert > start + 1 && lines[insert - 1].trim() === '') insert--

  // an inline flow list (versions: []) cannot take block items: replace it
  if (/^versions:\s*\[\s*\]/.test(lines[start])) lines[start] = 'versions:'

  lines.splice(insert, 0, ...item)
  return lines.join('\n')
}

/** The `upload:` references of a manifest's versions (from the validated manifest object). */
export function uploadReferences(manifest) {
  return (manifest?.versions ?? []).map(v => v?.upload).filter(Boolean)
}
