// @vitest-environment jsdom
import { createApp, h, nextTick, ssrContextKey } from 'vue'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import Filters from './list/ListFilters.vue'
import filtersSource from './list/ListFilters.vue?raw'
import FilterSheet from '@/components/ui/FilterSheet.vue'
import filterSheetSource from '@/components/ui/FilterSheet.vue?raw'
import Pager from './list/ListPager.vue'
import pagerSource from './list/ListPager.vue?raw'
import View from './CollectionList.vue'
import source from './CollectionList.vue?raw'
import Empty from '@/components/ui/EmptyState.vue'
import emptySource from '@/components/ui/EmptyState.vue?raw'
import { clientComponent } from '@/test/clientComponent.js'
const { query, prefs } = vi.hoisted(() => ({ query: vi.fn(), prefs: vi.fn() }))
vi.mock('@/stores/app.js', () => ({ app: { locale: 'ru', languages: { main: 'ru', list: [{ code: 'ru' }] }, collections: [] }, can: () => true, refreshCounters: vi.fn(), useLocaleGuard: vi.fn() }))
vi.mock('vue-router', () => ({ useRouter: () => ({}), onBeforeRouteLeave: vi.fn(), onBeforeRouteUpdate: vi.fn() }))
vi.mock('@/api/index.js', () => ({ api: { collections: { query }, prefs: { get: prefs, put: async () => ({}) } } }))
vi.mock('./actions/useActions.js', () => ({ useActions: () => ({ at: () => [], busy: { value: false }, dialog: { busy: false } }) }))
clientComponent(Filters, filtersSource); clientComponent(FilterSheet, filterSheetSource); clientComponent(Pager, pagerSource); clientComponent(View, source); clientComponent(Empty, emptySource)
let mounted
const tick = async () => { for (let i = 0; i < 6; i++) { await Promise.resolve(); await nextTick() } }
beforeEach(() => { query.mockReset(); prefs.mockReset().mockResolvedValue({}) })
afterEach(() => { mounted?.unmount(); document.body.innerHTML = ''; vi.useRealTimers() })
it('shows the server error and retry until a successful empty response arrives', async () => {
  query.mockRejectedValueOnce(new Error('Service unavailable')).mockResolvedValueOnce({ items: [], total: 0, page: 1, perPage: 50 })
  const cfg = { key: 'team', title: 'Team', description: '', newLabel: 'Add person', titleField: 'title', empty: { title: 'No people yet', text: 'Add your first person' }, tabs: [] }
  const host = document.createElement('div'); document.body.append(host)
  mounted = createApp({ render: () => h(View, { cfg }) }); mounted.component('router-link', { render() { return h('a', this.$slots.default?.()) } }); mounted.provide(ssrContextKey, { modules: new Set() }); mounted.mount(host)
  await tick()
  expect(host.querySelector('[role=alert]').textContent).toContain('Service unavailable')
  expect(host.textContent).not.toContain('No people yet')
  host.querySelector('[role=alert] button').click(); await tick()
  expect(host.querySelector('[role=alert]')).toBeNull()
  expect(host.textContent).toContain('No people yet')
  expect(query).toHaveBeenCalledTimes(2)
})

async function mountList() {
  const cfg = { key: 'team', title: 'Team', description: '', newLabel: 'Add person', titleField: 'title', empty: { title: 'No people yet', text: 'Add your first person' }, tabs: [] }
  const host = document.createElement('div'); document.body.append(host)
  mounted = createApp({ render: () => h(View, { cfg }) }); mounted.component('router-link', { render() { return h('a', this.$slots.default?.()) } }); mounted.provide(ssrContextKey, { modules: new Set() }); mounted.mount(host)
  await tick()
  return { host, state: mounted._instance.subTree.component.setupState }
}
it('keeps search, reset and known pagination usable after a list error', async () => {
  query.mockResolvedValueOnce({ items: [{ id: 'one', title: 'One', translated: true }], total: 120, page: 1, perPage: 50 })
  const { host, state } = await mountList()
  state.ls.state.q = 'broken search'; state.ls.state.filter = { status: 'draft' }; await tick()
  query.mockRejectedValueOnce(new Error('Search unavailable')); await state.load(); await tick()
  expect(host.querySelector('[role=alert]').textContent).toContain('Search unavailable')
  expect(host.querySelector('#cl-q')?.value).toBe('broken search')
  expect(host.querySelector('.lf select')?.value).toBe('draft')
  expect(host.querySelector('.cl-reset')).not.toBeNull()
  expect(host.querySelector('.lp')).not.toBeNull()
  expect(host.querySelector('table')).toBeNull()
  host.querySelector('.cl-reset').click(); await tick()
  expect(state.ls.state.q).toBe('')
})
it('keeps the loading placeholder throughout automatic recovery from a stale 422 preference', async () => {
  vi.useFakeTimers()
  prefs.mockResolvedValueOnce({ sort: 'updatedAt' })
  query.mockRejectedValueOnce({ status: 422, message: 'Stale sort' })
  let finish
  query.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve }))
  const { host } = await mountList()
  expect(query).toHaveBeenCalledTimes(1)
  expect(host.textContent).not.toContain('No people yet')
  expect(host.querySelector('.cl-pad')).not.toBeNull()
  await vi.advanceTimersByTimeAsync(220); await tick()
  expect(query).toHaveBeenCalledTimes(2)
  expect(host.textContent).not.toContain('No people yet')
  finish({ items: [], total: 0, page: 1, perPage: 50 }); await tick()
  expect(host.textContent).toContain('No people yet')
})
