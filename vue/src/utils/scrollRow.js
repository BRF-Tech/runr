// Rows that scroll sideways (the project tabs, ProjectHeader): their elements carry
// data-scroll-row. An item of such a row can sit out of view: these helpers scroll the row only,
// never the page (Element.scrollIntoView would also scroll the window to the sticky header).

/** The data-scroll-row row around `element` (not the element itself), else null. */
export function scrollRowOf(element) {
  return element?.parentElement?.closest?.('[data-scroll-row]') ?? null
}

/** 'smooth', unless the user asked the system for less motion. */
export function rowScrollBehavior() {
  return window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ? 'auto' : 'smooth'
}

// Within the row's scroll range (0 … scrollWidth - clientWidth)
function scrollRowTo(row, left, behavior) {
  const target = Math.min(Math.max(0, row.scrollWidth - row.clientWidth), Math.max(0, left))
  if (typeof row.scrollTo === 'function') row.scrollTo({ left: target, behavior })
  else row.scrollLeft = target
}

/**
 * Scrolls the row around `element` until the element is in view, clear of the row's
 * scroll-padding (the edge fades). Returns true when the row moved.
 */
export function revealInScrollRow(element, { behavior = 'auto' } = {}) {
  const row = scrollRowOf(element)
  if (!row || row.scrollWidth <= row.clientWidth) return false
  const style = getComputedStyle(row)
  const padLeft = parseFloat(style.scrollPaddingLeft) || 0
  const padRight = parseFloat(style.scrollPaddingRight) || 0
  const rowBox = row.getBoundingClientRect()
  const box = element.getBoundingClientRect()
  let delta = 0
  if (box.left < rowBox.left + padLeft) delta = box.left - rowBox.left - padLeft
  else if (box.right > rowBox.right - padRight) delta = box.right - rowBox.right + padRight
  if (!delta) return false
  scrollRowTo(row, row.scrollLeft + delta, behavior)
  return true
}

/** Which ends of the row hide items: { start, end }. */
export function scrollRowEdges(row) {
  if (!row || row.scrollWidth <= row.clientWidth) return { start: false, end: false }
  return {
    start: row.scrollLeft > 1,
    end: row.scrollLeft + row.clientWidth < row.scrollWidth - 1,
  }
}

/** Scrolls the row a page (most of its width) back (-1) or forward (1). */
export function scrollRowPage(row, direction, { behavior = 'auto' } = {}) {
  if (!row) return
  scrollRowTo(row, row.scrollLeft + direction * Math.round(row.clientWidth * 0.8), behavior)
}
