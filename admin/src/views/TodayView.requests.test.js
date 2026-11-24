// @vitest-environment jsdom
import { createApp, h, nextTick, ssrContextKey } from 'vue'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import Today from './TodayView.vue'
import source from './TodayView.vue?raw'
import { clientComponent } from '@/test/clientComponent.js'
const mocks = vi.hoisted(() => ({ dashboard: vi.fn(), list: vi.fn(), update: vi.fn(), counters: vi.fn(), show: vi.fn(), error: vi.fn() }))
vi.mock('@/stores/app.js', () => ({ app: { siteInfo: { name: 'Site' } }, can: (area) => area === 'requests', canAnyCol: () => false, refreshCounters: mocks.counters }))
vi.mock('vue-router', () => ({ useRouter: () => ({}) }))
vi.mock('@/api/index.js', () => ({ api: { site: { getDashboard: mocks.dashboard }, requests: { list: mocks.list, update: mocks.update } } }))
vi.mock('@/components/ui/useToast.js', () => ({ useToast: () => ({ show: mocks.show, error: mocks.error }) }))
vi.mock('@/components/today/YandexWebmasterCard.vue', () => ({ default: { render: () => null } }))
clientComponent(Today, source)
let mounted, host, state, records
const tick = async () => { for (let i = 0; i < 6; i++) { await Promise.resolve(); await nextTick() } }
const dashboard = (rows = records) => ({ newRequests: rows.slice(0, 8), newRequestCount: rows.length, unpublished: [], proposals: [], recent: [], awaiting: [] })
const envelope = (rows) => ({ items: rows.slice(0, 8), total: rows.length, page: 1, perPage: 8 })
const deferred = () => { let resolve; const promise = new Promise((done) => { resolve = done }); return { promise, resolve } }
beforeEach(() => {
  vi.clearAllMocks()
  records = Array.from({ length: 10 }, (_, i) => ({ id: i + 1, phone: 'Phone ' + (i + 1), city: '', place: 'Contact', work: 'new' }))
  mocks.dashboard.mockImplementation(async () => dashboard())
  mocks.list.mockImplementation(async () => envelope(records))
  mocks.update.mockImplementation(async (id, patch) => { if (patch.work === 'progress') records = records.filter((row) => row.id !== id); return {} })
  mocks.counters.mockResolvedValue({})
})
afterEach(() => { mounted?.unmount(); document.body.innerHTML = '' })
async function mount() {
  host = document.createElement('div'); document.body.append(host)
  mounted = createApp({ render: () => h(Today) }); mounted.component('router-link', { props: ['to'], render() { return h('a', this.$slots.default?.()) } }); mounted.provide(ssrContextKey, { modules: new Set() }); mounted.mount(host)
  state = mounted._instance.subTree.component.setupState
  await tick()
}
it('refills the eight-row request slice until the ninth request is actionable without reloading the dashboard', async () => {
  records = records.slice(0, 9)
  await mount()
  const originalRows = [...state.data.newRequests]
  for (const row of originalRows) await state.markSeen(row)
  await tick()
  expect(state.data.newRequests.map((row) => row.id)).toEqual([9])
  expect(state.newRequestCount).toBe(1)
  expect(host.textContent).toContain('Phone 9')
  expect(host.querySelectorAll('button.seen')).toHaveLength(1)
  expect(mocks.dashboard).toHaveBeenCalledTimes(1)
  expect(mocks.list).toHaveBeenCalledTimes(8)
  for (const [params] of mocks.list.mock.calls) expect(params).toEqual({ status: 'new', perPage: 8 })
})
it('keeps the newest refill when two mark responses and list responses overlap', async () => {
  await mount()
  const older = deferred(), newer = deferred()
  mocks.list.mockReturnValueOnce(older.promise).mockReturnValueOnce(newer.promise)
  const first = state.markSeen(state.data.newRequests[0]); await tick()
  const second = state.markSeen(state.data.newRequests.find((row) => row.id === 2)); await tick()
  newer.resolve(envelope(records)); await second
  older.resolve(envelope([{ id: 2 }, ...records])); await first
  expect(state.data.newRequests.map((row) => row.id)).toEqual(records.map((row) => row.id))
  expect(state.newRequestCount).toBe(8)
})
it('preserves a completed request refill when an older whole-dashboard response arrives later', async () => {
  await mount()
  const original = dashboard(), pending = deferred()
  mocks.dashboard.mockReturnValueOnce(pending.promise)
  const loading = state.load()
  await state.markSeen(state.data.newRequests[0])
  pending.resolve({ ...original, marker: 'other dashboard data' }); await loading
  expect(state.data.marker).toBe('other dashboard data')
  expect(state.data.newRequests.some((row) => row.id === 1)).toBe(false)
  expect(state.data.newRequests.map((row) => row.id)).toEqual(records.slice(0, 8).map((row) => row.id))
  expect(state.newRequestCount).toBe(9)
})
it('preserves a refill that finishes while a later whole-dashboard load is pending', async () => {
  await mount()
  const list = deferred(), full = deferred()
  mocks.list.mockReturnValueOnce(list.promise)
  const marking = state.markSeen(state.data.newRequests[0]); await tick()
  const stale = { ...dashboard(), newRequests: [{ id: 'old' }], newRequestCount: 99 }
  mocks.dashboard.mockReturnValueOnce(full.promise)
  const loading = state.load()
  list.resolve(envelope(records)); await marking
  full.resolve(stale); await loading
  expect(state.data.newRequests.map((row) => row.id)).toEqual(records.slice(0, 8).map((row) => row.id))
  expect(state.newRequestCount).toBe(9)
})
it('undo refills only the request slice and invalidates the older pending refill', async () => {
  await mount()
  const original = { ...records[0] }, pending = deferred()
  mocks.list.mockReturnValueOnce(pending.promise)
  const marking = state.markSeen(original); await tick()
  const undo = mocks.show.mock.calls[0][1].undo
  mocks.update.mockImplementationOnce(async () => { records = [original, ...records]; return {} })
  await undo()
  pending.resolve(envelope(records.filter((row) => row.id !== original.id))); await marking
  expect(state.data.newRequests[0].id).toBe(original.id)
  expect(state.newRequestCount).toBe(10)
  expect(mocks.dashboard).toHaveBeenCalledTimes(1)
})

it('does not subtract a late status response twice after a refill already reflects both updates', async () => {
  await mount()
  const firstResponse = deferred()
  mocks.update.mockImplementationOnce((id) => {
    records = records.filter((row) => row.id !== id)
    return firstResponse.promise
  })
  const first = state.markSeen(state.data.newRequests[0]); await tick()
  await state.markSeen(state.data.newRequests.find((row) => row.id === 2))
  expect(state.newRequestCount).toBe(8)
  const lastRefill = deferred()
  mocks.list.mockReturnValueOnce(lastRefill.promise)
  firstResponse.resolve({}); await tick()
  expect(state.newRequestCount).toBe(8)
  lastRefill.resolve(envelope(records)); await first
  expect(state.newRequestCount).toBe(8)
  expect(state.data.newRequests.map((row) => row.id)).toEqual(records.map((row) => row.id))
})
