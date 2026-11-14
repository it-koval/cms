<script setup>
// RichText on TipTap. Value = an HTML string restricted by utils/html.js.
//   <RichText v-model="html" mode="headline" aria-label="Заголовок" />   one block; Enter = line break; the only mark is «Градиент», offered only when the site design has gradient text
//   <RichText v-model="html" mode="rich" />                              the DESCRIPTION editor, for all prose: paragraphs, Shift+Enter line break, bold, italic, link, bullet / numbered list (no gradient, no headings)
//   <RichText v-model="html" mode="article" />                           long text (blog): + headings H2/H3, quote, divider, photo from the media library, simple table (rows / columns); undo/redo
// props: mode, modelValue, placeholder, invalid, allowBreak (headline), ariaLabel / ariaLabelledby / ariaDescribedby, minHeight (rich, px)
// exposes: focus(), focusEnd()
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { EditorContent, useEditor } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Link from '@tiptap/extension-link'
import Image from '@tiptap/extension-image'
import { Table, TableRow, TableHeader, TableCell } from '@tiptap/extension-table'
import { DOMSerializer } from '@tiptap/pm/model'
import { thumbUrl } from '@/utils/media.js'
import { EnterIsBreak, Gradient, SingleBlockDoc } from '@/utils/gradientMark.js'
import { escapeHtml, headlineMarks, sanitizeHtml } from '@/utils/html.js'
import Icon from '@/components/ui/Icon.vue'
import MediaPickerDialog from './MediaPickerDialog.vue'
import '@/styles/richtext.css'
import { t } from '@/i18n/index.js'

const props = defineProps({
  modelValue: { type: String, default: '' },
  mode: { type: String, default: 'rich' }, // 'headline' | 'rich' | 'article'
  placeholder: { type: String, default: '' },
  invalid: { type: Boolean, default: false },
  allowBreak: { type: Boolean, default: true },
  ariaLabel: { type: String, default: '' },
  ariaLabelledby: { type: String, default: '' },
  ariaDescribedby: { type: String, default: '' },
  minHeight: { type: Number, default: 132 },
})
const emit = defineEmits(['update:modelValue', 'focus', 'blur'])
const headline = props.mode === 'headline'
const article = props.mode === 'article'
const gradient = headline && headlineMarks().includes('gradient') // the site design decides (schema/sections.js SITE_DESIGN)

const toDoc = (html) => {
  const clean = sanitizeHtml(html, props.mode)
  return headline ? `<p>${clean}</p>` : clean || '<p></p>'
}
// editor.getHTML() builds <img> elements in the live document, and the browser downloads each original; an inert document does not
function htmlOf(ed) {
  const inert = document.implementation.createHTMLDocument('')
  const box = inert.createElement('div')
  box.appendChild(DOMSerializer.fromSchema(ed.schema).serializeFragment(ed.state.doc.content, { document: inert }))
  return box.innerHTML
}
function toValue(ed) {
  if (ed.isEmpty) return ''
  const html = htmlOf(ed)
  if (headline) {
    const inner = html.replace(/^<p>/, '').replace(/<\/p>$/, '')
    return sanitizeHtml(inner, 'headline')
  }
  return sanitizeHtml(html, article ? 'article' : 'rich')
}

// The editor shows a picture as a WebP variant (a node view); the document, and so the stored HTML, keeps the original address.
const imageView = (node) => {
  const dom = document.createElement('img')
  const show = (n) => {
    dom.src = thumbUrl(n.attrs.src, 960)
    dom.alt = n.attrs.alt || ''
    if (n.attrs.title) dom.title = n.attrs.title
    else dom.removeAttribute('title')
  }
  show(node)
  return { dom, update: (n) => n.type === node.type && (show(n), true) }
}
// Preserve legacy width/height parsing and data-align through the editor and stored HTML.
const keepAttr = (attr, name) => ({ default: null, parseHTML: (el) => el.getAttribute(name), renderHTML: (attrs) => (attrs[attr] ? { [name]: attrs[attr] } : {}) })
const DisplayImage = Image.extend({
  addAttributes() {
    return { ...this.parent?.(), width: keepAttr('width', 'width'), height: keepAttr('height', 'height'), align: keepAttr('align', 'data-align') }
  },
  addNodeView() {
    return ({ node }) => imageView(node)
  },
})

