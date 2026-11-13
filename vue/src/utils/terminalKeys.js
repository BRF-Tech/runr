// Alt+arrow in the shell terminal moves by word, as it did up to xterm 5. xterm 6 sends the key as
// it is (ESC [1;3D for Alt+←), which shells do not bind, and leaves the word move to the page that
// embeds it: ESC b / ESC f (readline's backward-word / forward-word) for Alt+←/→ on macOS, the
// Ctrl+arrow keys everywhere else (where Alt+↑/↓ also became Ctrl+↑/↓).
const MAC = { ArrowLeft: '\x1bb', ArrowRight: '\x1bf' }
const OTHER = { ArrowLeft: '\x1b[1;5D', ArrowRight: '\x1b[1;5C', ArrowUp: '\x1b[1;5A', ArrowDown: '\x1b[1;5B' }

/** Whether the browser runs on macOS (xterm's own test). */
export const isMacPlatform = () => typeof navigator !== 'undefined' && /^Mac/.test(navigator.platform ?? '')

/** What xterm 5 sent for this key event: a word move for Alt+arrow, null for anything else. */
export function altArrowSequence(event, isMac) {
  if (event.type !== 'keydown' || !event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return null
  return (isMac ? MAC : OTHER)[event.key] ?? null
}

/** Has `terminal` send xterm 5's word moves for Alt+arrow (attachCustomKeyEventHandler). */
export function keepAltArrowWordMoves(terminal, isMac = isMacPlatform()) {
  terminal.attachCustomKeyEventHandler((event) => {
    const sequence = altArrowSequence(event, isMac)
    if (sequence === null) return true
    // xterm leaves the event alone once the handler takes it: no browser Back on Alt+←
    event.preventDefault()
    terminal.input(sequence)
    return false
  })
}
