// Focus handling for overlays: move focus in on open, keep Tab inside, give focus back on close. On a phone and a touch tablet the page behind an overlay does not scroll.
import { nextTick, onBeforeUnmount, watch } from 'vue'
import { vp } from '@/utils/viewport.js'

const FOCUSABLE = 'button:not([disabled]),a[href],input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex="0"],[contenteditable="true"]'
const TEXT_FIELD = 'input:not([type=checkbox]):not([type=radio]):not([type=button]):not([type=submit]):not([type=file]),textarea,[contenteditable="true"]'

// several overlays may be open at once (a dialog over a drawer): the lock is released by the last one
let locks = 0
const overlays = []
const lockPage = (on) => {
  if (typeof document === 'undefined') return
  locks = Math.max(0, locks + (on ? 1 : -1))
  document.body.classList.toggle('ov-lock', locks > 0)
}

export function useOverlay(elRef, isOpen, { onEsc, initial, top = false } = {}) {
  let prev = null
  let locked = false
  const overlay = { top }
  const isTop = () => (overlays.findLast((entry) => entry.top) ?? overlays.at(-1)) === overlay
  const keydown = (e) => {
    if (!elRef.value || !isTop()) return
    if (e.key === 'Escape' && onEsc) { e.preventDefault(); e.stopImmediatePropagation(); onEsc(); return }
    if (e.key !== 'Tab') return
    const list = [...elRef.value.querySelectorAll(FOCUSABLE)].filter((x) => x.offsetParent !== null)
    if (!list.length) return
    const first = list[0]
    const last = list[list.length - 1]
    if (e.shiftKey && document.activeElement === first) { last.focus(); e.preventDefault() } else if (!e.shiftKey && document.activeElement === last) { first.focus(); e.preventDefault() }
  }
  const release = () => { if (locked) { locked = false; lockPage(false) } }
  const remove = () => {
    const index = overlays.indexOf(overlay)
    if (index >= 0) overlays.splice(index, 1)
    document.removeEventListener('keydown', keydown, true)
    release()
  }
  watch(isOpen, async (v) => {
    if (v) {
      prev = document.activeElement
      overlays.push(overlay)
      document.addEventListener('keydown', keydown, true)
      if ((vp.phone || (vp.compact && vp.touch)) && !locked) { locked = true; lockPage(true) }
      await nextTick()
      if (!isTop()) return
      const root = elRef.value
      const selector = typeof initial === 'function' ? initial() : initial
      let wanted = selector && root && root.querySelector(selector)
      // a finger: a text field that gets the focus at once brings the keyboard up over the dialog. So only an empty field is focused (a «new …» dialog, a search); the settings of something that exists open without the keyboard
      if (wanted && (vp.touch || vp.phone) && wanted.matches(TEXT_FIELD) && String(wanted.value ?? wanted.textContent).trim()) wanted = null
      const first = root && [...root.querySelectorAll(FOCUSABLE)].find((x) => !(vp.touch || vp.phone) || !x.matches(TEXT_FIELD))
      const target = wanted || first || (root && root.querySelector(FOCUSABLE))
      if (target) target.focus({ preventScroll: true })
    } else {
      const restoreFocus = isTop()
      remove()
      if (restoreFocus && prev && document.contains(prev)) prev.focus({ preventScroll: true })
      prev = null
    }
  }, { immediate: true })
  onBeforeUnmount(remove)
}
