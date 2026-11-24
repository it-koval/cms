// @vitest-environment jsdom
import { compile, createApp, h, nextTick, reactive, ssrContextKey } from 'vue'
import { compileScript, parse } from '@vue/compiler-sfc'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { t } from '@/i18n/index.js'
import { setLanguages } from '@/utils/locales.js'
import HistoryDrawer from './HistoryDrawer.vue'
import componentSource from './HistoryDrawer.vue?raw'

const mocks = vi.hoisted(() => ({ list: vi.fn(), entry: vi.fn(), restore: vi.fn(), confirm: vi.fn(), notice: vi.fn() }))
vi.mock('@/api/index.js', () => ({ api: { history: mocks } }))
vi.mock('@/components/ui/useConfirm.js', () => ({ useConfirm: () => mocks.confirm }))
vi.mock('@/components/ui/useToast.js', () => ({ useToast: () => ({ show: mocks.notice, error: vi.fn() }) }))
vi.mock('@/components/ui/Drawer.vue', () => ({ default: { props: ['open'], render() { return this.open ? h('aside', [this.$slots.default?.(), this.$slots.footer?.()]) : null } } }))
vi.mock('@/components/ui/Icon.vue', () => ({ default: { render: () => null } }))
vi.mock('@/components/ui/Skeleton.vue', () => ({ default: { render: () => null } }))
vi.mock('@/components/ui/EmptyState.vue', () => ({ default: { render: () => null } }))
vi.mock('./ChangeRows.vue', () => ({ default: { render: () => null } }))

const { descriptor } = parse(componentSource)
const { bindings } = compileScript(descriptor, { id: 'history-test' })
HistoryDrawer.render = compile(descriptor.template.content, { bindingMetadata: bindings, prefixIdentifiers: true })
const settle = async () => { await Promise.resolve(); await nextTick(); await Promise.resolve(); await nextTick() }
let app, box
const revision = (action) => ({ id: 1, action, summary: [], against: [], userName: 'Editor', updatedAt: '2026-11-24T00:00:00Z', current: false, canRestore: action !== 'delete_locale' })
function mountDrawer(options = {}) {
  box = document.createElement('div')
  const props = reactive({ open: false, kind: 'page', resourceId: 'page-id', ...options })
  app = createApp({ render: () => h(HistoryDrawer, props) })
  app.provide(ssrContextKey, { modules: new Set() })
  app.mount(box)
  props.open = true
  return props
}
async function openRevision(action) {
  const entry = revision(action)
  mocks.list.mockResolvedValue({ items: [entry], total: 1, keep: 100 })
  mocks.entry.mockResolvedValue(entry)
  mountDrawer()
  await settle()
  const currentBadge = box.querySelector('.hd-now')
  box.querySelector('.hd-row').click()
  await settle()
  return currentBadge
}
const languages = [{ code: 'ru', name: 'Русский' }, { code: 'kk', name: 'Қазақша' }]
beforeEach(() => { vi.resetAllMocks(); setLanguages({ main: 'ru', list: languages.slice(0, 1) }) })
afterEach(() => app?.unmount())

it('shows a translation deletion as an audit entry without a current badge or restore', async () => {
  expect(await openRevision('delete_locale')).toBeNull()
  expect(box.textContent).not.toContain(t('ui.history_restore'))
  expect(box.textContent).not.toContain(t('ui.history_what_changes'))
  expect(box.textContent).toContain(t('ui.back'))
  expect(mocks.restore).not.toHaveBeenCalled()
})

it('keeps the restore action and comparison for an earlier editable version', async () => {
  await openRevision('edit')
  expect(box.textContent).toContain(t('ui.history_restore'))
  expect(box.textContent).toContain(t('ui.history_what_changes'))
})

