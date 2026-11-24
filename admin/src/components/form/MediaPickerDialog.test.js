// @vitest-environment jsdom
import { createApp, h, nextTick, ref } from 'vue'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import MediaPickerDialog from './MediaPickerDialog.vue'
import { t } from '@/i18n/index.js'

const mocks = vi.hoisted(() => ({ list: vi.fn(), upload: vi.fn(), error: vi.fn() }))
vi.mock('@/api/index.js', () => ({ api: { media: mocks } }))
vi.mock('@/components/ui/useToast.js', () => ({ useToast: () => ({ show: vi.fn(), error: mocks.error }) }))
vi.mock('@/components/ui/Modal.vue', () => ({ default: {
  props: ['open'], render() { return this.open ? [this.$slots.default?.(), this.$slots.footer?.()] : null },
} }))

let app, host, open, picked
const media = (id) => ({ id: String(id), src: `/photo-${id}.jpg`, alt: `Alt ${id}`, name: `Photo ${id}`, kb: 10 })
const pageResult = ({ page = 1, perPage = 25 } = {}, total = 26) => ({
  page, perPage, total,
  items: Array.from({ length: Math.max(0, Math.min(perPage, total - (page - 1) * perPage)) }, (_, i) => media((page - 1) * perPage + i + 1)),
})
const button = (label) => host.querySelector(`button[aria-label="${label}"]`)
beforeEach(() => {
  vi.clearAllMocks()
  mocks.list.mockImplementation(async (query) => pageResult(query))
})
afterEach(() => { app?.unmount(); host?.remove() })
async function mount() {
  open = ref(true)
  picked = vi.fn()
  host = document.createElement('div')
  document.body.appendChild(host)
  app = createApp({ render: () => h(MediaPickerDialog, { open: open.value, 'onUpdate:open': (value) => { open.value = value }, onPick: picked }) })
  app.mount(host)
  await vi.waitFor(() => expect(host.querySelectorAll('.mdi')).toHaveLength(25))
}

it('loads the next bounded page and picks its photo with the original alt', async () => {
  await mount()
  expect(mocks.list).toHaveBeenLastCalledWith({ q: '', type: 'img', page: 1, perPage: 25 })
  button(t('collections.lp_next')).click()
  await vi.waitFor(() => expect(host.querySelectorAll('.mdi')).toHaveLength(1))
  expect(mocks.list).toHaveBeenLastCalledWith({ q: '', type: 'img', page: 2, perPage: 25 })
  expect(host.querySelector('.mdi').textContent).toContain('Photo 26')
  expect(button(t('collections.lp_next')).disabled).toBe(true)
  host.querySelector('.mdi').click()
  expect(picked).toHaveBeenCalledWith({ src: '/photo-26.jpg', alt: 'Alt 26', name: 'Photo 26' })
  expect(open.value).toBe(false)
})

it('returns to page one for search and page size changes', async () => {
  await mount()
  button(t('collections.lp_next')).click()
  await vi.waitFor(() => expect(host.querySelectorAll('.mdi')).toHaveLength(1))
  const search = host.querySelector('input[type=search]')
  search.value = 'Photo'
  search.dispatchEvent(new Event('input', { bubbles: true }))
  await vi.waitFor(() => expect(mocks.list).toHaveBeenLastCalledWith({ q: 'Photo', type: 'img', page: 1, perPage: 25 }))
  await vi.waitFor(() => expect(host.querySelectorAll('.mdi')).toHaveLength(25))
  const size = host.querySelector('.lp select')
  size.value = '50'
  size.dispatchEvent(new Event('change', { bubbles: true }))
  await vi.waitFor(() => expect(host.querySelectorAll('.mdi')).toHaveLength(26))
  expect(mocks.list).toHaveBeenLastCalledWith({ q: 'Photo', type: 'img', page: 1, perPage: 50 })
})

it('recovers an emptied last page and offers retry after a failed page request', async () => {
  await mount()
  mocks.list.mockResolvedValueOnce(pageResult({ page: 2, perPage: 25 }, 1))
  mocks.list.mockResolvedValueOnce(pageResult({ page: 1, perPage: 25 }, 1))
  button(t('collections.lp_next')).click()
  await vi.waitFor(() => expect(host.querySelectorAll('.mdi')).toHaveLength(1))
  expect(mocks.list).toHaveBeenLastCalledWith({ q: '', type: 'img', page: 1, perPage: 25 })
  expect(host.querySelector('.mdi').textContent).toContain('Photo 1')
  open.value = false
  await nextTick()
  mocks.list.mockRejectedValueOnce(new Error('Media unavailable'))
  open.value = true
  await vi.waitFor(() => expect(host.querySelector('[role=alert]')?.textContent).toContain('Media unavailable'))
  expect(host.querySelector('.mdi')).toBeNull()
  host.querySelector('[role=alert] button').click()
  await vi.waitFor(() => expect(host.querySelectorAll('.mdi')).toHaveLength(25))
  expect(host.querySelector('[role=alert]')).toBeNull()
})

it('ignores a page response that finishes after closing and reopening the picker', async () => {
  await mount()
  let finish
  mocks.list.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve }))
  button(t('collections.lp_next')).click()
  open.value = false
  await nextTick()
  open.value = true
  await vi.waitFor(() => expect(host.querySelectorAll('.mdi')).toHaveLength(25))
  finish(pageResult({ page: 2, perPage: 25 }))
  await nextTick()
  await nextTick()
  expect(host.querySelectorAll('.mdi')).toHaveLength(25)
  expect(host.querySelector('.mdi').textContent).toContain('Photo 1')
})
