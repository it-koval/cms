// @vitest-environment jsdom
import { compile, createApp, h, ssrContextKey } from 'vue'
import { compileScript, parse } from '@vue/compiler-sfc'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { app, setContentLocale, switchContentLocale } from '@/stores/app.js'
import { setLanguages } from '@/utils/locales.js'
import { loadSchema } from '@/schema/registry.js'
import { installContentLocaleRouting } from '@/router/contentLocale.js'
import { t } from '@/i18n/index.js'
import SettingsView from './SettingsView.vue'
import settingsSource from './SettingsView.vue?raw'
import LangScope from '@/components/settings/LangScope.vue'
import scopeSource from '@/components/settings/LangScope.vue?raw'
import MenusEditor from '@/components/settings/MenusEditor.vue'
import menusSource from '@/components/settings/MenusEditor.vue?raw'

const mocks = vi.hoisted(() => ({ get: vi.fn(), update: vi.fn(), getNotifications: vi.fn(), updateNotifications: vi.fn(), getMenus: vi.fn(), saveMenus: vi.fn(), getMenuAreas: vi.fn(), forms: vi.fn(), list: vi.fn(), confirm: vi.fn() }))
vi.mock('@/api/index.js', () => ({ api: { site: { currentSiteSync: () => ({}) }, pages: { list: mocks.list }, settings: mocks, requests: { forms: mocks.forms } } }))
vi.mock('@/components/ui/useConfirm.js', () => ({ useConfirm: () => mocks.confirm }))
vi.mock('@/components/ui/Icon.vue', () => ({ default: { render: () => null } }))
// The picker owned by MenusEditor is the only page request under test.
vi.mock('@/components/form/usePageOptions.js', async () => {
  const { ref } = await import('vue')
  return { usePageOptions: () => ref([]) }
})
vi.mock('@/components/ui/LanguageSelect.vue', () => ({ default: {
  props: ['modelValue', 'languages', 'label'], emits: ['update:modelValue'],
  render() { return h('select', { 'aria-label': this.label, value: this.modelValue, onChange: (event) => this.$emit('update:modelValue', event.target.value) }, this.languages.map((l) => h('option', { value: l.code }, l.name))) },
} }))
vi.mock('@/components/settings/SettingsFormTab.vue', () => ({ default: {
  props: ['sections', 'data', 'marks'], emits: ['set', 'revert'], inheritAttrs: false,
  render() { return this.sections.flatMap((s) => s.fields.map((f) => h('input', { name: f.k, value: this.data[f.k], 'data-shared': this.marks && !f.tr, onInput: (event) => this.$emit('set', f.k, event.target.value) }))) },
} }))
vi.mock('@/components/settings/NotificationsPanel.vue', () => ({ default: {
  props: ['data', 'saved', 'forms'], emits: ['set'],
  render() { return ['tgRoutes', 'maxRoutes', 'mailRoutes'].map((key) => h('input', { name: key + '-0', value: this.data[key]?.['0'] || '', onInput: (event) => this.$emit('set', key, { ...this.data[key], '0': event.target.value }) })) },
} }))
vi.mock('@/components/settings/MenuAreaDialog.vue', () => ({ default: { render: () => null } }))
vi.mock('@/components/ui/SortableTree.vue', () => ({ default: {
  props: ['nodes'], emits: ['move', 'invalid'], inheritAttrs: false,
  render() { return this.nodes.flatMap((node) => this.$slots.default({ node, level: 1 })) },
} }))
vi.mock('@/components/settings/MenuRow.vue', () => ({ default: {
  props: ['item', 'pages'], render() { return h('span', { class: 'menu-value' }, this.item.label + ' ' + this.pages.map((p) => p.value).join(' ')) },
} }))

for (const [component, source, id] of [[SettingsView, settingsSource, 'settings'], [LangScope, scopeSource, 'scope'], [MenusEditor, menusSource, 'menus']]) {
  const { descriptor } = parse(source)
  const { bindings } = compileScript(descriptor, { id })
  component.render = compile(descriptor.template.content, { bindingMetadata: bindings, prefixIdentifiers: true })
}

const languages = { main: 'ru', list: ['ru', 'kk', 'zh'].map((code) => ({ code, name: code })) }
const values = { ru: { headline: 'RU title', phone: '123' }, kk: { headline: 'KK title', phone: '123' }, zh: { headline: '', phone: '123' } }
let root, box

