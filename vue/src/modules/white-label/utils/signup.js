// Signup settings helpers.

/** Domains from free text: one per line or comma separated, lower case, without "@", unique. */
export function parseDomains(text) {
  return [...new Set(
    String(text ?? '')
      .split(/[\s,;]+/)
      .map(d => d.trim().toLowerCase().replace(/^@/, ''))
      .filter(Boolean),
  )]
}
