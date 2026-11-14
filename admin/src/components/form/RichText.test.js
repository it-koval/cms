// @vitest-environment jsdom
import { createApp, h, nextTick, reactive } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import RichText from './RichText.vue'
import { sanitizeHtml, setHeadlineMarks } from '@/utils/html.js'
import { t } from '@/i18n/index.js'

// The picker is unrelated to editing; the component, editor, schema and toolbar stay real.
vi.mock('./MediaPickerDialog.vue', () => ({ default: { render: () => null } }))

const mounted = []
const settle = async () => {
  await nextTick()
  await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)))
  await nextTick()
}
async function mountEditor(options = {}) {
  const props = reactive({ mode: 'article', modelValue: '', ...options })
  const changes = vi.fn((value) => { props.modelValue = value })
  const box = document.createElement('div')
  document.body.append(box)
  const app = createApp({ render: () => h(RichText, { ...props, 'onUpdate:modelValue': changes }) })
  app.mount(box)
  mounted.push(() => { app.unmount(); box.remove() })
  await settle()
  const editor = box.querySelector('.tiptap').editor
  // jsdom has no layout/Range geometry. Keep content commands real and skip their scrolling.
  editor.setOptions({ editorProps: { ...editor.options.editorProps, handleScrollToSelection: () => true } })
  const button = (key) => [...box.querySelectorAll('button')].find((b) => b.getAttribute('aria-label') === t(key) || b.textContent.trim() === t(key))
  const click = async (key) => { const b = button(key); expect(b, key).toBeDefined(); b.click(); await settle(); return b }
  return { props, changes, box, editor, button, click }
}
afterEach(() => {
  mounted.splice(0).reverse().forEach((unmount) => unmount())
  setHeadlineMarks(['gradient'])
  delete window.ITK_ADMIN
})

