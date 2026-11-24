// The state of a list of a collection that the person set: search, filters, sort, page, page size, view. The filters, the sort, the page size and the view are KEPT FOR HIM ON THE SERVER
// (api.prefs, key «list.<collection>»): the next visit, from any device, opens the list the way he left it. The search text and the page are not kept: a search is an act of the moment.
//   const st = useListState(cfg, filters)       filters = filtersOf(cfg, ...)
//   await st.restore()                          read the saved state once; sets st.ready
//   st.q / st.filter / st.sort / st.page / st.perPage / st.view     reactive; changing a filter, the sort or the page size goes back to page 1 (watch in the list)
//   st.params()                                 { q, filter, sort, page, perPage } for api.collections.query
//   st.reset()                                  clears the search and every filter (the sort stays)
// Saving is delayed (a person typing in a filter does not send a request per letter) and its failure is silent: a preference is never worth an error.
import { nextTick, onBeforeUnmount, reactive, toRefs, watch } from 'vue'
import { api } from '@/api/index.js'
import { activeCount, cleanFilter, sanitizeSaved, viewsOf } from './listModel.js'

export function useListState(cfg, filters) {
  const key = `list.${cfg.key}`
  const state = reactive({ ready: false, q: '', filter: {}, sort: '', page: 1, perPage: cfg.perPage || 50, view: viewsOf(cfg)[0] })
  let timer = null
  let restoring = true

  const saved = () => ({ filter: cleanFilter(state.filter), sort: state.sort, perPage: state.perPage, view: state.view })
  function flush() {
    clearTimeout(timer)
    timer = null
    api.prefs.put(key, saved()).catch(() => { /* not kept: the list works all the same */ })
  }
  const later = () => {
    if (restoring) return
    clearTimeout(timer)
    timer = setTimeout(flush, 600)
  }
  watch(() => [state.filter, state.sort, state.perPage, state.view], later, { deep: true })
  onBeforeUnmount(() => { if (timer) flush() })

  async function restore() {
    const s = sanitizeSaved(await api.prefs.get(key), cfg, filters)
    state.filter = s.filter
    state.sort = s.sort
    if (s.perPage) state.perPage = s.perPage
    if (s.view) state.view = s.view
    await nextTick() // the watch above sees the restored values on this tick: they are not a change of the person
    restoring = false
    state.ready = true
  }

  return Object.assign(toRefs(state), {
    restore,
    state,
    params: () => {
      const { status = '', ...filter } = cleanFilter(state.filter)
      return { q: state.q.trim(), status, filter, sort: state.sort, page: state.page, perPage: state.perPage }
    },
    reset() { state.q = ''; state.filter = {}; state.page = 1 },
    activeFilters: () => activeCount(state.filter),
    flush,
  })
}
