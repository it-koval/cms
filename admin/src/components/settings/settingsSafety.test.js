// @vitest-environment jsdom
import { createApp, h, nextTick, reactive, ssrContextKey } from 'vue'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import Mail from './MailPanel.vue'
import mailSource from './MailPanel.vue?raw'
import Theme from './ThemePanel.vue'
import themeSource from './ThemePanel.vue?raw'
import Color from './ThemeColor.vue'
import colorSource from './ThemeColor.vue?raw'
import Bots from './BotsPanel.vue'
import botSource from './BotsPanel.vue?raw'
import { clientComponent } from '@/test/clientComponent.js'
const mocks = vi.hoisted(() => ({ mail: { get: vi.fn(), save: vi.fn() }, theme: { get: vi.fn(), save: vi.fn(), preview: vi.fn() }, bots: { get: vi.fn(), save: vi.fn() } }))
vi.mock('vue-router', () => ({ useRouter: () => ({ afterEach: () => () => {} }), onBeforeRouteLeave: vi.fn(), onBeforeRouteUpdate: vi.fn() }))
vi.mock('@/stores/app.js', () => ({ can: () => true, app: { user: null }, useLocaleGuard: vi.fn() }))
vi.mock('@/api/index.js', () => ({ api: mocks }))
vi.mock('@/utils/theme.js', () => ({ applyTheme: vi.fn() }))
vi.mock('@/components/ui/useToast.js', () => ({ useToast: () => ({ show: vi.fn(), error: vi.fn() }) }))
clientComponent(Mail, mailSource); clientComponent(Theme, themeSource); clientComponent(Color, colorSource); clientComponent(Bots, botSource)
const theme = () => ({ theme: { brand: '#123456', accent: '#654321', radius: 6 }, defaults: { brand: '#123456', accent: '#654321', radius: 6 }, checks: { brand: { ok: true }, accent: { ok: true } }, ladder: { brand: {}, accent: {} }, vars: {} })
let mounted, host
const tick = async () => { for (let i = 0; i < 5; i++) { await Promise.resolve(); await nextTick() } }
beforeEach(() => {
  vi.clearAllMocks()
  mocks.mail.get.mockResolvedValue({ enabled: false, host: '', port: '', encryption: 'starttls', username: '', fromAddress: '', fromName: '' })
  mocks.theme.get.mockResolvedValue(theme()); mocks.theme.preview.mockResolvedValue(theme())
  mocks.bots.get.mockResolvedValue({ telegram: {}, max: {} })
})
afterEach(() => { mounted?.unmount(); document.body.innerHTML = '' })
async function mount(View, props = {}) {
  host = document.createElement('div'); document.body.append(host)
  mounted = createApp({ render: () => h(View, props) }); mounted.provide(ssrContextKey, { modules: new Set() }); mounted.mount(host); await tick()
  return mounted._instance.subTree.component.setupState
}
function expectError(control, text) {
  control.focus()
  expect(document.activeElement).toBe(control)
  expect(control.getAttribute('aria-invalid')).toBe('true')
  const descriptions = (control.getAttribute('aria-describedby') || '').split(' ').map((id) => document.getElementById(id)?.textContent)
  expect(descriptions).toContain(text)
}
it('associates every mail setting error with its focusable control', async () => {
  const state = await mount(Mail)
  const fields = { host: 'ml-host', port: 'ml-port', encryption: 'ml-enc', username: 'ml-user', password: 'ml-pass', fromAddress: 'ml-from', fromName: 'ml-name' }
  for (const key of Object.keys(fields)) state.errors[key] = 'Invalid ' + key
  await tick()
  for (const [key, id] of Object.entries(fields)) expectError(document.getElementById(id), 'Invalid ' + key)
})
it('associates bot token errors as well as the existing help', async () => {
  const state = await mount(Bots)
  for (const channel of ['telegram', 'max']) state.errors[channel] = 'Invalid ' + channel
  await tick()
  for (const channel of ['telegram', 'max']) {
    const control = document.getElementById('bots-' + channel + '-token')
    expectError(control, 'Invalid ' + channel)
    expect(control.getAttribute('aria-describedby').split(' ')).toContain('bots-' + channel + '-help')
  }
})
it('associates color validation and readability failures with both controls', async () => {
  const props = reactive({ id: 'brand', label: 'Brand', modelValue: '#123456', error: 'Invalid color' })
  await mount(Color, props)
  for (const id of ['brand', 'brand-hex']) expectError(document.getElementById(id), 'Invalid color')
  props.error = ''; props.check = { ok: false, ratio: 2 }; await tick()
  const message = host.querySelector('.err').textContent
  for (const id of ['brand', 'brand-hex']) expectError(document.getElementById(id), message)
})
it('associates a radius error with the actual range control', async () => {
  const state = await mount(Theme); state.errors.radius = 'Invalid radius'; await tick()
  expectError(document.getElementById('th-radius'), 'Invalid radius')
})
it.each([['mail', true], ['mail', false], ['theme', true], ['theme', false]])('prevents another edit while the %s save is pending and restores controls after success=%s', async (kind, success) => {
  const state = await mount(kind === 'mail' ? Mail : Theme)
  if (kind === 'mail') state.draft.fromName = 'Submitted sender'
  else state.draft.radius = 8
  let finish, fail
  mocks[kind].save.mockImplementationOnce(() => new Promise((resolve, reject) => { finish = resolve; fail = reject }))
  const saving = state.save(); await tick()
  const ids = kind === 'mail' ? ['ml-host', 'ml-port', 'ml-enc', 'ml-user', 'ml-pass', 'ml-from', 'ml-name'] : ['th-brand', 'th-brand-hex', 'th-accent', 'th-accent-hex', 'th-radius']
  for (const id of ids) expect(document.getElementById(id).disabled, id).toBe(true)
  if (success) finish(kind === 'mail' ? { ...state.draft } : { ...theme(), theme: { ...state.draft } })
  else fail(new Error('Save refused'))
  await saving; await tick()
  expect(kind === 'mail' ? state.draft.fromName : state.draft.radius).toBe(kind === 'mail' ? 'Submitted sender' : 8)
  for (const id of ids) expect(document.getElementById(id).disabled, id).toBe(false)
})

it.each([['ssl', '587'], ['ssl', ''], ['none', '587']])('keeps the saved %s port %s unchanged and clean on load', async (encryption, port) => {
  mocks.mail.get.mockResolvedValueOnce({ enabled: false, host: 'mail.example.test', port, encryption, username: '', fromAddress: '', fromName: '' })
  const state = await mount(Mail)
  expect(state.draft.port).toBe(port)
  expect(state.dirty).toBe(false)
})
it('adjusts the usual port only on an explicit encryption selection and keeps a custom port', async () => {
  mocks.mail.get.mockResolvedValueOnce({ enabled: false, host: '', port: 587, encryption: 'starttls', username: '', fromAddress: '', fromName: '' })
  const state = await mount(Mail)
  const select = document.getElementById('ml-enc')
  select.value = 'ssl'; select.dispatchEvent(new Event('change', { bubbles: true })); await tick()
  expect(state.draft.port).toBe('465')
  state.draft.port = '2525'
  select.value = 'starttls'; select.dispatchEvent(new Event('change', { bubbles: true })); await tick()
  expect(state.draft.port).toBe('2525')
})
