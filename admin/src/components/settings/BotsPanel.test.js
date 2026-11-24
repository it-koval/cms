import { compile, createRenderer, h, nextTick, reactive, ssrContextKey } from 'vue'
import { compileScript, parse } from '@vue/compiler-sfc'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { t } from '@/i18n/index.js'
import BotsPanel from './BotsPanel.vue'
import componentSource from './BotsPanel.vue?raw'

const mocks = vi.hoisted(() => ({ get: vi.fn(), save: vi.fn(), show: vi.fn(), error: vi.fn(), confirm: vi.fn() }))
vi.mock('vue-router', () => ({ useRouter: () => ({ afterEach: () => () => {} }), onBeforeRouteLeave: vi.fn(), onBeforeRouteUpdate: vi.fn() }))
vi.mock('@/api/index.js', () => ({ api: { bots: { get: mocks.get, save: mocks.save } } }))
vi.mock('@/stores/app.js', () => ({ useLocaleGuard: vi.fn(), can: (area, action = 'view') => rights[area]?.includes(action) }))
vi.mock('@/components/ui/useToast.js', () => ({ useToast: () => ({ show: mocks.show, error: mocks.error }) }))
vi.mock('@/components/ui/useConfirm.js', () => ({ useConfirm: () => mocks.confirm }))
vi.mock('@/components/ui/Icon.vue', () => ({ default: { render: () => null } }))
vi.mock('@/components/ui/Skeleton.vue', () => ({ default: { render: () => null } }))
vi.mock('@/components/ui/ReadOnlyNote.vue', () => ({ default: { props: ['text'], render() { return h('p', this.text) } } }))

// Node tests compile SFCs for SSR. Compile the real client template for a DOM-free Vue renderer.
const { descriptor } = parse(componentSource)
const { bindings } = compileScript(descriptor, { id: 'bots-test' })
BotsPanel.render = compile(descriptor.template.content, { bindingMetadata: bindings, prefixIdentifiers: true })

let rights
const state = () => ({ telegram: { tokenSet: true, source: 'server', ready: true }, max: { tokenSet: false, source: null, ready: false } })
const telegramToken = '123456789:' + 'x'.repeat(35)
const mounted = []
const settle = async () => { await Promise.resolve(); await nextTick(); await Promise.resolve(); await nextTick() }
const node = (tag, text = '') => ({ tag, text, props: {}, children: [], parent: null })
const renderer = createRenderer({
  createElement: (tag) => node(tag), createText: (text) => node('#text', text), createComment: () => node('#comment'),
  setText: (el, text) => { el.text = text }, setElementText: (el, text) => { el.text = text; el.children = [] },
  patchProp: (el, key, previous, value) => { el.props[key] = value },
  parentNode: (el) => el.parent, nextSibling: (el) => el.parent?.children[el.parent.children.indexOf(el) + 1] || null,
  setScopeId: () => {},
  insert: (el, parent, anchor = null) => {
    el.parent = parent
    const at = anchor ? parent.children.indexOf(anchor) : -1
    if (at < 0) parent.children.push(el)
    else parent.children.splice(at, 0, el)
  },
  remove: (el) => { el.parent.children.splice(el.parent.children.indexOf(el), 1) },
})
const all = (box) => [box, ...box.children.flatMap(all)]
const content = (box) => box.text + box.children.map(content).join('')
const input = (box, id) => all(box).find((el) => el.props.id === id)
async function mountPanel() {
  const box = node('root')
  const app = renderer.createApp({ render: () => h(BotsPanel) })
  app.provide(ssrContextKey, { modules: new Set() })
  app.mount(box)
  mounted.push(() => app.unmount())
  await settle()
  return box
}
const section = (box, bot) => all(box).find((el) => el.tag === 'section' && el.props['aria-labelledby'] === 'bots-' + bot + '-heading')
const button = (box, key) => all(box).find((el) => el.tag === 'button' && content(el).trim() === t(key))
async function type(box, id, value) {
  input(box, id).props.onInput({ target: { value } })
  await settle()
}

beforeEach(() => {
  vi.clearAllMocks()
  rights = reactive({ 'set:bots': ['view', 'edit'] })
  mocks.get.mockResolvedValue(state())
  mocks.save.mockResolvedValue({ telegram: { tokenSet: true, source: 'admin', ready: true }, max: state().max })
  mocks.confirm.mockResolvedValue(true)
})
afterEach(() => mounted.splice(0).reverse().forEach((unmount) => unmount()))