it('shows the current revision of each language and restores a different language without replacing this editor', async () => {
  setLanguages({ main: 'ru', list: languages })
  const current = { ...revision('edit'), current: true, locale: 'ru' }
  const kk = { ...current, id: 2, locale: 'kk' }
  const old = { ...kk, id: 3, current: false }
  mocks.list.mockImplementation(async (_kind, _id, { scope }) => ({ items: scope === 'all' ? [current, kk, old] : [current], total: scope === 'all' ? 3 : 1, keep: 100 }))
  mocks.entry.mockResolvedValue(old)
  mocks.confirm.mockResolvedValue(true)
  mocks.restore.mockResolvedValue({ locale: 'kk' })
  const onRestored = vi.fn()
  mountDrawer({ locale: 'ru', onRestored })
  await settle()
  expect(mocks.list.mock.lastCall[2]).toMatchObject({ locale: 'ru' })
  box.querySelector('[data-history-scope="all"]').click()
  await settle()
  expect(mocks.list.mock.lastCall[2]).toMatchObject({ scope: 'all' })
  expect(box.querySelectorAll('.hd-now')).toHaveLength(2)
  expect(box.textContent).toContain('Қазақша')
  box.querySelectorAll('.hd-row')[2].click()
  await settle()
  box.querySelector('.btn.primary').click()
  await settle()
  expect(mocks.confirm.mock.lastCall[0].message).toContain('Қазақша')
  expect(mocks.restore).toHaveBeenCalledWith(3)
  expect(mocks.notice.mock.lastCall[0]).toContain('Қазақша')
  expect(onRestored).not.toHaveBeenCalled()
})

it('drops detail responses from a previous language and list responses from a previous scope', async () => {
  setLanguages({ main: 'ru', list: languages })
  const ru = { ...revision('edit'), locale: 'ru' }
  let resolveList, resolveEntry
  mocks.list.mockImplementation(async (_kind, _id, { locale, scope }) => scope === 'all'
    ? new Promise((resolve) => { resolveList = resolve })
    : { items: locale === 'ru' ? [ru] : [], total: locale === 'ru' ? 1 : 0 })
  mocks.entry.mockImplementation(() => new Promise((resolve) => { resolveEntry = resolve }))
  const props = mountDrawer({ locale: 'ru' })
  await settle()
  box.querySelector('.hd-row').click()
  box.querySelector('[data-history-scope="all"]').click()
  await settle()
  props.locale = 'kk'
  await settle()
  resolveList({ items: [ru], total: 1 })
  resolveEntry(ru)
  await settle()
  expect(box.querySelector('.hd-row')).toBeNull()
  expect(box.querySelector('.hd-lead')).toBeNull()
  expect(mocks.list.mock.lastCall[2]).toMatchObject({ locale: 'kk' })
})

it('updates the open language after restore and ignores completion after the language changes', async () => {
  setLanguages({ main: 'ru', list: languages })
  const kk = { ...revision('edit'), locale: 'kk' }
  const result = { locale: 'kk' }
  mocks.list.mockResolvedValue({ items: [kk], total: 1, keep: 100 })
  mocks.entry.mockResolvedValue(kk)
  mocks.confirm.mockResolvedValue(true)
  mocks.restore.mockResolvedValueOnce(result)
  const onRestored = vi.fn()
  const props = mountDrawer({ locale: 'kk', onRestored })
  await settle()
  box.querySelector('.hd-row').click()
  await settle()
  box.querySelector('.btn.primary').click()
  await settle()
  expect(onRestored).toHaveBeenCalledExactlyOnceWith(result)

  let complete
  mocks.restore.mockImplementationOnce(() => new Promise((resolve) => { complete = resolve }))
  box.querySelector('.hd-row').click()
  await settle()
  box.querySelector('.btn.primary').click()
  await settle()
  props.locale = 'ru'
  await settle()
  complete(result)
  await settle()
  expect(onRestored).toHaveBeenCalledTimes(1)
  expect(mocks.notice).toHaveBeenCalledTimes(1)
  expect(mocks.list.mock.lastCall[2]).toMatchObject({ locale: 'ru' })
})

it('refreshes the current editor after a sent restore even when the drawer was closed', async () => {
  const entry = { ...revision('edit'), locale: 'ru' }
  mocks.list.mockResolvedValue({ items: [entry], total: 1, keep: 100 })
  mocks.entry.mockResolvedValue(entry)
  mocks.confirm.mockResolvedValue(true)
  let complete
  mocks.restore.mockImplementation(() => new Promise((resolve) => { complete = resolve }))
  const onRestored = vi.fn()
  const props = mountDrawer({ locale: 'ru', onRestored })
  await settle()
  box.querySelector('.hd-row').click()
  await settle()
  box.querySelector('.btn.primary').click()
  await settle()
  props.open = false
  await settle()
  complete({ locale: 'ru' })
  await settle()
  expect(onRestored).toHaveBeenCalledExactlyOnceWith({ locale: 'ru' })
  expect(mocks.notice).toHaveBeenCalledTimes(1)
  expect(mocks.list).toHaveBeenCalledTimes(1)
})
