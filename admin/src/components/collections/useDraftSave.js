// Debounced autosave of a working copy (the draft).
//
//   const save = useDraftSave(({ col, id, data }, baseVersion) => api.collections.update(col, id, data, LOC, baseVersion))
//   save.schedule({ col, id, data })      on every edit (the last payload wins)
//   if (!(await save.flush())) return     before publish, revert, leaving the record: true = nothing is left unsaved; false = the save failed (save.error.value says why) or a conflict waits (save.conflict.value)
//   save.state.value                      'idle' | 'dirty' | 'saving' | 'saved' | 'error'
// The payload carries its own target, so a flush on the way out of a record still saves THAT record.
// Unmounting the view or closing the tab flushes too (the http client sends small writes with keepalive, so they outlive the page).
//
// VERSION: the server numbers the draft (`version` of the record). The view hands over the version it loaded (setVersion), every save is called with the version the
// last answer brought (saveFn returns the saved record), so the saves of one editor never quarrel with each other, however they queue up. A save over a draft that
// changed meanwhile (an assistant, another person) is refused with 409 `conflict` (isConflict): autosave stops, save.conflict.value says who changed it, the edits stay
// pending, and the person picks one of two: save.keepMine() (the same save over the new version) or, after the view loaded the record as the server has it now, save.reset().
// A flush during that wait sends nothing and resolves false. Closing the tab during that wait makes the browser ask first.
import { onBeforeUnmount, ref } from 'vue'
import { isConflict, whileClosing } from '@/api/index.js'
import { t } from '@/i18n/index.js'

export { isConflict }

export function useDraftSave(saveFn, { delay = 700 } = {}) {
  const state = ref('idle')
  const error = ref('')
  const savedAt = ref(null)
  const conflict = ref(null) // { version, by, at, via, viaName } while the draft changed behind our back and the person has not chosen
  let timer = null
  let pending = null
  let inflight = null
  let version = null
  let sent = 0

  /**
   * The version of the record on screen (after a load): the next save asks to find the draft still at it.
   * since = saves() taken before the load: a save that went out while the load was on its way knows a newer version than the load, and keeps it.
   */
  function setVersion(v, since = sent) {
    if (since === sent) version = v || null
  }

  async function run() {
    clearTimeout(timer)
    timer = null
    if (inflight) await inflight
    if (pending === null || conflict.value) return
    const payload = pending
    pending = null
    state.value = 'saving'
    ++sent
    inflight = (async () => {
      try {
        const saved = await saveFn(payload, version)
        if (saved?.version) version = saved.version
        error.value = ''
        savedAt.value = new Date()
        state.value = pending === null ? 'saved' : 'dirty'
      } catch (e) {
        error.value = (e && e.message) || t('collections.draft_save_failed')
        state.value = 'error'
        if (pending === null) pending = payload // keep it for the next try
        if (isConflict(e)) conflict.value = (e.details && e.details.current) || {}
      } finally {
        inflight = null
      }
    })()
    await inflight
    if (pending !== null && state.value === 'dirty' && !timer) timer = setTimeout(run, delay)
  }

  function schedule(payload) {
    pending = payload
    if (conflict.value) return // stopped: the newest payload waits for the person's choice
    state.value = 'dirty'
    clearTimeout(timer)
    timer = setTimeout(run, delay)
  }
  /**
   * Saves what waits. Resolves true only when nothing is left unsaved and no save is on its way: false after a failed save (a failed one is tried once more when an earlier
   * failure left the edits waiting) or while a conflict waits. Saves that start while it waits (the timer's, for edits typed meanwhile) are waited for too.
   */
  async function flush() {
    for (;;) {
      if (inflight) {
        await inflight
        if (state.value === 'error') break
        continue // another save may have started while that one was on its way
      }
      if (conflict.value || (timer === null && pending === null)) break
      await run() // edits typed while it was on its way are picked up by the next round
      if (state.value === 'error') break
    }
    return !conflict.value && state.value !== 'error' && inflight === null && timer === null && pending === null
  }
  /** «Оставить мои правки»: save what waits once more, over the version the server named. */
  async function keepMine() {
    const c = conflict.value
    if (!c) return
    conflict.value = null
    if (c.version) version = c.version
    await run()
  }
  /** Forget everything (a different record was loaded; nothing pending is dropped silently: call flush() first). */
  function reset() {
    clearTimeout(timer)
    timer = null
    pending = null
    conflict.value = null
    state.value = 'idle'
    error.value = ''
  }

  // A publish can find a newer draft even when every local edit was saved.
  // Keep the visible copy for the same explicit choice as a conflict during autosave.
  function publishConflict(e, payload) {
    pending = payload
    conflict.value = e.details.current || {}
    state.value = 'error'
  }

  const onUnload = (e) => {
    if (inflight || (conflict.value && pending !== null)) {
      // A save is still determining the next version, or a conflicting writer was found.
      // Keep the queue intact if the person stays.
      e.preventDefault()
      e.returnValue = ''
      return
    }
    if (state.value === 'error' && pending !== null) {
      e.preventDefault()
      e.returnValue = ''
    }
    whileClosing(flush)
  }
  window.addEventListener('beforeunload', onUnload)
  onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', onUnload)
    flush()
  })

  return { state, error, savedAt, conflict, setVersion, version: () => version, publishConflict, saves: () => sent, schedule, flush, keepMine, reset, hasPending: () => timer !== null || pending !== null || inflight !== null }
}