describe('bot credentials settings', () => {
  it('shows safe server metadata and two empty password fields', async () => {
    const box = await mountPanel()
    expect(mocks.get).toHaveBeenCalledOnce()
    expect(all(box).filter((el) => el.tag === 'input').map((el) => [el.props.type, el.props.value, el.props.autocomplete])).toEqual([
      ['password', '', 'new-password'], ['password', '', 'new-password'],
    ])
    expect(content(box)).toContain(t('settings.bots_source_server'))
    expect(content(box)).toContain(t('settings.bots_not_ready'))
    expect(button(box, 'settings.save').props.disabled).toBe(true)
    expect(all(box).filter((el) => el.tag === 'button' && content(el).trim() === t('settings.save'))).toHaveLength(2)
    expect(input(box, 'bots-telegram-token').props.placeholder).toBe(t('settings.bots_token_server'))
    expect(input(box, 'bots-max-token').props.placeholder).toBe(t('settings.bots_token_none'))
    expect(content(box)).toContain('@BotFather')
    expect(all(box).filter((el) => el.tag === 'a').map((el) => el.props.href)).toEqual(expect.arrayContaining(['https://business.max.ru/', 'https://dev.max.ru/']))
  })

  it('saves only a newly entered token and empties it after success', async () => {
    const box = await mountPanel()
    await type(box, 'bots-telegram-token', telegramToken)
    button(box, 'settings.save').props.onClick()
    await settle()
    expect(mocks.save).toHaveBeenCalledWith({ telegram: { token: telegramToken } })
    expect(input(box, 'bots-telegram-token').props.value).toBe('')
    expect(content(box)).toContain(t('settings.bots_source_admin'))
    expect(button(box, 'settings.save').props.disabled).toBe(true)
  })

  it('confirms removing an admin token and displays the server fallback', async () => {
    mocks.get.mockResolvedValue({ ...state(), telegram: { tokenSet: true, source: 'admin', ready: true } })
    mocks.save.mockResolvedValue(state())
    const box = await mountPanel()
    button(box, 'settings.bots_clear').props.onClick()
    await settle()
    expect(mocks.confirm).toHaveBeenCalledOnce()
    expect(mocks.save).not.toHaveBeenCalled()
    button(box, 'settings.save').props.onClick()
    await settle()
    expect(mocks.save).toHaveBeenCalledWith({ telegram: { clear: true } })
    expect(content(box)).toContain(t('settings.bots_source_server'))
  })

  it('keeps the draft on a failed save and shows field validation', async () => {
    mocks.save.mockRejectedValue({ message: 'Check the token', details: { max: 'Invalid token' } })
    const box = await mountPanel()
    await type(box, 'bots-max-token', 'entered-secret')
    button(section(box, 'max'), 'settings.save').props.onClick()
    await settle()
    expect(input(box, 'bots-max-token').props.value).toBe('entered-secret')
    expect(input(box, 'bots-max-token').props['aria-invalid']).toBe('true')
    expect(content(box)).toContain('Invalid token')
    expect(mocks.error).toHaveBeenCalledWith('Check the token')
  })

  it('saves a single block without discarding the other token draft', async () => {
    const box = await mountPanel()
    await type(box, 'bots-telegram-token', telegramToken)
    await type(box, 'bots-max-token', 'max-new')
    button(section(box, 'telegram'), 'settings.save').props.onClick()
    await settle()
    expect(mocks.save).toHaveBeenCalledWith({ telegram: { token: telegramToken } })
    expect(input(box, 'bots-max-token').props.value).toBe('max-new')
    expect(input(box, 'bots-telegram-token').props.value).toBe('')
    expect(input(box, 'bots-telegram-token').props.placeholder).toBe(t('settings.bots_token_admin'))
  })

  it('supports retry after loading fails', async () => {
    mocks.get.mockRejectedValueOnce(new Error('Offline'))
    const box = await mountPanel()
    expect(content(box)).toContain('Offline')
    button(box, 'settings.retry').props.onClick()
    await settle()
    expect(all(box).filter((el) => el.tag === 'input' && el.props.type === 'password')).toHaveLength(2)
  })

  it('keeps metadata visible for a view-only role and makes controls inert', async () => {
    rights['set:bots'] = ['view']
    const box = await mountPanel()
    expect(content(box)).toContain(t('settings.bots_readonly'))
    expect(all(box).filter((el) => el.props.inert)).toHaveLength(2)
    expect(button(box, 'settings.save')).toBeUndefined()
  })
})
