// @vitest-environment jsdom
import { compile, createApp, h, nextTick, reactive } from 'vue'
import { compileScript, parse } from '@vue/compiler-sfc'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { t } from '@/i18n/index.js'
import View from './RelationField.vue'
import source from './RelationField.vue?raw'

const mocks = vi.hoisted(() => ({ list: vi.fn(), app: { locale: 'kk' } }))
vi.mock('@/api/index.js', () => ({ api: { collections: { list: mocks.list } } }))
vi.mock('@/stores/app.js', () => ({ app: mocks.app }))
vi.mock('@/schema/collections.js', () => ({ getCollectionConfig: () => ({ titleField: 'title' }) }))
vi.mock('../FieldShell.vue', () => ({ default: { render() { return h('div', this.$slots.default?.()) } } }))
const { descriptor } = parse(source)
const { bindings } = compileScript(descriptor, { id: 'relation-test' })
View.render = compile(descriptor.template.content, { bindingMetadata: bindings, prefixIdentifiers: true })
const settle = async () => { for (let i = 0; i < 5; i++) { await Promise.resolve(); await nextTick() } }
let app, box, props
beforeEach(() => { mocks.list.mockReset(); mocks.list.mockResolvedValue([{ id: 'one', title: 'Қазақша', translated: true }, { id: 'two', title: 'Русский автор', translated: false }, { id: 'three', title: 'Hidden untranslated', translated: false }]) })
afterEach(() => app?.unmount())
async function mount(multi, value) {
  props = reactive({ field: { k: 'author', t: 'relation', to: `authors-${multi}`, label: 'Author', multi }, modelValue: value, path: 'author', 'onUpdate:modelValue': (v) => { props.modelValue = v } })
  app = createApp({ render: () => h(View, props) })
  box = document.createElement('div')
  app.mount(box)
  await settle()
}

it('shows a selected untranslated relation with its source title, permits clearing it and keeps it unavailable for new selections', async () => {
  await mount(false, 'two')
  const option = box.querySelector('option[value="two"]')
  expect(option.textContent).toContain('Русский автор')
  expect(option.textContent).toContain(t('forms.relation_untranslated', { title: '' }).trim())
  expect(option.disabled).toBe(true)
  expect(box.querySelector('option[value="three"]')).toBeNull()
  const select = box.querySelector('select')
  select.value = ''
  select.dispatchEvent(new Event('change'))
  await settle()
  expect(props.modelValue).toBe('')
  expect(box.querySelector('option[value="two"]')).toBeNull()
})

it('keeps untranslated and deleted multi-relations visible and removable', async () => {
  await mount(true, ['two', 'gone'])
  expect(box.textContent).toContain('Русский автор')
  expect(box.textContent).toContain(t('forms.relation_gone'))
  expect(box.textContent).not.toContain('Hidden untranslated')
  const chosen = [...box.querySelectorAll('input:checked')]
  expect(chosen).toHaveLength(2)
  chosen[0].checked = false
  chosen[0].dispatchEvent(new Event('change'))
  await settle()
  expect(props.modelValue).toEqual(['gone'])
  expect(box.textContent).not.toContain('Русский автор')
})