// Keep the supported formatting and document structure when upgrading StarterKit.
// Link is configured separately; the other v3 additions are outside the stored HTML contract.
const starterOptions = { link: false, underline: false, listKeymap: false, trailingNode: false }
const extensions = headline
  ? [
      StarterKit.configure({ ...starterOptions, document: false, bold: false, italic: false, strike: false, code: false, codeBlock: false, blockquote: false, bulletList: false, orderedList: false, listItem: false, heading: false, horizontalRule: false }),
      SingleBlockDoc, ...(gradient ? [Gradient] : []), EnterIsBreak.configure({ allowBreak: props.allowBreak }),
    ]
  : article
    ? [
        StarterKit.configure({ ...starterOptions, strike: false, code: false, codeBlock: false, heading: { levels: [2, 3] } }),
        Link.configure({ openOnClick: false, autolink: false, linkOnPaste: true, HTMLAttributes: { rel: 'noopener noreferrer' } }),
        DisplayImage.configure({ inline: false, allowBase64: true }),
        Table.configure({ resizable: false }), TableRow, TableHeader, TableCell,
      ]
    : [
        StarterKit.configure({ ...starterOptions, strike: false, code: false, codeBlock: false, blockquote: false, heading: false, horizontalRule: false }),
        Link.configure({ openOnClick: false, autolink: false, linkOnPaste: true, HTMLAttributes: { rel: 'noopener noreferrer' } }),
      ]

const editor = useEditor({
  extensions,
  content: toDoc(props.modelValue),
  editorProps: {
    attributes: {
      role: 'textbox',
      'aria-multiline': headline ? 'false' : 'true',
      ...(props.ariaLabel ? { 'aria-label': props.ariaLabel } : {}),
      ...(props.ariaLabelledby ? { 'aria-labelledby': props.ariaLabelledby } : {}),
      ...(props.ariaDescribedby ? { 'aria-describedby': props.ariaDescribedby } : {}),
      spellcheck: 'true',
    },
    // Headline: paste as plain text, line breaks only when the field allows them
    handlePaste: headline ? (view, event) => {
      const text = (event.clipboardData || window.clipboardData).getData('text/plain')
      const html = props.allowBreak ? escapeHtml(text.replace(/\r/g, '').trim()).replace(/\n/g, '<br>') : escapeHtml(text.replace(/\s*\n\s*/g, ' ').trim())
      editor.value.commands.insertContent(html)
      return true
    } : undefined,
  },
  onUpdate: ({ editor: ed }) => {
    const v = toValue(ed)
    if (v !== props.modelValue) emit('update:modelValue', v)
  },
  onFocus: () => emit('focus'),
  onBlur: () => emit('blur'),
})

// outside changes (revert, undo, proposals) -> editor
watch(() => props.modelValue, (v) => {
  const ed = editor.value
  if (!ed) return
  if (toValue(ed) === sanitizeHtml(v, props.mode)) return
  ed.commands.setContent(toDoc(v), { emitUpdate: false })
})
onBeforeUnmount(() => { if (editor.value) editor.value.destroy() })

const empty = computed(() => (editor.value ? editor.value.isEmpty : !props.modelValue))

/* ---- toolbar ---- */
const on = (name) => !!editor.value && editor.value.isActive(name)

/** «Градиент» with the caret inside a word and nothing selected: take the whole word (like the prototype). */
function wordRange(ed) {
  const { $from, empty: caretOnly } = ed.state.selection
  if (!caretOnly) return null
  const parent = $from.parent
  const start = $from.start()
  const text = parent.textBetween(0, parent.content.size, '\n', '\n')
  const off = $from.parentOffset
  const isW = (i) => i >= 0 && i < text.length && !/\s/.test(text[i])
  let i = off
  if (!isW(i)) { if (isW(i - 1)) i -= 1; else return null }
  let a = i
  let b = i + 1
  while (isW(a - 1)) a--
  while (isW(b)) b++
  return { from: start + a, to: start + b }
}
function toggleGradient() {
  const ed = editor.value
  if (!ed) return
  const r = wordRange(ed)
  const chain = ed.chain().focus()
  if (r) chain.setTextSelection(r)
  chain.toggleGradient().run()
}
const breakLine = () => editor.value && editor.value.chain().focus().setHardBreak().run()
const clearFormat = () => editor.value && editor.value.chain().focus().unsetAllMarks().run()

