// @vitest-environment jsdom
import { createApp, h, nextTick, ssrContextKey } from 'vue'
import { afterEach, expect, it, vi } from 'vitest'
import Today from './TodayView.vue'
import source from './TodayView.vue?raw'
import { clientComponent } from '@/test/clientComponent.js'
vi.mock('@/stores/app.js', () => ({ app: { siteInfo: { name: 'Site' } }, can: (area) => area === 'requests', canAnyCol: () => false, refreshCounters: vi.fn() }))
vi.mock('vue-router', () => ({ useRouter: () => ({}), RouterLink: { render() { return h('a', this.$slots.default?.()) } } }))
vi.mock('@/api/index.js', () => ({ api: { site: { getDashboard: async () => ({ newRequests: [
  { id: 'both', city: 'City', place: 'Place' }, { id: 'city', city: 'City', place: '' },
  { id: 'place', city: '', place: 'Place' }, { id: 'neither', city: '', place: '' },
], unpublished: [], recent: [], awaiting: [] }) } } }))
vi.mock('@/components/today/YandexWebmasterCard.vue', () => ({ default: { render: () => null } }))
clientComponent(Today, source)
let mounted
afterEach(() => { mounted?.unmount(); document.body.innerHTML = '' })
it('shows a location separator only between two nonempty request labels', async () => {
  const host = document.createElement('div'); document.body.append(host)
  mounted = createApp(Today); mounted.component('router-link', { render() { return h('a', this.$slots.default?.()) } }); mounted.provide(ssrContextKey, { modules: new Set() }); mounted.mount(host)
  for (let i = 0; i < 4; i++) { await Promise.resolve(); await nextTick() }
  expect([...host.querySelectorAll('.main-c > .meta:not(.note)')].map((el) => el.textContent)).toEqual(['City · Place', 'City', 'Place', ''])
})
