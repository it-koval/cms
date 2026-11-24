// @vitest-environment jsdom
import { createApp, h, nextTick, ssrContextKey } from 'vue'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import Mail from './MailPanel.vue'
import Theme from './ThemePanel.vue'
import Bots from './BotsPanel.vue'
const mocks = vi.hoisted(() => ({ leave: [], update: [], locale: [], confirm: vi.fn(), mail: { get: vi.fn(), save: vi.fn() }, theme: { get: vi.fn(), save: vi.fn(), preview: vi.fn() }, bots: { get: vi.fn(), save: vi.fn() } }))
vi.mock('vue-router', () => ({ useRouter: () => ({ afterEach: () => () => {} }), onBeforeRouteLeave: (fn) => mocks.leave.push(fn), onBeforeRouteUpdate: (fn) => mocks.update.push(fn) }))
vi.mock('@/stores/app.js', () => ({ can: () => true, app: { user: null }, useLocaleGuard: (fn) => mocks.locale.push(fn) }))
vi.mock('@/api/index.js', () => ({ api: mocks }))
vi.mock('@/components/ui/useConfirm.js', () => ({ useConfirm: () => mocks.confirm }))
vi.mock('@/components/ui/useToast.js', () => ({ useToast: () => ({ show: vi.fn(), error: vi.fn() }) }))
vi.mock('@/utils/theme.js', () => ({ applyTheme: vi.fn() }))
let mounted
const settle = async () => { for (let i = 0; i < 4; i++) { await Promise.resolve(); await nextTick() } }
beforeEach(() => {
  vi.clearAllMocks(); mocks.leave = []; mocks.update = []; mocks.locale = []
  mocks.confirm.mockResolvedValue(false)
  mocks.mail.get.mockResolvedValue({ enabled: false, host: '', port: '', encryption: 'starttls', username: '', fromAddress: '', fromName: '' })
  const theme = { theme: { brand: '#123456', accent: '#654321', radius: 6 }, defaults: { brand: '#123456', accent: '#654321', radius: 6 }, checks: { brand: { ok: true }, accent: { ok: true } } }
  mocks.theme.get.mockResolvedValue(theme); mocks.theme.preview.mockResolvedValue(theme)
  mocks.bots.get.mockResolvedValue({ telegram: {}, max: {} })
})
afterEach(() => mounted?.unmount())
const cases = [
  [Mail, (s) => { s.draft.fromName = 'Changed sender' }, async (s) => { mocks.mail.save.mockResolvedValue({ ...s.draft }); await s.save() }],
  [Theme, (s) => { s.draft.radius = 8 }, async (s) => { mocks.theme.save.mockResolvedValue({ ...s.saved, theme: { ...s.draft } }); await s.save() }],
  [Bots, (s) => { s.tokens.telegram = 'entered-token' }, async (s) => { mocks.bots.save.mockResolvedValue({ telegram: {}, max: {} }); await s.save('telegram') }],
]
it.each(cases)('protects %s drafts on section, route and language changes, then releases after save', async (View, edit, save) => {
  View.render = () => null
  mounted = createApp({ render: () => h(View) }); mounted.provide(ssrContextKey, { modules: new Set() }); mounted.mount(document.createElement('div'))
  const state = mounted._instance.subTree.component.setupState
  await settle(); edit(state); await settle()
  expect(mocks.leave).toHaveLength(1); expect(mocks.update).toHaveLength(1); expect(mocks.locale).toHaveLength(1)
  for (const guard of [mocks.leave[0], mocks.update[0], mocks.locale[0]]) expect(await guard()).toBe(false)
  const unload = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(unload); expect(unload.defaultPrevented).toBe(true)
  mocks.confirm.mockResolvedValueOnce(true); expect(await mocks.leave[0]()).toBe(true)
  await save(state); mocks.confirm.mockClear()
  expect(await mocks.leave[0]()).toBe(true); expect(mocks.confirm).not.toHaveBeenCalled()
})