beforeEach(() => {
  vi.resetAllMocks()
  app.languages = languages
  setLanguages(languages)
  setContentLocale('ru')
  app.user = { perms: { 'set:contact': ['view', 'edit'], 'set:menus': ['view', 'edit'], pages: ['view'] } }
  loadSchema({ settings: { groups: [{ key: 'contact', title: 'Contact', fields: [{ k: 'headline', t: 'text', label: 'Headline', tr: true, req: true }, { k: 'phone', t: 'text', label: 'Phone' }] }] } })
  mocks.get.mockImplementation(async (locale) => values[locale])
  mocks.getNotifications.mockResolvedValue({})
  mocks.forms.mockResolvedValue([])
  mocks.getMenus.mockImplementation(async (locale) => ({ header: locale === 'zh' ? [] : [{ _id: 'item', label: `${locale} menu`, link: { kind: 'page', page: `/${locale}` } }] }))
  mocks.getMenuAreas.mockResolvedValue([{ key: 'header', name: 'Header', depth: 1, usedIn: [] }])
  mocks.list.mockImplementation(async ({ locale }) => [{ translated: true, status: 'published', url: `/${locale}`, title: locale }])
})
afterEach(() => root?.unmount())

async function mountSettings(path) {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/settings/:tab?', name: 'settings', component: SettingsView }] })
  installContentLocaleRouting(router)
  root = createApp({ render: () => h(RouterView, null, { default: ({ Component }) => h(Component, { key: app.locale }) }) })
  await router.push(path)
  root.use(router)
  root.provide(ssrContextKey, { modules: new Set() })
  box = document.createElement('div')
  root.mount(box)
  await vi.waitFor(() => expect(language()).toBeTruthy())
  return router
}
const language = () => box.querySelector(`select[aria-label="${t('settings.content_language')}"]`)
const field = (name) => box.querySelector(`input[name="${name}"]`)
function edit(name, value) { field(name).value = value; field(name).dispatchEvent(new Event('input')) }
function choose(locale) { language().value = locale; language().dispatchEvent(new Event('change')) }

it.each(['objects', 'legacy arrays'])('keeps numeric notification routes from %s on load and after saving', async (shape) => {
  app.user.perms['set:notifications'] = ['view', 'edit']
  const routes = (telegram) => ({
    tgRoutes: shape === 'objects' ? { '0': telegram } : [telegram],
    maxRoutes: shape === 'objects' ? { '0': '-23' } : ['-23'],
    mailRoutes: shape === 'objects' ? { '0': 'numeric@example.test' } : ['numeric@example.test'],
  })
  mocks.getNotifications.mockResolvedValueOnce(routes('-10012345'))
  const router = await mountSettings('/settings/general')
  await router.push('/settings/notifications')
  await vi.waitFor(() => expect(field('tgRoutes-0')?.value).toBe('-10012345'))
  expect(field('maxRoutes-0').value).toBe('-23')
  expect(field('mailRoutes-0').value).toBe('numeric@example.test')
  mocks.updateNotifications.mockResolvedValueOnce(routes('-10054321'))
  edit('tgRoutes-0', '-10054321')
  await vi.waitFor(() => expect(box.querySelector('.savebar .primary')).toBeTruthy())
  box.querySelector('.savebar .primary').click()
  await vi.waitFor(() => expect(mocks.updateNotifications).toHaveBeenCalledWith({ tgRoutes: { '0': '-10054321' } }))
  await vi.waitFor(() => expect(box.querySelector('.savebar .primary')).toBeNull())
  expect(field('tgRoutes-0').value).toBe('-10054321')
  expect(field('maxRoutes-0').value).toBe('-23')
  expect(field('mailRoutes-0').value).toBe('numeric@example.test')
})

it('keeps missing translations empty and saves a shared field even when a required translation is missing', async () => {
  await mountSettings('/settings/general?lang=kk')
  expect(field('headline').value).toBe('KK title')
  expect(box.querySelectorAll('[data-shared="true"]')).toHaveLength(1)
  choose('zh')
  await vi.waitFor(() => expect(field('headline')?.value).toBe(''))
  expect(field('phone').value).toBe('123')
  mocks.update.mockResolvedValueOnce({ ...values.zh, phone: '456' })
  edit('phone', '456')
  await vi.waitFor(() => expect(box.querySelector('.savebar .primary')).toBeTruthy())
  box.querySelector('.savebar .primary').click()
  await vi.waitFor(() => expect(mocks.update).toHaveBeenCalledWith({ phone: '456' }, 'zh'))
})

