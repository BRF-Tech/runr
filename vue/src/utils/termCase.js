// English terms in upper-cased Turkish text.
//
// Labels and headings are upper-cased with CSS (`uppercase`, `.label`, `.section-title`), and
// text-transform follows the language of the element: under <html lang="tr"> an i becomes İ.
// That is right for Turkish words (ŞİFRE) and wrong for the English terms the Turkish UI keeps
// ("Knative Bölgeleri" → KNATİVE, "Bellek (MiB)" → MİB, "Base Domain" → DOMAİN). The terms are
// the words a Turkish message shares with its English one; marked lang="en" (TermText.vue),
// they are upper-cased with English rules.

const WORD = /[\p{L}\p{N}]+(?:[-.][\p{L}\p{N}]+)*/gu

// Spelled the same in both languages, but Turkish words too: they keep Turkish casing (DİSK).
const TURKISH_TOO = new Set(['disk', 'site'])

function englishWords(english) {
  return new Set((String(english ?? '').match(WORD) ?? []).map((w) => w.toLowerCase()))
}

function isTerm(word, english) {
  const w = word.toLowerCase()
  if (TURKISH_TOO.has(w) || !/\p{L}/u.test(w)) return false
  // the stem before a Turkish suffix: registry'ler / registries, commit'ler / commits
  return english.has(w) || english.has(`${w}s`) || english.has(`${w}es`)
    || (w.endsWith('y') && english.has(`${w.slice(0, -1)}ies`))
}

/**
 * The text split into runs of English terms ({ text, en: true }) and the rest ({ text, en: false }).
 * Terms separated only by spaces or punctuation form one run ("Base Domain", "IdP Entity ID").
 */
export function termSegments(text, english) {
  const source = String(text ?? '')
  const words = englishWords(english)
  const segments = []
  const push = (part, en) => {
    if (!part) return
    const last = segments[segments.length - 1]
    if (last && last.en === en) last.text += part
    else segments.push({ text: part, en })
  }

  let pos = 0
  for (const m of source.matchAll(WORD)) {
    const gap = source.slice(pos, m.index)
    pos = m.index + m[0].length
    const term = isTerm(m[0], words)
    if (term && segments[segments.length - 1]?.en) {
      push(gap + m[0], true)
    } else {
      push(gap, false)
      push(m[0], term)
    }
  }
  push(source.slice(pos), false)
  return segments
}
