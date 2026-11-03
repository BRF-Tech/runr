import { afterEach, describe, expect, it, vi } from 'vitest'
import { revealInScrollRow, rowScrollBehavior, scrollRowEdges, scrollRowOf, scrollRowPage } from '@/utils/scrollRow'

// Rows that scroll sideways (the project tabs): an item out of view is scrolled into view by
// moving the row only, clear of the row's scroll-padding (its edge fades).

const rect = (left, width) => ({ left, right: left + width, width, top: 0, bottom: 40, height: 40, x: left, y: 0 })

/** A row 300 px wide at x = 100 holding five 100 px items (500 px of content). */
function fakeRow({ scrollLeft = 0, padding = 0, scrollTo = true } = {}) {
  const row = document.createElement('div')
  row.setAttribute('data-scroll-row', '')
  Object.defineProperty(row, 'clientWidth', { value: 300 })
  Object.defineProperty(row, 'scrollWidth', { value: 500 })
  row.scrollLeft = scrollLeft
  row.getBoundingClientRect = () => rect(100, 300)
  if (scrollTo) row.scrollTo = vi.fn(function ({ left }) { this.scrollLeft = left })
  const items = Array.from({ length: 5 }, (_, i) => {
    const item = document.createElement('button')
    item.getBoundingClientRect = () => rect(100 + i * 100 - row.scrollLeft, 100)
    row.appendChild(item)
    return item
  })
  document.body.appendChild(row)
  const real = window.getComputedStyle
  vi.spyOn(window, 'getComputedStyle').mockImplementation((el) => (
    el === row ? { scrollPaddingLeft: `${padding}px`, scrollPaddingRight: `${padding}px` } : real(el)
  ))
  return { row, items }
}

afterEach(() => { document.body.innerHTML = '' })

describe('scrollRow', () => {
  it('finds the row around an item, not the row itself', () => {
    const { row, items } = fakeRow()
    expect(scrollRowOf(items[2])).toBe(row)
    expect(scrollRowOf(row)).toBeNull()
    expect(scrollRowOf(document.createElement('span'))).toBeNull()
  })

  it('scrolls an item past the end into view, clear of the fade, never past the end', () => {
    const { row, items } = fakeRow({ padding: 48 })
    expect(revealInScrollRow(items[3], { behavior: 'smooth' })).toBe(true)
    expect(row.scrollTo).toHaveBeenCalledWith({ left: 148, behavior: 'smooth' })
    // the last item: the row's end (200), not 248
    row.scrollLeft = 0
    revealInScrollRow(items[4])
    expect(row.scrollTo).toHaveBeenLastCalledWith({ left: 200, behavior: 'auto' })
  })

  it('scrolls an item before the start into view, never past the start', () => {
    const { row, items } = fakeRow({ scrollLeft: 200, padding: 48 })
    expect(revealInScrollRow(items[1])).toBe(true)
    expect(row.scrollLeft).toBe(52)
    revealInScrollRow(items[0])
    expect(row.scrollLeft).toBe(0)
  })

  it('leaves the row alone when the item is in view, or the row fits', () => {
    const { row, items } = fakeRow()
    expect(revealInScrollRow(items[1])).toBe(false)
    expect(row.scrollTo).not.toHaveBeenCalled()

    const loose = document.createElement('button')
    document.body.appendChild(loose)
    expect(revealInScrollRow(loose)).toBe(false)
    expect(revealInScrollRow(null)).toBe(false)
  })

  it('sets scrollLeft where the browser has no Element.scrollTo', () => {
    const { row, items } = fakeRow({ scrollTo: false })
    revealInScrollRow(items[4])
    expect(row.scrollLeft).toBe(200)
  })

  it('tells which ends hide items', () => {
    const { row } = fakeRow()
    expect(scrollRowEdges(row)).toEqual({ start: false, end: true })
    row.scrollLeft = 100
    expect(scrollRowEdges(row)).toEqual({ start: true, end: true })
    row.scrollLeft = 200
    expect(scrollRowEdges(row)).toEqual({ start: true, end: false })
    expect(scrollRowEdges(null)).toEqual({ start: false, end: false })
  })

  it('pages back and forward by most of the row width', () => {
    const { row } = fakeRow()
    scrollRowPage(row, 1)
    expect(row.scrollTo).toHaveBeenLastCalledWith({ left: 200, behavior: 'auto' }) // 240, held at the end
    row.scrollLeft = 160
    scrollRowPage(row, -1, { behavior: 'smooth' })
    expect(row.scrollTo).toHaveBeenLastCalledWith({ left: 0, behavior: 'smooth' })
    row.scrollLeft = 0
    scrollRowPage(row, 1)
    expect(row.scrollLeft).toBe(200)
    expect(() => scrollRowPage(null, 1)).not.toThrow()
  })

  it('scrolls smoothly unless the system asks for less motion', () => {
    expect(rowScrollBehavior()).toBe('smooth')
    vi.spyOn(window, 'matchMedia').mockImplementation(query => ({ matches: query.includes('reduced-motion'), media: query }))
    expect(rowScrollBehavior()).toBe('auto')
  })
})