it('keeps failed input when a language switch is declined and ignores a slow settings load from the previous language', async () => {
  const router = await mountSettings('/settings/general')
  mocks.update.mockRejectedValueOnce(new Error('Save rejected'))
  edit('headline', 'Unsaved')
  await vi.waitFor(() => expect(box.querySelector('.savebar .primary')).toBeTruthy())
  box.querySelector('.savebar .primary').click()
  await vi.waitFor(() => expect(mocks.update).toHaveBeenCalledWith({ headline: 'Unsaved' }, 'ru'))
  mocks.confirm.mockResolvedValueOnce(false)
  await router.push('/settings/general?lang=kk')
  expect(field('headline').value).toBe('Unsaved')
  expect(language().value).toBe('ru')
  let complete
  mocks.get.mockImplementationOnce(() => new Promise((resolve) => { complete = resolve }))
  mocks.confirm.mockResolvedValueOnce(true)
  await switchContentLocale('kk')
  await vi.waitFor(() => expect(complete).toBeTypeOf('function'))
  await switchContentLocale('zh')
  await vi.waitFor(() => expect(field('headline')?.value).toBe(''))
  complete(values.kk)
  await vi.waitFor(() => expect(language().value).toBe('zh'))
  expect(field('headline').value).toBe('')
})

it('waits for an in-flight shared save before opening another language', async () => {
  await mountSettings('/settings/general')
  let complete
  const pending = new Promise((resolve) => { complete = resolve })
  mocks.update.mockReturnValueOnce(pending)
  edit('phone', '789')
  await vi.waitFor(() => expect(box.querySelector('.savebar .primary')).toBeTruthy())
  box.querySelector('.savebar .primary').click()
  mocks.get.mockImplementation(async (locale) => ({ ...values[locale], phone: '789' }))
  mocks.confirm.mockResolvedValue(true)
  const switched = switchContentLocale('kk')
  await new Promise((resolve) => setTimeout(resolve, 0))
  expect(app.locale).toBe('ru')
  expect(mocks.get.mock.calls).toEqual([['ru']])
  complete({ ...values.ru, phone: '789' })
  expect(await switched).toBe(true)
  await vi.waitFor(() => expect(field('phone')?.value).toBe('789'))
  expect(mocks.confirm).not.toHaveBeenCalled()
})

it('switches menus and their page choices together and leaves missing menu versions empty', async () => {
  await mountSettings('/settings/menus?lang=kk')
  await vi.waitFor(() => expect(box.querySelector('.menu-value')?.textContent).toBe('kk menu /kk'))
  choose('zh')
  await vi.waitFor(() => expect(box.querySelector('.mn-empty')).toBeTruthy())
  expect(language().value).toBe('zh')
  expect(box.querySelector('.menu-value')).toBeNull()
  expect(box.querySelector('.mn-empty').textContent).toContain(t('settings.menu_empty'))
  expect(mocks.list).toHaveBeenCalledWith({ locale: 'zh' })
})

it('offers retry instead of copying menu page links without the language page list', async () => {
  mocks.list.mockRejectedValueOnce(new Error('Pages unavailable'))
  await mountSettings('/settings/menus?lang=zh')
  await vi.waitFor(() => expect(mocks.list).toHaveBeenCalledWith({ locale: 'zh' }))
  expect(box.querySelector('.mn-copy')).toBeNull()
  const retry = box.querySelector('.mn-page-load button')
  expect(retry).toBeTruthy()
  retry.click()
  await vi.waitFor(() => expect(box.querySelector('.mn-copy')).toBeTruthy())
  expect(mocks.list).toHaveBeenCalledTimes(2)
})

it('only copies menus with page links when the editor can read pages', async () => {
  delete app.user.perms.pages
  await mountSettings('/settings/menus?lang=zh')
  expect(mocks.list).not.toHaveBeenCalled()
  expect(box.querySelector('.mn-copy')).toBeNull()
  expect(box.querySelector('.mn-page-load')).toBeNull()
})

it('restores the mobile section selector after cancelled navigation and lets the same option be retried', async () => {
  const router = await mountSettings('/settings/general')
  window.scrollTo = vi.fn()
  const block = router.beforeResolve(() => false)
  const select = box.querySelector('#set-sel')
  select.value = 'menus'; select.dispatchEvent(new Event('change'))
  await vi.waitFor(() => expect(router.currentRoute.value.path).toBe('/settings/general'))
  await vi.waitFor(() => expect(select.value).toBe('general'))
  block()
  select.value = 'menus'; select.dispatchEvent(new Event('change'))
  await vi.waitFor(() => expect(router.currentRoute.value.path).toBe('/settings/menus'))
  expect(select.value).toBe('menus')
})
