// @vitest-environment jsdom
import { createApp, h, nextTick, ssrContextKey } from 'vue'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import MediaView from './MediaView.vue'
import source from './MediaView.vue?raw'
import { clientComponent } from '@/test/clientComponent.js'

const mocks = vi.hoisted(() => ({ list: vi.fn(), move: vi.fn(), updated: null }))
vi.mock('@/stores/app.js', () => ({ can: () => true }))
vi.mock('vue-router', () => ({ useRoute: () => ({ query: { folder: 'current' } }), useRouter: () => ({ replace: vi.fn() }) }))
vi.mock('@/api/index.js', () => ({ api: { media: {
  list: mocks.list,
  move: mocks.move,
  usage: async () => [],
  folders: async () => ({ folders: [{ id: 'current', name: 'Current' }, { id: 'elsewhere', name: 'Other' }], total: 2, unfiled: 0 }),
} } }))
vi.mock('@/components/media/FolderTree.vue', () => ({ default: { render: () => null } }))
vi.mock('@/components/media/MediaDrawer.vue', () => ({
  default: { emits: ['changed'], render() { return h('button', { 'data-changed': '', onClick: () => this.$emit('changed', mocks.updated) }) } },
}))
clientComponent(MediaView, source)

const a = { id: 'a', name: 'A.pdf', type: 'pdf', kb: 1, folderId: 'current' }
const b = { ...a, id: 'b', name: 'B.pdf' }
const page = (...items) => ({ items, total: items.length, page: 1, perPage: 50 })
const tick = async () => { for (let i = 0; i < 6; i++) { await Promise.resolve(); await nextTick() } }
let app, host
beforeEach(() => {
  vi.clearAllMocks()
  mocks.list.mockResolvedValue(page(a, b))
  mocks.move.mockResolvedValue({ moved: 1 })
  mocks.updated = a
})
afterEach(() => { app?.unmount(); document.body.innerHTML = '' })
async function mount() {
  host = document.createElement('div'); document.body.append(host)
  app = createApp(MediaView); app.provide(ssrContextKey, { modules: new Set() }); app.mount(host)
  await tick()
}

it('keeps tiles and keyboard focus while a card update refreshes the current page', async () => {
  await mount()
  const tile = host.querySelector('.mdi')
  tile.focus()
  let finish
  mocks.list.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve }))
  host.querySelector('[data-changed]').click(); await tick()
  expect(host.querySelector('.mdi')).toBe(tile)
  expect(document.activeElement).toBe(tile)
  expect(tile.closest('[aria-busy]').getAttribute('aria-busy')).toBe('true')
  finish(page(a, b)); await tick()
  expect(tile.closest('[aria-busy]').getAttribute('aria-busy')).toBe('false')
})

it('excludes a checked file that was moved out through its card from the next bulk move', async () => {
  await mount()
  host.querySelector('.mpick').click()
  host.querySelector('.mdi').click(); await tick()
  mocks.updated = { ...a, folderId: 'elsewhere' }
  mocks.list.mockResolvedValue(page(b))
  host.querySelector('[data-changed]').click(); await tick()
  host.querySelector('.mpick').click(); await tick()
  const target = host.querySelector('.ml-bulk select')
  target.value = 'none'; target.dispatchEvent(new Event('change', { bubbles: true })); await tick()
  expect(mocks.move).toHaveBeenCalledWith(['b'], null)
})
