// @vitest-environment jsdom
import { createApp, h, nextTick, ssrContextKey } from 'vue'
import { afterEach, expect, it, vi } from 'vitest'
import View from './CollectionList.vue'
import source from './CollectionList.vue?raw'
import Empty from '@/components/ui/EmptyState.vue'
import emptySource from '@/components/ui/EmptyState.vue?raw'
import { clientComponent } from '@/test/clientComponent.js'
const query = vi.hoisted(() => vi.fn())
vi.mock('@/stores/app.js', () => ({ app: { locale: 'ru', languages: { main: 'ru', list: [{ code: 'ru' }] }, collections: [] }, can: () => true, refreshCounters: vi.fn(), useLocaleGuard: vi.fn() }))
vi.mock('vue-router', () => ({ useRouter: () => ({}), onBeforeRouteLeave: vi.fn(), onBeforeRouteUpdate: vi.fn() }))
vi.mock('@/api/index.js', () => ({ api: { collections: { query }, prefs: { get: async () => ({}), put: async () => ({}) } } }))
vi.mock('./actions/useActions.js', () => ({ useActions: () => ({ at: () => [], busy: { value: false }, dialog: { busy: false } }) }))
clientComponent(View, source); clientComponent(Empty, emptySource)
let mounted
const tick = async () => { for (let i = 0; i < 6; i++) { await Promise.resolve(); await nextTick() } }
afterEach(() => { mounted?.unmount(); document.body.innerHTML = '' })
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
