import { describe, it, expect, vi } from 'vitest'
import { altArrowSequence, keepAltArrowWordMoves } from '@/utils/terminalKeys'

const keydown = (key, mods = {}) => new KeyboardEvent('keydown', { key, cancelable: true, ...mods })

describe('Alt+arrow in the shell terminal (the word moves of xterm 5)', () => {
  it('sends Ctrl+arrow for Alt+arrow off macOS', () => {
    expect(altArrowSequence(keydown('ArrowLeft', { altKey: true }), false)).toBe('\x1b[1;5D')
    expect(altArrowSequence(keydown('ArrowRight', { altKey: true }), false)).toBe('\x1b[1;5C')
    expect(altArrowSequence(keydown('ArrowUp', { altKey: true }), false)).toBe('\x1b[1;5A')
    expect(altArrowSequence(keydown('ArrowDown', { altKey: true }), false)).toBe('\x1b[1;5B')
  })

  it('sends readline\'s backward-word / forward-word for Option+←/→ on macOS, Option+↑/↓ as they are', () => {
    expect(altArrowSequence(keydown('ArrowLeft', { altKey: true }), true)).toBe('\x1bb')
    expect(altArrowSequence(keydown('ArrowRight', { altKey: true }), true)).toBe('\x1bf')
    expect(altArrowSequence(keydown('ArrowUp', { altKey: true }), true)).toBeNull()
    expect(altArrowSequence(keydown('ArrowDown', { altKey: true }), true)).toBeNull()
  })

  it('leaves every other key to xterm: no Alt, more modifiers, other keys, key up', () => {
    expect(altArrowSequence(keydown('ArrowLeft'), false)).toBeNull()
    expect(altArrowSequence(keydown('ArrowLeft', { altKey: true, shiftKey: true }), false)).toBeNull()
    expect(altArrowSequence(keydown('ArrowLeft', { altKey: true, ctrlKey: true }), false)).toBeNull()
    expect(altArrowSequence(keydown('ArrowLeft', { altKey: true, metaKey: true }), true)).toBeNull()
    expect(altArrowSequence(keydown('b', { altKey: true }), false)).toBeNull()
    expect(altArrowSequence(new KeyboardEvent('keyup', { key: 'ArrowLeft', altKey: true }), false)).toBeNull()
  })

  it('types the word move into the terminal and keeps the browser from going Back', () => {
    let handler
    const terminal = { attachCustomKeyEventHandler: (fn) => { handler = fn }, input: vi.fn() }
    keepAltArrowWordMoves(terminal, false)

    const altLeft = keydown('ArrowLeft', { altKey: true })
    expect(handler(altLeft)).toBe(false)
    expect(altLeft.defaultPrevented).toBe(true)
    expect(terminal.input).toHaveBeenCalledWith('\x1b[1;5D')

    const plainLeft = keydown('ArrowLeft')
    expect(handler(plainLeft)).toBe(true)
    expect(plainLeft.defaultPrevented).toBe(false)
    expect(terminal.input).toHaveBeenCalledTimes(1)
  })
})
