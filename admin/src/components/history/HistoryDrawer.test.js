// @vitest-environment jsdom
import { compile, createApp, h, nextTick, reactive, ssrContextKey } from 'vue'
import { compileScript, parse } from '@vue/compiler-sfc'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { t } from '@/i18n/index.js'
import HistoryDrawer from './HistoryDrawer.vue'
import componentSource from './HistoryDrawer.vue?raw'

const mocks = vi.hoisted(() => ({ list: vi.fn(), entry: vi.fn(), restore: vi.fn(), confirm: vi.fn() }))
vi.mock('@/api/index.js', () => ({ api: { history: mocks } }))
vi.mock('@/components/ui/useConfirm.js', () => ({ useConfirm: () => mocks.confirm }))
vi.mock('@/components/ui/useToast.js', () => ({ useToast: () => ({ show: vi.fn(), error: vi.fn() }) }))
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
async function openRevision(action) {
  const entry = revision(action)
  mocks.list.mockResolvedValue({ items: [entry], total: 1, keep: 100 })
  mocks.entry.mockResolvedValue(entry)
  box = document.createElement('div')
  const props = reactive({ open: false, kind: 'page', resourceId: 'page-id' })
  app = createApp({ render: () => h(HistoryDrawer, props) })
  app.provide(ssrContextKey, { modules: new Set() })
  app.mount(box)
  props.open = true
  await settle()
  const currentBadge = box.querySelector('.hd-now')
  box.querySelector('.hd-row').click()
  await settle()
  return currentBadge
}
beforeEach(() => vi.clearAllMocks())
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
