// @vitest-environment jsdom
import { createApp, h, nextTick, ssrContextKey } from 'vue'
import { parse } from '@vue/compiler-sfc'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import Requests from './RequestsView.vue'
import source from './RequestsView.vue?raw'
import Pager from '@/components/collections/list/ListPager.vue'
import pagerSource from '@/components/collections/list/ListPager.vue?raw'
import Filters from '@/components/ui/FilterSheet.vue'
import filterSource from '@/components/ui/FilterSheet.vue?raw'
import Modal from '@/components/ui/Modal.vue'
import modalSource from '@/components/ui/Modal.vue?raw'
import { clientComponent } from '@/test/clientComponent.js'
import { t } from '@/i18n/index.js'
const mocks = vi.hoisted(() => ({ list: vi.fn(), vp: null, push: vi.fn() }))
vi.mock('@/utils/viewport.js', async () => {
  mocks.vp = (await import('vue')).reactive({ phone: true, compact: true, touch: true })
  return { vp: mocks.vp }
})
vi.mock('@/stores/app.js', () => ({ app: { languages: { main: 'ru', list: [{ code: 'ru' }] } }, can: () => true, refreshCounters: vi.fn() }))
vi.mock('vue-router', () => ({ useRoute: () => ({ params: {}, query: {} }), useRouter: () => ({ push: mocks.push }) }))
vi.mock('@/api/index.js', () => ({ api: { requests: { list: mocks.list, forms: async () => [{ key: 'short', title: 'Contact' }, { key: 'long', title: 'A very long form title for customers requesting a detailed individual consultation with the project team' }] }, prefs: { get: async () => 'table', put: async () => ({}) } } }))
vi.mock('@/components/requests/RequestDrawer.vue', () => ({ default: { emits: ['changed'], render() { return h('button', { 'data-request-changed': '', onClick: () => this.$emit('changed') }) } } }))
clientComponent(Requests, source); clientComponent(Pager, pagerSource); clientComponent(Filters, filterSource); clientComponent(Modal, modalSource)
const longTitle = 'A very long form title for customers requesting a detailed individual consultation with the project team'
const records = Array.from({ length: 120 }, (_, index) => ({ id: index + 1, phone: '+7 900 123 45 67', dt: '2026-11-24T10:00:00Z', city: 'City', form: index % 2 ? 'long' : 'short', place: index % 2 ? longTitle : 'Contact', work: 'new' }))
let mounted, host
const tick = async () => { for (let i = 0; i < 7; i++) { await Promise.resolve(); await nextTick() } }
const button = (label) => [...document.querySelectorAll('button')].find((el) => el.getAttribute('aria-label') === label || el.textContent.trim() === label)
beforeEach(() => {
  vi.clearAllMocks()
  mocks.list.mockImplementation(async ({ page, perPage, form }) => {
    const selected = records.filter((row) => !form || row.form === form)
    return { items: selected.slice((page - 1) * perPage, page * perPage), total: selected.length, page, perPage }
  })
})
afterEach(() => { mounted?.unmount(); document.body.innerHTML = ''; document.head.querySelectorAll('[data-requests-test]').forEach((el) => el.remove()) })
async function mount(width) {
  Object.defineProperty(window, 'innerWidth', { configurable: true, value: width })
  Object.assign(mocks.vp, { phone: width < 768, compact: width < 768, touch: width < 768 })
  const style = document.createElement('style'); style.dataset.requestsTest = ''; style.textContent = parse(source).descriptor.styles.map((sheet) => sheet.content).join('\n'); document.head.append(style)
  host = document.createElement('div'); host.style.width = width + 'px'; document.body.append(host)
  mounted = createApp({ render: () => h(Requests) }); mounted.provide(ssrContextKey, { modules: new Set() }); mounted.mount(host); await tick()
}
it.each([375, 320, 1280])('keeps short and long form names complete in their labeled table cells at %ipx', async (width) => {
  await mount(width)
  const cells = [...host.querySelectorAll('tbody td.rq-form')]
  expect(cells).toHaveLength(50)
  expect(cells.slice(0, 2).map((cell) => cell.textContent)).toEqual(['Contact', longTitle])
  cells.slice(0, 2).forEach((cell) => {
    expect(cell.dataset.label).toBe(t('ui.rq_form'))
    expect(cell.classList.contains('ts-line')).toBe(true)
    expect(cell.querySelector('.tag')).toBeNull()
    expect(getComputedStyle(cell).overflowWrap).toBe('anywhere')
  })
})
it.each([375, 320, 1280])('loads the next server page and resets to page one when the form filter changes at %ipx', async (width) => {
  await mount(width)
  expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ page: 1, perPage: 50, form: '' }))
  button(t('collections.lp_next')).click(); await tick()
  expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2, perPage: 50 }))
  expect(host.querySelector('tbody .rq-id').textContent).toBe('51')
  if (width < 768) { button(t('ui.filters')).click(); await tick() }
  const filter = document.querySelector('select[aria-label="' + t('ui.rq_form') + '"]')
  filter.value = 'long'; filter.dispatchEvent(new Event('change', { bubbles: true })); await tick()
  expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ page: 1, perPage: 50, form: 'long' }))
  expect(host.querySelector('tbody .rq-id').textContent).toBe('2')
  expect([...host.querySelectorAll('tbody td.rq-form')].every((cell) => cell.textContent === longTitle)).toBe(true)
  if (width < 768) { button(t('ui.done')).click(); await tick() }
  const size = host.querySelector('.lp-s select'); size.value = '25'; size.dispatchEvent(new Event('change', { bubbles: true })); await tick()
  expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ page: 1, perPage: 25, form: 'long' }))
  expect(host.querySelectorAll('tbody tr')).toHaveLength(25)
})

it('keeps the existing row connected and focused while the drawer refreshes its page', async () => {
  await mount(375)
  const row = host.querySelector('tbody tr')
  row.focus()
  let finish
  mocks.list.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve }))
  host.querySelector('[data-request-changed]').click(); await tick()
  expect(host.querySelector('tbody tr')).toBe(row)
  expect(document.activeElement).toBe(row)
  expect(row.closest('[aria-busy]').getAttribute('aria-busy')).toBe('true')
  finish({ items: records.slice(0, 50), total: 120, page: 1, perPage: 50 }); await tick()
  expect(row.closest('[aria-busy]').getAttribute('aria-busy')).toBe('false')
})

it('uses the returned cursors for adjacent pages and clears them for filter changes', async () => {
  mocks.list.mockResolvedValueOnce({ items: records.slice(0, 50), total: 120, page: 1, perPage: 50, nextCursor: 'older-than-50', previousCursor: null })
  await mount(375)
  mocks.list.mockResolvedValueOnce({ items: records.slice(50, 100), total: 120, page: 2, perPage: 50, nextCursor: 'older-than-100', previousCursor: 'newer-than-51' })
  button(t('collections.lp_next')).click(); await tick()
  expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2, cursor: 'older-than-50' }))
  button(t('collections.lp_prev')).click(); await tick()
  expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ page: 1, cursor: 'newer-than-51' }))
  button(t('ui.filters')).click(); await tick()
  const filter = document.querySelector('select[aria-label="' + t('ui.rq_form') + '"]')
  filter.value = 'long'; filter.dispatchEvent(new Event('change', { bubbles: true })); await tick()
  expect(mocks.list.mock.lastCall[0]).not.toHaveProperty('cursor')
})
