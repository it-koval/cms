<script setup>
// The page editor (full screen). The canvas is the REAL page: an iframe of /admin/preview/{id} that the server renders from the draft with the site's own templates
// (PreviewFrame.vue; the frame talks to this file through postMessage). ONE left sidebar holds «Структура», the block library and the selected section's form.
// State and document logic: editorState.js. This file wires state to the DOM: the frame, focus, keyboard, the sidebar modes.
// LANGUAGE: the editor edits one language version (`locale`). A page without a version in that language shows the top bar (name + language select)
// and MissingVersion (copy / blank) instead of the canvas; everything else is the same editor.
// RIGHTS: a role that may not see the page's template gets «Нет доступа»; one that may not edit gets the same editor read-only (a calm line above the page, no editing controls, inert forms);
// a version that waits for publication says so above the page (and who sent it); a draft that someone changed meanwhile stops the autosave and asks what to do with the edits on screen (EditConflict).
import { computed, nextTick, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '@/components/ui/Icon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import { toast } from '@/components/ui/useToast.js'
import EditorTopBar from './EditorTopBar.vue'
import MissingVersion from './MissingVersion.vue'
import PreviewFrame from './PreviewFrame.vue'
import EditorSidebar from './EditorSidebar.vue'
import FullPreview from './FullPreview.vue'
import NoAccess from '@/components/ui/NoAccess.vue'
import ReadOnlyNote from '@/components/ui/ReadOnlyNote.vue'
import EditConflict from '@/components/ui/EditConflict.vue'
import { fmtUpdated } from '@/utils/format.js'
import { t } from '@/i18n/index.js'
import { app } from '@/stores/app.js'
import PageSettingsModal from './PageSettingsModal.vue'
import { EDITOR_KEY, EDITOR_UI_KEY, TPL_ID, rememberSb, useEditorState } from './editorState.js'
import { smooth } from './scroll.js'
import { isMulti, langName } from '@/utils/locales.js'
import { vp } from '@/utils/viewport.js'
import { previewDestination } from './previewTarget.js'

// `locale`: the language version this editor instance edits (fixed for its life; PageEditorView re-creates it when the content language changes)
const props = defineProps({ pageId: { type: String, required: true }, locale: { type: String, default: '' } })
const router = useRouter()
const ed = useEditorState(props.pageId, props.locale || undefined)
provide(EDITOR_KEY, ed)

const stage = ref(null)
const sidebar = ref(null)
const preview = ref(null)
const previewOpen = ref(false)
const settingsOpen = ref(false)
const settingsModal = ref(null)
/** The marker the server puts on the template's own fields (data-sec="@page"). */
const PAGE_SEC = '@page'

/* ---------- finding things on the page (the frame) ---------- */
const secKey = (id) => (id === TPL_ID ? PAGE_SEC : id)
const reveal = (id, block = 'nearest') => preview.value && preview.value.reveal(secKey(id), block)
/** A section that should be shown once the frame has reloaded (after a move, a copy, an undo): the reload keeps the old scroll position. */
let revealAfter = ''
function revealSoon(id) { revealAfter = id; reveal(id) }
function onFrameReady() {
  if (!revealAfter) return
  const id = revealAfter
  revealAfter = ''
  reveal(id)
}
/** A click on a place of the page or a change in the list: wash that place magenta for a moment. */
const flashTarget = (id, key) => preview.value && preview.value.flash(secKey(id), key)
/** The section whose form is open (or the template's fields) is marked on the page. */
const selected = computed(() => (ed.sb === 'tpl' ? PAGE_SEC : ed.sb === 'lib' ? null : ed.sel))

/* ---------- the sidebar: collapsed | outline | lib | form ---------- */
/** Put the sidebar into a mode and focus something sensible.
 *  o.field: path of the clicked text/photo (the form focuses it); o.focus: 'head' | 'row' | false (default: first text field of the form);
 *  o.scroll: where to bring the section on the canvas; o.changed === false: the form already shows this section;
 *  o.act: button in the sidebar to focus afterwards; o.select: select the first field's text (a new section). */
async function goSb(mode, o = {}) {
  const was = ed.sb
  if (mode && vp.phone) ed.pane = 'form' // a phone: opening anything of the sidebar brings it to the front
  if (!was && mode) rememberSb(true) // opened again from collapsed: the saved state follows what is on screen
  ed.sb = mode
  await nextTick()
  if (mode === 'form') {
    requestAnimationFrame(() => reveal(ed.sel, o.scroll || 'nearest'))
    if (!was) setTimeout(() => { if (ed.sb === 'form') reveal(ed.sel, 'nearest') }, 260) // the page has reflowed beside the sidebar: bring the section back into view
    if (o.field) sidebar.value.focusField(o.field)
    else if (o.act) sidebar.value.focusAct(o.act, o.alt)
    else if (o.focus === 'head') sidebar.value.focusHead()
    else if (o.focus !== false) {
      await nextTick()
      if (vp.phone || vp.touch || !sidebar.value.focusFirst(o.select)) sidebar.value.focusHead() // a finger: a focused text field brings the keyboard up at once
    }
  } else if (mode === 'tpl') {
    // the template's own fields: the fixed part on top of the page
    requestAnimationFrame(() => reveal(TPL_ID, o.scroll || 'nearest'))
    if (!was) setTimeout(() => { if (ed.sb === 'tpl') reveal(TPL_ID, 'nearest') }, 260)
    if (o.field) sidebar.value.focusField(o.field)
    else if (o.focus === 'head') sidebar.value.focusHead()
    else if (o.focus !== false) {
      await nextTick()
      if (vp.phone || vp.touch || !sidebar.value.focusFirst(false)) sidebar.value.focusHead()
    }
  } else if (o.focus !== false) {
    if (mode === 'outline' && o.focus === 'row') sidebar.value.focusRow(was === 'tpl' ? TPL_ID : ed.sel)
    else sidebar.value.focusHead()
  }
}
function collapseSb(user) {
  if (!ed.sb) return
  ed.sbLast = ed.sb
  ed.sbFull = false
  ed.sb = null
  if (vp.phone) { ed.pane = 'preview'; return } // a phone: «свернуть» = look at the page
  if (user) rememberSb(false)
  const b = document.getElementById('sbBtn')
  if (b) b.focus({ preventScroll: true }) // the toggle in the top bar gets focus back
}
function toggleSb() {
  if (ed.sb === 'outline') collapseSb(true)
  else { rememberSb(true); goSb('outline', { focus: ed.sb ? 'row' : 'head' }) } // from a form or the library it shows Структура first
}

/** Back to what the sidebar showed before it was collapsed. */
function reopenSb() {
  const m = ed.sbLast
  goSb(m === 'form' && !ed.sel ? 'outline' : m === 'tpl' && !ed.template ? 'outline' : m, { focus: 'head' })
}
const toggleFull = () => { ed.sbFull = !ed.sbFull }
/** A phone: «Форма | Предпросмотр». The sidebar keeps what it showed; the page stays mounted. */
function setPane(p) {
  if (p === 'preview') { ed.pane = 'preview'; return }
  if (ed.sb) { ed.pane = 'form'; return }
  reopenSb()
}

/* ---------- selecting and opening ---------- */
function selectSec(id, o = {}) {
  if (!ed.secById(id)) return
  const changed = id !== ed.sel
  ed.sel = id
  if (o.form) return goSb('form', { ...o, changed })
  if (o.scroll) reveal(id, o.scroll)
}
const openForm = (id, o = {}) => selectSec(id, { form: true, ...o })
/** Open the template's own fields in the sidebar (o.field: the clicked text or photo). */
function openTpl(o = {}) {
  if (!ed.hasFields) return
  return goSb('tpl', o)
}
/** «Заполняется автоматически» in «Структура»: show the list on the page. */
function showListing() {
  if (preview.value) preview.value.showEnd()
}
/** «Предыдущая / Следующая секция» in the form header. */
function stepSection(dir) {
  const k = ed.indexOf(ed.sel) + dir
  const s = ed.sections[k]
  if (s) selectSec(s.id, { form: true, scroll: 'start', act: dir < 0 ? 'sb-prev' : 'sb-next', alt: dir < 0 ? 'sb-next' : 'sb-prev' })
}
/** A row of the publish list: show that place on the page. */
function gotoChange(c) {
  if (c.kind === 'meta') { settingsOpen.value = true; return }
  if (c.kind === 'field') { gotoTpl(c.key); return }
  if (!c.sec || !ed.secById(c.sec)) return
  selectSec(c.sec, { form: true, focus: 'head', field: c.key || '', scroll: 'center' })
  setTimeout(() => flashTarget(c.sec, c.key), smooth() === 'auto' ? 0 : 420)
}
/** A change or an error in the template's own fields: open them on that field and wash the place on the page. */
function gotoTpl(key) {
  openTpl({ focus: 'head', field: key || '', scroll: 'center' })
  setTimeout(() => flashTarget(TPL_ID, key), smooth() === 'auto' ? 0 : 420)
}
function gotoError(e) {
  if (e.scope === 'fields') { gotoTpl(e.path); return }
  selectSec(e.section.id, { form: true, focus: 'head', field: e.path || '', scroll: 'center' })
  setTimeout(() => flashTarget(e.section.id, e.path), smooth() === 'auto' ? 0 : 420)
}

/* ---------- section operations (state change in editorState.js, focus here) ---------- */
function moveSec(id, dir, fromSb) {
  if (!ed.moveSec(id, dir)) return
  const act = dir < 0 ? 'mv-up' : 'mv-down'
  const alt = dir < 0 ? 'mv-down' : 'mv-up'
  nextTick(() => {
    if (fromSb && sidebar.value) sidebar.value.focusRowAct(id, act, alt) // reordering in «Структура» keeps focus in the list
    revealSoon(id)
  })
}
function dupSec(id) {
  const c = ed.dupSec(id)
  if (!c) return
  nextTick(() => revealSoon(c.id)) // the open form moves to the copy
}
function delSec(id) {
  const r = ed.delSec(id)
  if (!r) return
  nextTick(() => {
    if (r.wasForm) sidebar.value.focusRow(ed.sel) // the Структура row of the section that took its place
  })
}
function toggleHide(id, fromSb) {
  ed.toggleHide(id)
  nextTick(() => { if (fromSb && sidebar.value) sidebar.value.focusHead() })
}
watch(() => ed.restored, (id) => { // «вернуть» after a delete
  if (!id) return
  ed.restored = ''
  nextTick(() => revealSoon(id))
})

/* ---------- what the page tells us (postMessage from the frame) ---------- */
function onFrameFocus({ sec, path, rows }) {
  if (sec === '@settings' || String(sec).startsWith('@collection/')) {
    const destination = previewDestination({ sec, path, rows }, props.locale || app.locale)
    if (destination) router.push(destination)
    return
  }
  if (sec === PAGE_SEC) openTpl({ focus: 'head', field: path })
  else if (ed.secById(sec)) openForm(sec, { focus: 'head', field: path })
}
function onFrameAction({ sec, act }) {
  if (sec === PAGE_SEC) { if (act === 'edit') openTpl(); return }
  if (!ed.secById(sec)) return
  if (act === 'edit') openForm(sec)
  else if (act === 'up') moveSec(sec, -1, false)
  else if (act === 'down') moveSec(sec, 1, false)
  else if (act === 'dup') dupSec(sec)
  else if (act === 'hide') toggleHide(sec, false)
  else if (act === 'del') delSec(sec)
}
function addAt(i) {
  if (!ed.canAdd) return // the template has no sections
  ed.insertAt = i
  goSb('lib')
}
function pickType(type) {
  ed.addSection(type, ed.insertAt)
  nextTick(() => goSb('form', { scroll: 'center', select: true })) // the new section's form opens in the sidebar
}
function step(dir) {
  if (ed.readOnly || !ed.stepHist(dir)) return
  nextTick(() => {
    // the button that ran out of steps hands focus to its twin
    if (document.activeElement && document.activeElement !== document.body) return
    const b = [document.getElementById('undoBtn'), document.getElementById('redoBtn')].find((x) => x && !x.disabled)
    if (b) b.focus({ preventScroll: true })
    else if (ed.sb === 'form') sidebar.value.focusHead()
  })
}
function setDevice(d) { ed.device = d }
// the two answers of the banner «Страницу изменили…» are not given twice or crosswise while one of them is still on its way
const choosing = ref(false)
async function choose(fn) {
  if (choosing.value) return
  choosing.value = true
  try { await fn() } finally { choosing.value = false }
}
async function openPreview() {
  try {
    await ed.flushAll() // the server renders the saved draft
  } catch (e) {
    toast.error((e && e.message) || t('editor.err_save_page'))
    return
  }
  previewOpen.value = true
}
const openSettings = () => { settingsOpen.value = true }

provide(EDITOR_UI_KEY, {
  selectSec, openForm, openTpl, showListing, goSb, collapseSb, reopenSb, toggleFull, setPane, toggleSb, stepSection, gotoChange, gotoError,
  moveSec, dupSec, delSec, toggleHide, addAt, pickType, step, setDevice, openPreview, openSettings,
})

watch(() => ed.loading, async (v) => {
  if (v || ed.error) return
  const title = ed.page ? ed.page.title : ed.pageTitle
  document.title = isMulti() ? t('editor.doc_title_lang', { title, lang: langName(ed.locale) }) : t('editor.doc_title', { title })
})

/* ---------- keyboard ---------- */
const TEXT_ENTRY = 'textarea,input:not([type=checkbox]):not([type=radio]):not([type=button]):not([type=submit]):not([type=range]),[contenteditable="true"]'
function onKey(e) {
  if (ed.loading || ed.busy || ed.error || !ed.page || previewOpen.value || settingsOpen.value) return
  const k = (e.key || '').toLowerCase()
  if ((e.metaKey || e.ctrlKey) && !e.altKey && ['z', 'я', 'y', 'н'].includes(k)) { // i18n-ignore: the keys of undo / redo on the Russian layout too
    if (ed.readOnly) return
    if (e.target && e.target.closest && e.target.closest(TEXT_ENTRY)) return // text entry keeps its own undo
    e.preventDefault()
    step(k === 'z' || k === 'я' ? (e.shiftKey ? 1 : -1) : 1) // i18n-ignore
    return
  }
  if (e.key === 'Escape' && !e.defaultPrevented && ed.sb) {
    e.preventDefault()
    if (ed.sbFull) toggleFull() // Esc first brings the sidebar back to its usual size
    else if (ed.sb === 'outline') collapseSb(true)
    else goSb('outline', { focus: 'row' }) // form and library step back to «Структура»; there Esc collapses
  }
}

onMounted(() => {
  document.body.classList.add('ed-mode')
  document.addEventListener('keydown', onKey)
  window.addEventListener('beforeunload', ed.saveNow)
  ed.load()
})
onBeforeUnmount(() => {
  document.body.classList.remove('ed-mode')
  document.removeEventListener('keydown', onKey)
  window.removeEventListener('beforeunload', ed.saveNow)
  toast.dismissOwner('editor') // «название и адрес» of the «версия создана» toast opens THIS editor's settings
  ed.dispose()
})
defineExpose({
  flushAll: () => ed.flushAll(),
  canLeave: () => !ed.busy && (settingsModal.value?.mayLeave() ?? true),
  discardPending: () => ed.discardPending(),
})

const hint = computed(() => (vp.phone ? t('editor.hint_phone') : t('editor.hint')))
// the hint gives way to the notes above it (read-only, waits for publication, sent back)
const showHint = computed(() => !(ed.readOnly || ed.awaiting || (ed.returned && !ed.canPublish)))
</script>

<template>
  <div class="ed-root">
    <!-- loading -->
    <div v-if="ed.loading" id="editor" class="editor" data-sb="off">
      <header class="ed-top"><router-link class="tb-back" to="/pages"><Icon name="chevL" size="s16" /><span>{{ t('editor.back_pages') }}</span></router-link></header>
      <div class="ed-main"><div class="stage"><div class="cv-wrap ed-sk"><Skeleton :rows="8" /></div></div></div>
    </div>

    <!-- page not found -->
    <div v-else-if="ed.error" id="editor" class="editor" data-sb="off">
      <header class="ed-top"><router-link class="tb-back" to="/pages"><Icon name="chevL" size="s16" /><span>{{ t('editor.back_pages') }}</span></router-link></header>
      <div class="ed-main">
        <div class="stage"><div class="stage-empty sheet">
          <EmptyState icon="pages" :title="t('editor.err_open_page')" :text="ed.error"><router-link class="btn primary" to="/pages">{{ t('editor.to_pages_list') }}</router-link></EmptyState>
        </div></div>
      </div>
    </div>

    <!-- the role may not see this page (its template is not in the role's scope) -->
    <NoAccess v-else-if="ed.denied" :text="app.user ? t('editor.no_access_role', { role: app.user.roleName }) : t('editor.no_access')" to="/" :back="t('editor.to_home')" />

    <!-- no version in this language: the top bar (page name, language select) and the «создать версию» state -->
    <div v-else-if="!ed.page" id="editor" class="editor" data-sb="off">
      <EditorTopBar />
      <div class="ed-main"><div class="stage">
        <MissingVersion v-if="!ed.readOnly" />
        <div v-else class="stage-empty sheet"><EmptyState icon="globe" :title="t('editor.empty_version_title')" :text="t('editor.empty_version_text')" /></div>
      </div></div>
    </div>

    <div
      v-else id="editor" class="editor" :inert="ed.busy || undefined" :aria-busy="ed.busy" :data-dev="ed.device" :data-sb="ed.sb || 'off'" :data-pane="vp.phone ? ed.pane : undefined" :data-full="ed.sb && ed.sbFull ? '' : undefined"
    >
      <EditorTopBar />
      <!-- above the sidebar and the page, not on the page: the form on the phone and the sidebar on the whole screen hide the page, and the person who is typing there has to see this -->
      <div v-if="ed.conflict" class="ed-conflict"><EditConflict what="page" :current="ed.conflict" @show="choose(() => ed.showNewVersion())" @keep="choose(() => ed.keepMine())" :busy="choosing" /></div>
      <div class="ed-main">
        <EditorSidebar ref="sidebar" />
        <button v-if="!ed.sb && !vp.phone" type="button" class="btn sb-open" aria-controls="sb" :title="t('editor.show_sidebar_title')" @click="reopenSb"><Icon name="panelL" size="s16" />{{ t('editor.show_sidebar') }}</button>
        <div v-if="vp.compact && !vp.phone && ed.sb" class="sb-scrim" @click="collapseSb(true)" />
        <div class="stage-col">
          <div id="stage" ref="stage" class="stage has-frame">
            <div v-if="ed.readOnly" class="ed-note"><ReadOnlyNote :text="t('editor.readonly_note')" /></div>
            <div v-else-if="ed.awaiting" class="ed-note">
              <div class="alert warn-a" role="status">
                <Icon name="clock" size="s16" />
                <span v-if="ed.canPublish"><b>{{ t('editor.awaiting_lead') }}</b> {{ t('editor.awaiting_publisher', { by: ed.awaiting.by, at: fmtUpdated(ed.awaiting.at) }) }}</span>
                <span v-else><b>{{ t('editor.awaiting_lead') }}</b> {{ app.user && ed.awaiting.by === app.user.name ? t('editor.awaiting_mine', { at: fmtUpdated(ed.awaiting.at) }) : t('editor.awaiting_other', { by: ed.awaiting.by, at: fmtUpdated(ed.awaiting.at) }) }}</span>
              </div>
            </div>
            <div v-else-if="ed.returned && !ed.canPublish" class="ed-note">
              <div class="alert warn-a" role="status"><Icon name="undo" size="s16" /><span><b>{{ t('editor.returned_lead') }}</b> {{ t('editor.returned_rest', { by: ed.returned.by, at: fmtUpdated(ed.returned.at) }) }}</span></div>
            </div>
            <div class="ed-hint-row">
              <p v-if="showHint" class="ed-hint">{{ hint }}</p>
              <button v-if="ed.canAdd" id="endAdd" type="button" class="btn add-end" @click="addAt(ed.sections.length)"><Icon name="plus" size="s16" />{{ t('editor.add_section') }}</button>
            </div>
            <PreviewFrame
              v-if="ed.sections.length || ed.hasFields || ed.listing" ref="preview" :page-id="pageId" :locale="ed.locale" :reload-key="ed.rev"
              :selected="selected" :phone="ed.device === 'phone'" @focus="onFrameFocus" @action="onFrameAction" @ready="onFrameReady"
            />
            <div v-else class="pv-wrap ed-empty">
              <EmptyState icon="pages" :title="t('editor.empty_sections_title')" :text="t('editor.empty_sections_text')" />
            </div>
          </div>
        </div>
      </div>

      <FullPreview v-if="previewOpen" :page="ed.page" @close="previewOpen = false" />
      <PageSettingsModal ref="settingsModal" v-model:open="settingsOpen" />
    </div>
  </div>
</template>

<style scoped>
.ed-note { width: min(100%, 1280px); margin: 0 auto 14px; }
.ed-note :deep(.ro-note), .ed-note .alert { margin-bottom: 0; }
.ed-sk { padding-top: 20px; }
.ed-empty { padding: 30px 0; background: var(--sheet); border-radius: 4px; }
.add-end { margin-left: auto; flex: none; }
</style>