describe('RichText: Tiptap 3 document and editing contract', () => {
  it.each(['headline', 'rich', 'article'])('external %s replacements update the document without update events or model emissions', async (mode) => {
    const { props, changes, editor } = await mountEditor({ mode, modelValue: 'old' })
    const updates = vi.fn()
    editor.on('update', updates)
    props.modelValue = mode === 'headline' ? 'Restored <span class="grad">headline</span><br>second line' : '<p>Restored <strong>text</strong></p>'
    await settle()
    expect(editor.getText()).toContain('Restored')
    expect(updates).not.toHaveBeenCalled()
    expect(changes).not.toHaveBeenCalled()
    props.modelValue = ''
    await settle()
    expect(editor.isEmpty).toBe(true)
    expect(updates).not.toHaveBeenCalled()
    expect(changes).not.toHaveBeenCalled()
  })

  it('round-trips legacy article tables, image attributes and original addresses after editing and reload', async () => {
    window.ITK_ADMIN = { images: { uploadUrl: '/uploads', variantDir: '_variants', widths: [320, 640, 960] } }
    const legacy = '<h2>Article</h2><p><strong>Bold</strong> <em>italic</em> <a href="/relative">link</a></p>'
      + '<blockquote><p>Quote</p></blockquote><ul><li><p>Bullet</p></li></ul><ol><li><p>Number</p></li></ol><hr>'
      + '<table><thead><tr><th colspan="2">Heading</th></tr></thead><tbody><tr><td rowspan="2"><strong>First</strong><br>line</td><td>Top</td></tr><tr><td><a href="https://example.com">Bottom</a></td></tr></tbody></table>'
      + '<img src="/uploads/2026/original.jpg" alt="Photo" width="640" height="480" data-align="right">'
    const { changes, box, editor } = await mountEditor({ modelValue: legacy })
    expect(changes).not.toHaveBeenCalled()
    expect(box.querySelector('.tiptap img').getAttribute('src')).toBe('/uploads/_variants/w960/2026/original.webp')
    expect(editor.state.doc.lastChild.type.name).toBe('image')
    expect(editor.state.doc.lastChild.attrs).toMatchObject({ src: '/uploads/2026/original.jpg', width: '640', height: '480', align: 'right' })
    editor.commands.insertContentAt(1, '!')
    await settle()
    const saved = changes.mock.lastCall[0]
    expect(saved).toBe(sanitizeHtml(legacy.replace('<h2>', '<h2>!'), 'article'))
    const reloaded = await mountEditor({ modelValue: saved })
    reloaded.editor.commands.insertContentAt(1, '?')
    await settle()
    expect(reloaded.changes.mock.lastCall[0]).toBe(saved.replace('<h2>', '<h2>?'))
  })

  it('keeps image-only and table-only articles as content without inserting a trailing paragraph', async () => {
    for (const html of ['<img src="/uploads/photo.jpg" alt="">', '<table><tbody><tr><td>Cell</td></tr></tbody></table>']) {
      const { editor, changes } = await mountEditor({ modelValue: html })
      expect(editor.isEmpty).toBe(false)
      expect(editor.state.doc.childCount).toBe(1)
      expect(changes).not.toHaveBeenCalled()
    }
  })

  it('keeps headline gradients and Enter inside a single paragraph, including the no-break mode', async () => {
    const { editor, changes, click } = await mountEditor({ mode: 'headline', modelValue: 'One <span class="grad">two</span><br>Three' })
    editor.commands.setTextSelection(2)
    await click('forms.rt_gradient')
    expect(changes.mock.lastCall[0]).toBe('<span class="grad">One</span> <span class="grad">two</span><br>Three')
    editor.commands.setTextSelection(editor.state.doc.content.size - 1)
    editor.commands.keyboardShortcut('Enter')
    editor.commands.insertContent('Four')
    await settle()
    expect(changes.mock.lastCall[0]).toBe('<span class="grad">One</span> <span class="grad">two</span><br>Three<br>Four')
    expect(editor.state.doc.childCount).toBe(1)
    const noBreak = await mountEditor({ mode: 'headline', allowBreak: false, modelValue: 'Single' })
    noBreak.editor.commands.setTextSelection(noBreak.editor.state.doc.content.size - 1)
    noBreak.editor.commands.keyboardShortcut('Enter')
    await settle()
    expect(noBreak.editor.getText()).toBe('Single')
    expect(noBreak.changes).not.toHaveBeenCalled()
  })

  it('uses the site gradient capability and keeps new StarterKit formatting out of the schema', async () => {
    setHeadlineMarks([])
    for (const mode of ['headline', 'rich', 'article']) {
      const { editor, button } = await mountEditor({ mode, modelValue: '<span class="grad">Text</span>' })
      expect(editor.schema.marks.underline).toBeUndefined()
      expect(editor.schema.marks.gradient).toBeUndefined()
      expect(editor.extensionManager.extensions.filter((extension) => extension.name === 'link')).toHaveLength(mode === 'headline' ? 0 : 1)
      expect(button('forms.rt_gradient')).toBeUndefined()
    }
  })

  it('updates toolbar state and preserves undo/redo after a real formatting change', async () => {
    const { editor, changes, button, click } = await mountEditor({ modelValue: '<p>Text</p>' })
    editor.commands.setTextSelection({ from: 1, to: 5 })
    await click('forms.rt_bold')
    expect(changes.mock.lastCall[0]).toBe('<p><strong>Text</strong></p>')
    expect(button('forms.rt_bold').getAttribute('aria-pressed')).toBe('true')
    expect(button('forms.rt_undo').disabled).toBe(false)
    await click('forms.rt_undo')
    expect(changes.mock.lastCall[0]).toBe('<p>Text</p>')
    expect(button('forms.rt_redo').disabled).toBe(false)
    await click('forms.rt_redo')
    expect(changes.mock.lastCall[0]).toBe('<p><strong>Text</strong></p>')
  })

  it('refreshes toolbar state on selection changes without emitting content changes', async () => {
    const { editor, changes, button } = await mountEditor({ modelValue: '<p><strong>Bold</strong> plain</p>' })
    editor.commands.setTextSelection(2)
    await expect.poll(() => button('forms.rt_bold').getAttribute('aria-pressed')).toBe('true')
    editor.commands.setTextSelection(8)
    await expect.poll(() => button('forms.rt_bold').getAttribute('aria-pressed')).toBe('false')
    expect(changes).not.toHaveBeenCalled()
  })

  it('keeps rich mode formatting and applies links through the toolbar', async () => {
    const { editor, box, changes, click } = await mountEditor({ mode: 'rich', modelValue: '<p>Text</p><ul><li><p>Bullet</p></li></ul><ol><li><p>Number</p></li></ol>' })
    editor.commands.setTextSelection({ from: 1, to: 5 })
    await click('forms.rt_link')
    const input = box.querySelector('.rt-link input')
    input.value = 'example.com'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await click('forms.rt_apply')
    expect(changes.mock.lastCall[0]).toBe('<p><a href="https://example.com" target="_blank" rel="noopener noreferrer">Text</a></p><ul><li><p>Bullet</p></li></ul><ol><li><p>Number</p></li></ol>')
    expect(editor.schema.nodes.heading).toBeUndefined()
    expect(editor.schema.nodes.table).toBeUndefined()
  })

  it('inserts and edits tables through the toolbar with the migrated table nodes', async () => {
    const { box, editor, changes, button, click } = await mountEditor({ modelValue: '<p>Text</p>' })
    editor.commands.setTextSelection(editor.state.doc.content.size - 1)
    await click('forms.rt_table_label')
    expect(box.querySelectorAll('.tiptap tr')).toHaveLength(3)
    expect(box.querySelectorAll('.tiptap th')).toHaveLength(3)
    expect(button('forms.rt_table_label').disabled).toBe(true)
    await click('forms.rt_row_add_label')
    expect(box.querySelectorAll('.tiptap tr')).toHaveLength(4)
    await click('forms.rt_col_add_label')
    expect(box.querySelector('.tiptap tr').children).toHaveLength(4)
    expect(changes.mock.lastCall[0]).toContain('<table>')
    await click('forms.rt_table_del')
    expect(box.querySelector('.tiptap table')).toBeNull()
    expect(changes.mock.lastCall[0]).not.toContain('<table>')
  })
})
