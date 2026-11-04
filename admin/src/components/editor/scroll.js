// Scroll and focus helpers of the page editor (ported from the prototype: scrollIn, smooth).
export const smooth = () => (matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth')

/** Scroll ONE container (the page stage or the sidebar body) so that el sits at its start, centre or nearest edge.
 *  Unlike scrollIntoView it never scrolls the page around it. */
export function scrollIn(sc, el, block = 'nearest') {
  if (!sc || !el) return
  const sr = sc.getBoundingClientRect()
  const r = el.getBoundingClientRect()
  const mt = parseFloat(getComputedStyle(el).scrollMarginTop) || 0
  const top = r.top - sr.top
  const h = sc.clientHeight
  let d = 0
  if (block === 'start') d = top - mt
  else if (block === 'center') d = top - (h - r.height) / 2
  else if (top < mt) d = top + r.height > h ? 0 : top - mt
  else if (top + r.height > h) d = Math.min(top - mt, top + r.height - h)
  if (d) sc.scrollTo({ top: sc.scrollTop + d, behavior: smooth() })
}
