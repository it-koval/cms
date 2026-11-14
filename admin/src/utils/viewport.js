// Screen size classes, the same ones the stylesheets use: phone < 768 px, compact (phone + tablet) < 1200 px. `touch` = the main pointer is a finger.
// The CSS does the layout; this file is for the places where the markup or the behaviour differs (a drawer instead of a rail, a sheet instead of a popover).
import { reactive } from 'vue'

export const PHONE_MAX = 767
export const COMPACT_MAX = 1199

export const vp = reactive({ phone: false, compact: false, touch: false })

if (typeof window !== 'undefined' && window.matchMedia) {
  const watch = (query, key) => {
    const mq = window.matchMedia(query)
    const set = () => { vp[key] = mq.matches }
    set()
    if (mq.addEventListener) mq.addEventListener('change', set)
    else if (mq.addListener) mq.addListener(set)
  }
  watch(`(max-width: ${PHONE_MAX}px)`, 'phone')
  watch(`(max-width: ${COMPACT_MAX}px)`, 'compact')
  watch('(pointer: coarse)', 'touch')
}

// iOS does not shrink the layout for the on-screen keyboard: it slides the whole page up, and a screen that does not scroll (the editor) has no way back.
// --vvh (height), --vvt and --vvb (the distance from the top and the bottom) are the part of the screen that is really visible; the editor and the overlays of a phone and a touch tablet stick to it. A pinch zoom changes nothing.
if (typeof window !== 'undefined' && window.visualViewport) {
  const v = window.visualViewport
  let last = v.height
  const sync = () => {
    if (v.scale > 1.01) return
    const s = document.documentElement.style
    s.setProperty('--vvh', `${Math.round(v.height)}px`)
    s.setProperty('--vvt', `${Math.round(v.offsetTop)}px`)
    s.setProperty('--vvb', `${Math.max(0, Math.round(document.documentElement.clientHeight - v.height - v.offsetTop))}px`)
    // the keyboard came up over the field the person is typing in
    if (v.height < last && vp.touch) requestAnimationFrame(() => {
      const el = document.activeElement
      if (el && el.matches('input,textarea,[contenteditable="true"]')) el.scrollIntoView({ block: 'nearest' })
    })
    last = v.height
  }
  sync()
  v.addEventListener('resize', sync)
  v.addEventListener('scroll', sync)
}