// a table: only at the top level of the text (the sanitiser flattens a table inside a quote or a list), so «Таблица» is off there and inside a table
const inTable = computed(() => !!editor.value && editor.value.isActive('table'))
const canInsertTable = computed(() => !!editor.value && !inTable.value && !['blockquote', 'bulletList', 'orderedList'].some((n) => editor.value.isActive(n)))
const insertTable = () => editor.value.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()
const tableCmd = (name) => editor.value.chain().focus()[name]().run()

const pickPhoto = ref(false)
function insertPhoto({ src, alt }) {
  editor.value.chain().focus().setImage({ src, alt: alt || '' }).run()
}

const link = reactive({ open: false, url: '' })
const linkInput = ref(null)
function openLink() {
  const ed = editor.value
  link.url = ed.getAttributes('link').href || ''
  link.open = !link.open
  if (link.open) setTimeout(() => linkInput.value && linkInput.value.focus(), 0)
}
function normalizeUrl(u) {
  const v = u.trim()
  if (!v) return ''
  return /^(https?:\/\/|mailto:|tel:|\/|#)/i.test(v) ? v : 'https://' + v
}
function applyLink() {
  const ed = editor.value
  const href = normalizeUrl(link.url)
  if (!href) { ed.chain().focus().extendMarkRange('link').unsetLink().run() } else if (ed.state.selection.empty && !ed.isActive('link')) {
    ed.chain().focus().insertContent({ type: 'text', text: href, marks: [{ type: 'link', attrs: { href } }] }).run()
  } else {
    ed.chain().focus().extendMarkRange('link').setLink({ href }).run()
  }
  link.open = false
}
function removeLink() {
  editor.value.chain().focus().extendMarkRange('link').unsetLink().run()
  link.open = false
}

defineExpose({
  focus: () => editor.value && editor.value.commands.focus(),
  focusEnd: () => editor.value && editor.value.commands.focus('end'),
})
</script>

<template>
  <div :class="['rt', headline ? 'headline' : 'rich', article && 'article']" :style="headline ? undefined : { '--rt-min': minHeight + 'px' }">
    <div v-if="article && editor" class="rt-tb" role="toolbar" :aria-label="t('forms.rt_toolbar_article')">
      <button type="button" class="rt-b" @mousedown.prevent :disabled="!editor.can().undo()" :title="t('forms.rt_undo_title')" :aria-label="t('forms.rt_undo')" @click="editor.chain().focus().undo().run()"><Icon name="undo" size="s14" /></button>
      <button type="button" class="rt-b" @mousedown.prevent :disabled="!editor.can().redo()" :title="t('forms.rt_redo_title')" :aria-label="t('forms.rt_redo')" @click="editor.chain().focus().redo().run()"><Icon name="redo" size="s14" /></button>
      <span class="rt-sep" aria-hidden="true" />
      <button type="button" class="rt-b rt-h" @mousedown.prevent :aria-pressed="on('paragraph')" :title="t('forms.rt_paragraph_title')" :aria-label="t('forms.rt_paragraph_title')" @click="editor.chain().focus().setParagraph().run()">{{ t('forms.rt_paragraph') }}</button>
      <button type="button" class="rt-b rt-h" @mousedown.prevent :aria-pressed="editor.isActive('heading', { level: 2 })" :title="t('forms.rt_h2_title')" :aria-label="t('forms.rt_h2_label')" @click="editor.chain().focus().toggleHeading({ level: 2 }).run()">H2</button>
      <button type="button" class="rt-b rt-h" @mousedown.prevent :aria-pressed="editor.isActive('heading', { level: 3 })" :title="t('forms.rt_h3_title')" :aria-label="t('forms.rt_h3_label')" @click="editor.chain().focus().toggleHeading({ level: 3 }).run()">H3</button>
      <span class="rt-sep" aria-hidden="true" />
      <button type="button" class="rt-b" @mousedown.prevent :aria-pressed="on('bold')" :title="t('forms.rt_bold_title')" :aria-label="t('forms.rt_bold')" @click="editor.chain().focus().toggleBold().run()"><Icon name="bold" size="s14" /></button>
      <button type="button" class="rt-b" @mousedown.prevent :aria-pressed="on('italic')" :title="t('forms.rt_italic_title')" :aria-label="t('forms.rt_italic')" @click="editor.chain().focus().toggleItalic().run()"><Icon name="italic" size="s14" /></button>
      <button type="button" class="rt-b" @mousedown.prevent :aria-pressed="on('link') || link.open" :title="t('forms.rt_link')" :aria-label="t('forms.rt_link')" @click="openLink"><Icon name="link" size="s14" /></button>
      <span class="rt-sep" aria-hidden="true" />
      <button type="button" class="rt-b" @mousedown.prevent :aria-pressed="on('bulletList')" :title="t('forms.rt_bullet')" :aria-label="t('forms.rt_bullet')" @click="editor.chain().focus().toggleBulletList().run()"><Icon name="listUl" size="s14" /></button>
      <button type="button" class="rt-b" @mousedown.prevent :aria-pressed="on('orderedList')" :title="t('forms.rt_ordered')" :aria-label="t('forms.rt_ordered')" @click="editor.chain().focus().toggleOrderedList().run()"><Icon name="listOl" size="s14" /></button>
      <button type="button" class="rt-b" @mousedown.prevent :aria-pressed="on('blockquote')" :title="t('forms.rt_quote')" :aria-label="t('forms.rt_quote')" @click="editor.chain().focus().toggleBlockquote().run()">{{ t('forms.rt_quote') }}</button>
      <span class="rt-sep" aria-hidden="true" />
      <button type="button" class="rt-b" @mousedown.prevent :title="t('forms.rt_photo_title')" :aria-label="t('forms.rt_photo_label')" @click="pickPhoto = true"><Icon name="image" size="s14" />{{ t('forms.photo') }}</button>
      <button type="button" class="rt-b" @mousedown.prevent :title="t('forms.rt_rule_label')" :aria-label="t('forms.rt_rule_label')" @click="editor.chain().focus().setHorizontalRule().run()">{{ t('forms.rt_rule') }}</button>
      <button type="button" class="rt-b" @mousedown.prevent :disabled="!canInsertTable" :title="t('forms.rt_table_title')" :aria-label="t('forms.rt_table_label')" @click="insertTable">{{ t('forms.rt_table') }}</button>
      <button type="button" class="rt-b" @mousedown.prevent :title="t('forms.rt_clear')" :aria-label="t('forms.rt_clear')" @click="editor.chain().focus().unsetAllMarks().clearNodes().run()"><Icon name="eraser" size="s14" /></button>
    </div>
    <div v-else-if="!headline && editor" class="rt-tb" role="toolbar" :aria-label="t('forms.rt_toolbar_text')">
      <button type="button" class="rt-b" @mousedown.prevent :aria-pressed="on('bold')" :title="t('forms.rt_bold_title')" :aria-label="t('forms.rt_bold')" @click="editor.chain().focus().toggleBold().run()"><Icon name="bold" size="s14" /></button>
      <button type="button" class="rt-b" @mousedown.prevent :aria-pressed="on('italic')" :title="t('forms.rt_italic_title')" :aria-label="t('forms.rt_italic')" @click="editor.chain().focus().toggleItalic().run()"><Icon name="italic" size="s14" /></button>
      <button type="button" class="rt-b" @mousedown.prevent :aria-pressed="on('link') || link.open" :title="t('forms.rt_link')" :aria-label="t('forms.rt_link')" @click="openLink"><Icon name="link" size="s14" /></button>
      <span class="rt-sep" aria-hidden="true" />
      <button type="button" class="rt-b" @mousedown.prevent :aria-pressed="on('bulletList')" :title="t('forms.rt_bullet')" :aria-label="t('forms.rt_bullet')" @click="editor.chain().focus().toggleBulletList().run()"><Icon name="listUl" size="s14" /></button>
      <button type="button" class="rt-b" @mousedown.prevent :aria-pressed="on('orderedList')" :title="t('forms.rt_ordered')" :aria-label="t('forms.rt_ordered')" @click="editor.chain().focus().toggleOrderedList().run()"><Icon name="listOl" size="s14" /></button>
      <span class="rt-sep" aria-hidden="true" />
      <button type="button" class="rt-b" @mousedown.prevent :title="t('forms.rt_clear')" :aria-label="t('forms.rt_clear')" @click="clearFormat"><Icon name="eraser" size="s14" /></button>
    </div>
    <div v-if="article && editor && inTable" class="rt-tb" role="toolbar" :aria-label="t('forms.rt_table')">
      <span class="rt-tl">{{ t('forms.rt_table_colon') }}</span>
      <button type="button" class="rt-b" @mousedown.prevent :title="t('forms.rt_row_add_title')" :aria-label="t('forms.rt_row_add_label')" @click="tableCmd('addRowAfter')">{{ t('forms.rt_row_add') }}</button>
      <button type="button" class="rt-b" @mousedown.prevent :disabled="!editor.can().deleteRow()" :title="t('forms.rt_row_del_title')" :aria-label="t('forms.rt_row_del_label')" @click="tableCmd('deleteRow')">{{ t('forms.rt_row_del') }}</button>
      <span class="rt-sep" aria-hidden="true" />
      <button type="button" class="rt-b" @mousedown.prevent :title="t('forms.rt_col_add_title')" :aria-label="t('forms.rt_col_add_label')" @click="tableCmd('addColumnAfter')">{{ t('forms.rt_col_add') }}</button>
      <button type="button" class="rt-b" @mousedown.prevent :disabled="!editor.can().deleteColumn()" :title="t('forms.rt_col_del_title')" :aria-label="t('forms.rt_col_del_label')" @click="tableCmd('deleteColumn')">{{ t('forms.rt_col_del') }}</button>
      <span class="rt-sep" aria-hidden="true" />
      <button type="button" class="rt-b" @mousedown.prevent :title="t('forms.rt_header_title')" :aria-label="t('forms.rt_header_label')" @click="tableCmd('toggleHeaderRow')">{{ t('forms.rt_header') }}</button>
      <button type="button" class="rt-b" @mousedown.prevent :title="t('forms.rt_table_del_title')" :aria-label="t('forms.rt_table_del')" @click="tableCmd('deleteTable')">{{ t('forms.rt_table_del') }}</button>
    </div>
    <div v-if="!headline && link.open" class="rt-link">
      <input ref="linkInput" v-model="link.url" type="text" placeholder="https://" :aria-label="t('forms.rt_link_url')" @keydown.enter.prevent="applyLink" @keydown.esc.stop.prevent="link.open = false">
      <button type="button" class="btn sm primary" @click="applyLink">{{ t('forms.rt_apply') }}</button>
      <button type="button" class="btn sm" :disabled="!on('link')" @click="removeLink"><Icon name="unlink" size="s14" />{{ t('forms.rt_unlink') }}</button>
    </div>

    <div class="rt-ed" :aria-invalid="invalid ? 'true' : undefined">
      <EditorContent :editor="editor" />
      <span v-if="empty && placeholder" class="rt-ph" aria-hidden="true">{{ placeholder }}</span>
    </div>

    <MediaPickerDialog v-if="article" v-model:open="pickPhoto" @pick="insertPhoto" />

    <div v-if="headline && editor && (gradient || allowBreak)" class="rt-tb" role="group" :aria-label="t('forms.rt_toolbar_headline')">
      <button v-if="gradient" type="button" class="rt-b" @mousedown.prevent :aria-pressed="on('gradient')" :title="t('forms.rt_gradient_title')" :aria-label="t('forms.rt_gradient')" @click="toggleGradient"><span class="rt-sw" aria-hidden="true" />{{ t('forms.rt_gradient') }}</button>
      <button v-if="allowBreak" type="button" class="rt-b" :title="t('forms.rt_break_title')" :aria-label="t('forms.rt_break')" @click="breakLine"><Icon name="enter" size="s14" />{{ t('forms.rt_break') }}</button>
    </div>
  </div>
</template>
