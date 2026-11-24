// State and document logic of the page editor (no DOM): the working copy of the page, undo/redo snapshots, autosave,
// section operations, AI proposals, publish.
//
//   const ed = useEditorState(pageId, locale)     // reactive: refs and computeds are unwrapped, so ed.page, ed.sel, ed.changes ... read directly
//   provide(EDITOR_KEY, ed)
//
// LANGUAGE: the editor edits ONE language version of the page (`locale`, default = the current content language app.locale; fixed for the life of the state:
// the editor view re-creates the state when the language changes). Everything below (draft, published copy, publish, revert, proposals, preview context)
// is of that language. A page that has no version in `locale` leaves ed.page = null and ed.translated = false (ed.error stays empty: the editor shows the
// «Версии ещё нет» empty state and createVersion({ from }) makes it); ed.locales (status of every language of the page) and ed.shell ({ id, parentId, template }) are filled.
// RIGHTS (the role of app.user, judged for the page's template): ed.denied = the role may not even look at this page; ed.readOnly = it may look but not change (every editing function below does nothing, the screen draws no editing controls);
// ed.canPublish / ed.canDelete decide the top bar: without «Публиковать» the button becomes «Отправить на публикацию» (ed.submit(): the version is marked ed.awaiting = { by, at } and appears on «Сегодня» of those who may publish;
// ed.returnToAuthor() takes the mark off and leaves ed.returned = { by, at } for the author). publish() and revertAll() clear the mark, too; submit() and publish() clear ed.returned.
// removeVersion() deletes this language version (never the main one) and leaves the editor in the same «версии нет» state.
// Undo history is kept PER LANGUAGE: on a multilingual site the history of a version is parked in a module-level cache when its editor is disposed and picked up
// again when the editor of the same page + language is re-created with the same content (switching RU <-> EN and back keeps both histories).
//
// Saving: every change calls touch() -> «Сохраняю…» -> api.pages.saveDraft after 650 ms of quiet -> «Сохранено».
// VERSION: the server numbers the draft (`version` of every page it hands out); each save carries the version the editor last had (`baseVersion`) and the answer brings the new one,
// so the saves of one editor, queued one after another, never quarrel. A draft changed meanwhile (an assistant, another person) is refused with 409 `conflict`: autosave stops,
// ed.conflict says who changed it, and the person picks showNewVersion() (the edits on screen are dropped, the server's draft is loaded) or keepMine() (the same save over the new version).
// Publishing and the like wait for that choice (flushAll() throws); closing the tab with edits that wait for it makes the browser ask (saveNow).
// A save that FAILED (the server said no, no connection) leaves the edits waiting too: flush() resolves true only when nothing is left unsaved (false: a failure or a conflict), and
// flushAll() throws then (code 'save_failed' for a failure), so nothing is published, submitted, returned, reverted or rendered from an older draft, and the screen that is left asks first.
// History: snapshots of { sections, proposals } (max 50). Typing is grouped (500 ms), structural edits push at once.
// AI proposals are part of the snapshot (undo of an accepted edit brings the proposal back); the decisions are sent to the API
// when the editor is left or the page is published (see commitProposals).
import { computed, reactive, ref } from 'vue'
import { api, isConflict, whileClosing } from '@/api/index.js'
import { app, can } from '@/stores/app.js'
import { getSectionType, hasPageH1, newSectionData, pageH1Id, sectionName } from '@/schema/sections.js'
import { getTemplate, templateFields } from '@/schema/templates.js'
import { pageChanges, sectionChangedKeys } from '@/utils/changes.js'
import { fromPlainText, validate } from '@/utils/fields.js'
import { clone, same, setIn } from '@/utils/objects.js'
import { nid } from '@/utils/ids.js'
import { firstLineText, htmlToText } from '@/utils/html.js'
import { toast } from '@/components/ui/useToast.js'
import { isMulti } from '@/utils/locales.js'
import { cap, version } from '@/utils/langWords.js'
import { t } from '@/i18n/index.js'

export const EDITOR_KEY = Symbol('page-editor-state')
export const EDITOR_UI_KEY = Symbol('page-editor-ui')
/** data-id of the fixed first part of a page that has template fields (on the canvas and in «Структура»). */
export const TPL_ID = '__tpl'

const HMAX = 50
const SB_KEY = 'itk-sb'
const readSb = () => { try { return localStorage.getItem(SB_KEY) } catch (e) { return null } }
/** Remember whether the sidebar was open (per browser). */
export const rememberSb = (on) => { try { localStorage.setItem(SB_KEY, on ? '1' : '0') } catch (e) { /* not remembered */ } }
const startOpen = () => { const v = readSb(); return v === '1' ? true : v === '0' ? false : innerWidth >= 1200 }

const fieldOf = (type, key) => { const t = getSectionType(type); return t ? t.fields.find((f) => f.k === key) || null : null }
const isHtmlField = (f) => !!f && (f.t === 'headline' || (f.t === 'textarea' && f.rich))
/** Proposals carry plain text: a headline / description field gets it as HTML. */
export const propValue = fromPlainText
/** Text of a stored value for the «было» line of a proposal. */
export const propText = (f, v) => (isHtmlField(f) ? htmlToText(v) : String(v ?? ''))

function reid(o) {
  if (Array.isArray(o)) o.forEach(reid)
  else if (o && typeof o === 'object') {
    if (o._id) o._id = nid('p')
    Object.values(o).forEach(reid)
  }
}

/** Parked undo histories: `${site}:${epoch}:${pageId}:${locale}` -> { st, i }  (only multilingual sites; at most HCACHE entries). */
const HCACHE = 14
const histCache = new Map()
const parkHist = (key, h) => {
  histCache.delete(key)
  histCache.set(key, { st: h.st, i: h.i })
  while (histCache.size > HCACHE) histCache.delete(histCache.keys().next().value)
}

export function useEditorState(pageId, locale = app.locale) {
  const cacheKey = `${pageId}:${locale}`

  const loading = ref(true)
  const error = ref('')
  const page = ref(null)
  const published = ref(null)
  const proposals = ref([])
  const rev = ref(0) // bumped whenever what the server renders changed (saved draft, published, reverted): the preview frame reloads
  const pages = ref([])
  const translated = ref(true) // false: the page has no version in this language
  const locales = ref({}) // status summary of every language version of the page (api.pages.getDraft().locales)
  const shell = ref(null) // { id, parentId, template }: what is shared by all languages
  const sel = ref(null)
  const sb = ref(startOpen() ? 'outline' : null) // null (collapsed) | 'outline' | 'lib' | 'form' | 'tpl' (the template's own fields)
  const sbLast = ref('outline') // what the sidebar showed before it was collapsed: it comes back to the same place
  const sbFull = ref(false) // the sidebar covers the whole editor (for long texts); the preview stays mounted behind it
  const pane = ref(sb.value ? 'form' : 'preview') // a PHONE shows one thing at a time: 'form' (the sidebar) | 'preview' (the page); wider windows ignore it
  const insertAt = ref(0)
  const libGrp = ref('')
  const device = ref('desktop')
  const save = ref('saved') // 'saved' | 'busy' | 'error'
  const histI = ref(0)
  const histN = ref(1)
  const histPending = ref(false)
  const restored = ref('') // id of a section that «вернуть» just brought back (the UI scrolls to it)
  const busy = ref(false) // a version is being created / deleted

  /* ---------- derived ---------- */
  const sections = computed(() => (page.value ? page.value.sections : []))
  /** Name of the page in this language; a missing version is named by the list row (the main language's title). */
  const pageTitle = computed(() => (page.value ? page.value.title || t('editor.untitled') : ((pages.value.find((x) => x.id === pageId) || {}).title || t('editor.page_fallback'))))
  /* ---------- rights ---------- */
  const tplKey = computed(() => (shell.value && shell.value.template) || (page.value && page.value.template) || '')
  const denied = computed(() => !!shell.value && !can('pages', 'view', { template: tplKey.value }))
  const readOnly = computed(() => !can('pages', 'edit', { template: tplKey.value }))
  const canPublish = computed(() => can('pages', 'publish', { template: tplKey.value }))
  const canDelete = computed(() => can('pages', 'delete', { template: tplKey.value }))
  /** { by, at } while the version waits for somebody to publish it, else null */
  const awaiting = computed(() => (page.value && page.value.awaiting) || null)
  /** { by, at } after a publisher sent the version back to its author (the author is told); cleared by sending it again or publishing */
  const returned = computed(() => (page.value && page.value.returned) || null)

  /** The section whose headline is the page's one <h1> (first visible «Первый экран»), or null: then the page has no main heading. */
  const template = computed(() => getTemplate(page.value ? page.value.template : (shell.value || {}).template))
  const h1Id = computed(() => pageH1Id(sections.value, template.value))
  /** The page has a main heading: its template owns it (an article's title) or a first-screen section carries it. */
  const hasH1 = computed(() => hasPageH1(sections.value, template.value))
  /** The template's own fields (an article's text, a product's card): a fixed first row in «Структура», a fixed part on the canvas, one form in the sidebar. */
  const tplFields = computed(() => (template.value ? templateFields(template.value.key) : []))
  const hasFields = computed(() => tplFields.value.length > 0)
  /** Sections can be added (a template may forbid them), and the list the template fills by itself (blog: its articles). */
  const canAdd = computed(() => !readOnly.value && (!template.value || template.value.sections !== false))
  const listing = computed(() => (template.value && template.value.listing) || null)
  /** Key of the template field that is the page's heading and name (an article's title), or ''. */
  /** The page name a value of the heading field stands for: a headline (lines, gradient words) gives its first line as plain text. */
  const nameOfHeading = (value) => {
    const f = template.value && template.value.h1 && tplFields.value.find((x) => x.k === template.value.h1.field)
    return f && f.t === 'headline' ? firstLineText(value) : String(value ?? '')
  }
  const h1Key = computed(() => (template.value && template.value.h1 && tplFields.value.some((f) => f.k === template.value.h1.field) ? template.value.h1.field : ''))
  const pubById = computed(() => Object.fromEntries(((published.value && published.value.sections) || []).map((s) => [s.id, s])))
  const liveProps = computed(() => (readOnly.value ? [] : proposals.value.filter((p) => sections.value.some((s) => s.id === p.sectionId))))
  const curSec = computed(() => sections.value.find((s) => s.id === sel.value) || null)
  const info = computed(() => {
    const out = {}
    const pub = published.value
    sections.value.forEach((s) => {
      const ps = pubById.value[s.id]
      const t = getSectionType(s.type)
      const keys = ps ? sectionChangedKeys(s, ps) : []
      out[s.id] = {
        isNew: !!pub && !ps,
        chg: !!pub && (!ps || keys.length > 0 || !!ps.hidden !== !!s.hidden),
        keys,
        err: t ? validate(t.fields, s.data).length > 0 : false,
        np: liveProps.value.filter((p) => p.sectionId === s.id).length,
      }
    })
    return out
  })
  /** What «Опубликовать» would put on the site. A page that was never published lists its sections. */
  const changes = computed(() => {
    if (!page.value) return []
    if (published.value) {
      const all = pageChanges(page.value, published.value)
      // a template with `h1`: the page name IS the heading field, one edit is one row (the field's)
      return h1Key.value && all.some((c) => c.kind === 'field' && c.key === h1Key.value) ? all.filter((c) => !(c.kind === 'meta' && c.key === 'title')) : all
    }
    const out = sections.value.map((s) => ({ kind: 'add', sec: s.id, name: sectionName(s.type), label: t('editor.change_new_section'), val: '', old: '' }))
    if (hasFields.value) out.unshift({ kind: 'field', sec: null, key: '', name: template.value.name, label: t('editor.change_tpl_fields'), val: '', old: '' })
    return out
  })
  /** Required template fields that are empty (they block publishing like an empty section field; `section` is null). */
  const tplErrors = computed(() => (hasFields.value && page.value
    ? validate(tplFields.value, page.value.fields || {}).map((e) => ({ section: null, scope: 'fields', path: e.path, field: e.field, message: e.message }))
    : []))
  /** The marks of the fixed row: unpublished changes in the template fields, an empty required field. */
  const tplInfo = computed(() => {
    const n = published.value ? changes.value.filter((c) => c.kind === 'field').length : 0
    return { n, chg: n > 0, err: tplErrors.value.length > 0 }
  })
  const errors = computed(() => {
    const out = [...tplErrors.value]
    sections.value.forEach((s) => {
      const t = getSectionType(s.type)
      if (t) validate(t.fields, s.data).forEach((e) => out.push({ section: s, path: e.path, field: e.field, message: e.message }))
    })
    return out
  })
  const status = computed(() => (!published.value ? 'draft' : changes.value.length ? 'changed' : 'published'))
  const canUndo = computed(() => histI.value > 0 || histPending.value)
  const canRedo = computed(() => histI.value < histN.value - 1)
  const pubOf = (id) => (pubById.value[id] ? pubById.value[id].data : null)

  /* ---------- history ---------- */
  let hist = { st: [], i: 0 }
  let histT = null
  const snap = () => ({ s: clone(page.value.sections), f: clone(page.value.fields || {}), p: clone(proposals.value) })
  function pushHist() {
    clearTimeout(histT)
    histT = null
    histPending.value = false
    if (!page.value) return
    const sn = snap()
    if (!same(sn, hist.st[hist.i])) {
      hist.st.splice(hist.i + 1)
      hist.st.push(sn)
      if (hist.st.length > HMAX) hist.st.shift()
      hist.i = hist.st.length - 1
    }
    histI.value = hist.i
    histN.value = hist.st.length
  }
  function resetHist() { hist = { st: [snap()], i: 0 }; histI.value = 0; histN.value = 1; histPending.value = false }

  /* ---------- autosave ---------- */
  let saveT = null
  let dirty = false
  let saveChain = Promise.resolve()
  let baseVersion = null // the version of the draft as the server last told it (a load, a save); each save asks to find the draft still at it
  let flying = 0 // queued or in flight, including a last save sent while the tab is closing
  let unchecked = false // the next save is not checked (the API has just written the draft itself, see commitProposals)
  const conflict = ref(null) // { version, by, at, via, viaName } while the draft changed behind our back and the person has not chosen yet; autosave is stopped
  const STOPPED = t('editor.err_decide_first')
  let failure = null // why the last save failed (not a conflict): its edits still wait, the next save tries again
  const unsaved = () => Object.assign(new Error(t('editor.err_unsaved_first', { reason: failure })), { code: 'save_failed', reason: failure })
  const takeVersion = (p) => { if (p?.version) baseVersion = p.version }
  // tell: the person may be typing and may not look at the banner; a dialog that reports the refusal itself says tell = false
  function onConflict(e, tell = true) {
    conflict.value = (e.details && e.details.current) || {}
    dirty = true // what is on screen still waits to be saved
    save.value = 'error'
    if (tell) toast.error(t('editor.err_conflict'))
  }
  function markDirty() {
    dirty = true
    if (conflict.value) return
    save.value = 'busy'
    clearTimeout(saveT)
    saveT = setTimeout(() => { flush() }, 650)
  }
  /** Queues the save of what waits. */
  function queueSave(closing = false) {
    clearTimeout(saveT)
    saveT = null
    if (!dirty || !page.value || conflict.value) return saveChain
    dirty = false
    const payload = clone(page.value)
    const queued = flying > 0
    flying++
    const send = async () => {
      try {
        if (conflict.value) { dirty = true; return }
        // the version is read now, not when the save was queued: the save before it in the chain has brought a newer one
        const put = () => api.pages.saveDraft(pageId, locale, payload, unchecked ? null : baseVersion)
        const r = await (closing ? whileClosing(put) : put())
        unchecked = false
        failure = null
        takeVersion(r)
        if (page.value && r) page.value.updatedAt = r.updatedAt
        if (!dirty) save.value = 'saved'
        rev.value++
      } catch (e) {
        if (isConflict(e)) onConflict(e)
        else {
          dirty = true // the next edit tries again
          save.value = 'error'
          failure = (e && e.message) || t('editor.err_save_page')
          toast.error(failure)
        }
      } finally {
        flying--
      }
    }
    saveChain = queued ? saveChain.then(send) : send()
    return saveChain
  }
  /** Saves what waits, edits typed meanwhile included. Resolves true only when nothing is left unsaved; false after a failed save or while a conflict waits (ed.conflict, ed.save === 'error'). */
  async function flush() {
    do { await queueSave() } while (dirty && page.value && !conflict.value && failure === null)
    return !dirty && failure === null && !conflict.value
  }
  /** «Показать новую версию»: the edits on screen are dropped, the draft of the server is loaded. */
  async function showNewVersion() {
    if (!conflict.value) return false
    try {
      await reloadDraft()
    } catch (e) {
      toast.error((e && e.message) || t('editor.err_refresh_page'))
      return false
    }
    clearTimeout(saveT)
    saveT = null
    dirty = false
    failure = null
    unchecked = false
    // the proposals decided on screen go with the edits: nothing is sent about them, and they are offered again the next time the page is opened
    decisions.clear()
    initialProps = proposals.value.map((p) => p.id)
    conflict.value = null
    return true
  }
  /** «Оставить мои правки»: the same save once more, over the version the server named. */
  function keepMine() {
    const c = conflict.value
    if (!c) return saveChain
    conflict.value = null
    if (c.version) baseVersion = c.version
    dirty = true
    save.value = 'busy'
    return flush().then(() => { if (!conflict.value && save.value === 'saved') toast.show(t('editor.toast_kept_mine')) })
  }
  /** Any change of the working copy. now = push a history step at once (structural edits), otherwise typing is grouped. */
  function touch(now) {
    markDirty()
    clearTimeout(histT)
    histT = null
    if (now) pushHist()
    else { histT = setTimeout(pushHist, 500); histPending.value = true }
  }

  /* ---------- AI proposals ---------- */
  let initialProps = []
  const decisions = new Map()
  function decided() {
    const left = new Set(proposals.value.map((p) => p.id))
    return initialProps.filter((id) => !left.has(id))
  }
  const accepted = (ids) => ids.some((id) => decisions.get(id) === 'accept')
  async function commitProposals() {
    const done = decided()
    if (!done.length) return
    initialProps = initialProps.filter((id) => !done.includes(id))
    done.forEach((id) => {
      const kind = decisions.get(id) === 'accept' ? 'accept' : 'reject'
      api.proposals[kind](id).catch(() => api.proposals.reject(id).catch(() => {})) // accept of a deleted section: drop the proposal
    })
    dirty = true // the saved page below carries the accepted values and wins over whatever the API wrote
    if (accepted(done)) unchecked = true // the API has just changed the draft itself: the save below is not checked (its answer brings the version again)
  }
  /** Leave the editor / publish: send the decisions about AI proposals, then the last autosave. */
  async function flushAll() {
    pushHist()
    if (accepted(decided())) dirty = true // the save after an accepted proposal is not checked, so this one has to go out even when the autosave has already sent everything
    await settled() // our own edits first, and checked: a draft changed behind our back is found out before the decisions below are written on top of it
    await commitProposals()
    await settled()
  }
  /** Throws unless everything is saved: the draft on the server is not ours (conflict), or it is older than the screen (failed save). Nothing may be published or rendered from it. */
  async function settled() {
    if (await flush()) return
    throw conflict.value ? new Error(STOPPED) : unsaved()
  }

  /* ---------- load ---------- */
  let loadSeq = 0
  let disposed = false
  async function load() {
    if (disposed) return
    const my = ++loadSeq
    loading.value = true
    error.value = ''
    try {
      const [d, props, list] = await Promise.all([
        api.pages.getDraft(pageId, locale),
        api.proposals.list({ locale }).catch(() => []),
        api.pages.list({ locale }).catch(() => []),
      ])
      if (my !== loadSeq) return
      translated.value = d.translated
      locales.value = d.locales
      shell.value = d.shell
      pages.value = list
      if (!d.translated) { // no version in this language: the editor shows the «создать версию» state, nothing to edit
        page.value = null
        published.value = null
        proposals.value = []
        initialProps = []
        sel.value = null
        return
      }
      page.value = d.page
      takeVersion(d.page)
      published.value = d.published
      proposals.value = props.filter((p) => p.kind === 'page' && p.pageId === pageId && p.sectionId && !p.stale).map((p) => ({ id: p.id, sectionId: p.sectionId, key: p.key, label: p.label, old: p.old, nw: p.nw, note: p.note }))
      initialProps = proposals.value.map((p) => p.id)
      sel.value = d.page.sections.length ? d.page.sections[0].id : null
      const parked = isMulti() ? histCache.get(cacheKey) : null
      histCache.delete(cacheKey)
      if (parked && same(parked.st[parked.i].s, d.page.sections) && same(parked.st[parked.i].f || {}, d.page.fields || {})) {
        // the same content as when this language was left: its undo steps come back (proposals were decided meanwhile, so the steps carry today's list)
        hist = { st: parked.st.map((x) => ({ s: x.s, f: x.f, p: clone(proposals.value) })), i: parked.i }
        histI.value = hist.i
        histN.value = hist.st.length
        histPending.value = false
      } else resetHist()
    } catch (e) {
      if (my !== loadSeq) return
      error.value = (e && e.message) || t('editor.err_open_page')
    } finally {
      if (my === loadSeq) loading.value = false
    }
  }

  /* ---------- sections ---------- */
  const secById = (id) => sections.value.find((s) => s.id === id) || null
  const indexOf = (id) => sections.value.findIndex((s) => s.id === id)

  /** The template form edited the page's own fields: `fields` is a new object. A template with `h1` keeps the page name equal to its heading field (as the api does). */
  function updateFields(fields) {
    if (readOnly.value) return
    if (!page.value) return
    page.value.fields = fields
    if (h1Key.value) page.value.title = nameOfHeading(fields[h1Key.value])
    touch()
  }

  /** The form edited a section: `data` is a new object. */
  function updateData(id, data) {
    if (readOnly.value) return
    const s = secById(id)
    if (!s) return
    s.data = data
    touch()
  }

  function addSection(type, at) {
    if (readOnly.value) return null
    const s = { id: nid('s'), type, hidden: false, data: newSectionData(type) }
    const i = Math.max(0, Math.min(at, sections.value.length))
    page.value.sections.splice(i, 0, s)
    sel.value = s.id
    touch(true)
    toast.show(t('editor.toast_section_added', { name: sectionName(type) }))
    return s
  }

  function moveSec(id, dir) {
    if (readOnly.value) return false
    const a = page.value.sections
    const i = a.findIndex((s) => s.id === id)
    const j = i + dir
    if (i < 0 || j < 0 || j >= a.length) return false
    ;[a[i], a[j]] = [a[j], a[i]]
    touch(true)
    return true
  }

  /** Drag and drop in «Структура»: move section `from` to the place of section `to`. */
  function reorder(fromId, toId) {
    if (readOnly.value) return false
    const a = page.value.sections
    const from = a.findIndex((s) => s.id === fromId)
    const to = a.findIndex((s) => s.id === toId)
    if (from < 0 || to < 0 || from === to) return false
    const [it] = a.splice(from, 1)
    a.splice(to, 0, it)
    touch(true)
    return true
  }

  function dupSec(id) {
    if (readOnly.value) return null
    const a = page.value.sections
    const i = a.findIndex((s) => s.id === id)
    if (i < 0) return null
    const s = a[i]
    const c = { id: nid('s'), type: s.type, hidden: s.hidden, data: clone(s.data) }
    reid(c.data)
    a.splice(i + 1, 0, c)
    if (sb.value === 'lib' && i < insertAt.value) insertAt.value++ // the chosen place stays between the same two neighbours
    sel.value = c.id
    touch(true)
    toast.show(t('editor.toast_section_duplicated', { name: sectionName(s.type) }))
    return c
  }

  let delToast = null
  let lastDel = 0
  /** Returns { wasForm, nextId } or null when nothing was deleted. */
  function delSec(id) {
    if (readOnly.value) return null
    if (Date.now() - lastDel < 400) return null // the next section's «Удалить» now sits under the pointer: a double click must not delete two
    const a = page.value.sections
    if (a.length === 1 && !hasFields.value) { toast.error(t('editor.err_last_section')); return null } // a page with template fields stands without sections
    const i = a.findIndex((s) => s.id === id)
    if (i < 0) return null
    const s = a.splice(i, 1)[0]
    const wasSel = sel.value === id
    const wasForm = wasSel && sb.value === 'form'
    const nx = a[Math.min(i, a.length - 1)] || null
    if (wasSel) sel.value = nx ? nx.id : null
    if (sb.value === 'lib' && i < insertAt.value) insertAt.value--
    if (wasForm) sb.value = 'outline' // the section whose form was open is gone: back to the structure
    touch(true)
    lastDel = Date.now()
    if (delToast) toast.dismiss(delToast)
    delToast = toast.show(t('editor.toast_section_deleted', { name: sectionName(s.type) }), {
      undoLabel: t('editor.undo_restore'),
      undo: () => {
        const cur = page.value.sections // the live array: history steps replace it
        if (!cur.some((x) => x.id === s.id)) {
          const at = Math.min(i, cur.length)
          cur.splice(at, 0, s)
          if (sb.value === 'lib' && at < insertAt.value) insertAt.value++
        }
        sel.value = s.id
        touch(true)
        restored.value = s.id
      },
    })
    return { wasForm, nextId: nx ? nx.id : null }
  }
  function toggleHide(id) {
    if (readOnly.value) return
    const s = secById(id)
    if (!s) return
    s.hidden = !s.hidden
    touch(true)
    toast.show(s.hidden ? t('editor.toast_hidden') : t('editor.toast_shown'))
  }

  /* ---------- undo / redo ---------- */
  function stepHist(dir) {
    if (readOnly.value) return false
    pushHist()
    const j = hist.i + dir
    if (j < 0 || j >= hist.st.length) return false
    hist.i = j
    page.value.sections = clone(hist.st[j].s)
    if (hist.st[j].f) {
      page.value.fields = clone(hist.st[j].f)
      if (h1Key.value) page.value.title = nameOfHeading(page.value.fields[h1Key.value])
    }
    proposals.value = clone(hist.st[j].p)
    histI.value = hist.i
    if (delToast) { toast.dismiss(delToast); delToast = null } // its section array is gone with the old history state
    if (!secById(sel.value)) {
      sel.value = sections.value.length ? sections.value[0].id : null
      if (sb.value === 'form') sb.value = 'outline' // the section whose form was open no longer exists
    }
    if (insertAt.value > sections.value.length) insertAt.value = sections.value.length
    markDirty()
    return true
  }

  /* ---------- AI proposals: accept / reject ---------- */
  function applyProposal(p) {
    const s = secById(p.sectionId)
    if (!s) return
    s.data = setIn(s.data, p.key, propValue(fieldOf(s.type, p.key), p.nw))
  }
  function acceptProposal(id) {
    if (readOnly.value) return null
    const i = proposals.value.findIndex((p) => p.id === id)
    if (i < 0) return null
    const p = proposals.value[i]
    applyProposal(p)
    proposals.value.splice(i, 1)
    decisions.set(id, 'accept')
    touch(true)
    toast.show(t('editor.toast_accepted'))
    return p
  }
  function rejectProposal(id) {
    if (readOnly.value) return null
    const i = proposals.value.findIndex((p) => p.id === id)
    if (i < 0) return null
    const p = proposals.value[i]
    proposals.value.splice(i, 1)
    decisions.set(id, 'reject')
    touch(true)
    toast.show(t('editor.toast_rejected'), {
      undoLabel: t('editor.undo_restore'),
      undo: () => {
        if (!proposals.value.some((x) => x.id === p.id)) proposals.value.splice(Math.min(i, proposals.value.length), 0, p)
        decisions.delete(id)
        touch(true)
      },
    })
    return p
  }
  function acceptAll(sectionId) {
    if (readOnly.value) return []
    const mine = proposals.value.filter((p) => p.sectionId === sectionId)
    mine.forEach((p) => { applyProposal(p); decisions.set(p.id, 'accept') })
    proposals.value = proposals.value.filter((p) => p.sectionId !== sectionId)
    touch(true)
    toast.show(t('editor.toast_accepted_all', { count: mine.length }))
    return mine
  }

  /* ---------- page level ---------- */
  async function publish() {
    try {
      await flushAll()
      await api.pages.publish(pageId, locale, baseVersion)
      const d = await api.pages.getDraft(pageId, locale)
      published.value = d.published
      // the version is NOT taken: publishing leaves the draft as it is, and the screen shows the draft we have. A draft that is another one by now (an assistant changed it
      // while the editor stood idle) must not pass for the one on screen: the next save would write the old screen over it
      const stale = !flying && !!(d.page.version && baseVersion && d.page.version !== baseVersion) // a save of edits typed meanwhile is on its way: it is checked by the server itself, and its answer brings the version
      page.value.status = d.page.status
      page.value.updatedAt = d.page.updatedAt
      page.value.awaiting = d.page.awaiting || null
      page.value.returned = null
      save.value = failure !== null || conflict.value ? 'error' : dirty ? 'busy' : 'saved' // edits typed while the publish was on its way stay in the working copy and go out with the next autosave
      if (stale && !conflict.value) conflict.value = { version: d.page.version }
      rev.value++ // the marks of unpublished changes leave the page
      toast.show(isMulti() ? t('editor.toast_published_multi', { ver: cap(version(locale).nom) }) : t('editor.toast_published'))
      return { ok: true }
    } catch (e) {
      if (isConflict(e)) {
        onConflict(e)
        return { ok: false }
      }
      const first = e && e.details && e.details[0]
      toast.error((e && e.message) || t('editor.err_publish'))
      return { ok: false, error: first || null }
    }
  }

  /** «Отправить на публикацию» (a role without «Публиковать»): the same checks as publish, then the version waits for somebody who may publish it. */
  async function submit() {
    try {
      await flushAll()
      const r = await api.pages.submit(pageId, locale)
      page.value.awaiting = r.awaiting || null
      page.value.returned = null
      save.value = failure !== null || conflict.value ? 'error' : dirty ? 'busy' : 'saved'
      toast.show(isMulti() ? t('editor.toast_submitted_multi', { ver: cap(version(locale).nom) }) : t('editor.toast_submitted'))
      return { ok: true }
    } catch (e) {
      const first = e && e.details && e.details[0]
      toast.error((e && e.message) || t('editor.err_submit'))
      return { ok: false, error: first || null }
    }
  }

  /** «Вернуть автору»: the version stops waiting; it stays as the author left it. */
  async function returnToAuthor() {
    try {
      await flushAll()
      const r = await api.pages.returnToAuthor(pageId, locale)
      page.value.awaiting = null
      page.value.returned = r.returned || null
      toast.show(t('editor.toast_returned'))
      return true
    } catch (e) {
      toast.error((e && e.message) || t('editor.err_return'))
      return false
    }
  }

  /** Throw away every unpublished change: the working copy becomes the published page again. */
  async function revertAll() {
    try {
      await flushAll()
      await api.pages.revert(pageId, locale)
      const d = await api.pages.getDraft(pageId, locale)
      page.value = d.page
      takeVersion(d.page)
      published.value = d.published
      if (!secById(sel.value)) sel.value = sections.value.length ? sections.value[0].id : null
      if (sb.value === 'form' && !secById(sel.value)) sb.value = 'outline'
      resetHist()
      save.value = 'saved'
      rev.value++
      toast.show(isMulti() ? t('editor.toast_reverted_multi', { ver: version(locale).nom }) : t('editor.toast_reverted'))
      return true
    } catch (e) {
      toast.error((e && e.message) || t('editor.err_revert'))
      return false
    }
  }

  /** The draft was changed on the server (a version returned from «История»): take it as it is now. */
  async function reloadDraft() {
    const my = ++loadSeq
    const d = await api.pages.getDraft(pageId, locale)
    if (my !== loadSeq || disposed) return
    page.value = d.page
    takeVersion(d.page)
    published.value = d.published
    if (!secById(sel.value)) sel.value = sections.value.length ? sections.value[0].id : null
    if (sb.value === 'form' && !secById(sel.value)) sb.value = 'outline'
    resetHist()
    save.value = 'saved'
    rev.value++
  }

  /** Title, address, parent, SEO from «Настройки страницы». Throws ApiError (for example «Страница с таким адресом уже есть»). */
  async function saveMeta(meta) {
    if (readOnly.value) return
    await flush()
    if (conflict.value) throw new Error(STOPPED)
    const next = { ...clone(page.value), title: meta.title.trim(), url: meta.url.trim(), parentId: meta.parentId || null, template: meta.template || page.value.template, seo: { title: meta.seo.title || '', description: meta.seo.description || '' } }
    let r
    try {
      r = await api.pages.saveDraft(pageId, locale, next, baseVersion)
    } catch (e) {
      if (isConflict(e)) onConflict(e, false) // the banner above the page takes over; the dialog shows the same message
      throw e
    }
    takeVersion(r)
    Object.assign(page.value, { title: r.title, url: r.url, parentId: r.parentId, template: r.template, fields: r.fields, seo: r.seo, updatedAt: r.updatedAt })
    if (r.template !== (shell.value || {}).template) { // a new template: its fields (and the sections rule) replaced the old ones, in every language
      page.value.sections = r.sections
      shell.value = { ...shell.value, template: r.template }
      resetHist()
      if (!secById(sel.value)) sel.value = sections.value.length ? sections.value[0].id : null
      // the sidebar part that the new template no longer has gives way to «Структура»
      if ((sb.value === 'tpl' && !hasFields.value) || (sb.value === 'form' && !curSec.value) || (sb.value === 'lib' && !canAdd.value)) sb.value = 'outline'
    }
    const row = pages.value.find((x) => x.id === pageId)
    if (row) Object.assign(row, { title: r.title, url: r.url, parentId: r.parentId, template: r.template })
    save.value = 'saved'
    rev.value++
    toast.show(t('editor.toast_settings_saved'))
  }

  /** «Скопировать с русского» (from = a language code) / «Начать с пустой страницы» (from = null): makes the draft of this language and opens it. */
  async function createVersion(from) {
    if (readOnly.value || busy.value || page.value) return false
    busy.value = true
    try {
      await api.pages.createTranslation(pageId, locale, { from })
      await load()
      return !!page.value
    } catch (e) {
      toast.error((e && e.message) || t('editor.err_create_version'))
      return false
    } finally {
      busy.value = false
    }
  }

  /** «Удалить английскую версию»: the page stays on the site in the other languages. Waits for the last autosave, drops what is pending, then shows the «версии нет» state. */
  async function removeVersion() {
    if (!canDelete.value || busy.value || !page.value) return false
    busy.value = true
    try {
      clearTimeout(saveT)
      saveT = null
      clearTimeout(histT)
      histT = null
      await saveChain
      await api.pages.removeTranslation(pageId, locale)
      dirty = false
      failure = null
      conflict.value = null // the version the person had to choose about is gone
      histCache.delete(cacheKey)
      await load()
      return !page.value
    } catch (e) {
      toast.error((e && e.message) || t('editor.err_remove_version'))
      return false
    } finally {
      busy.value = false
    }
  }

  /** Last chance (tab closing, `e` is the beforeunload event): the request is sent at once, as a keepalive one when it is small enough (whileClosing), so it outlives the page. */
  function saveNow(e) {
    if ((!dirty && !flying) || !page.value) return
    if (flying || conflict.value || failure !== null) {
      e.preventDefault()
      e.returnValue = ''
    }
    // Wait for the checked save to finish, or for an explicit conflict decision.
    // A user who stays can continue with the same queue and version.
    if (!flying && !conflict.value) queueSave(true)
  }
  function dispose() {
    disposed = true
    loadSeq++
    if (page.value && isMulti()) { pushHist(); parkHist(cacheKey, hist) }
    clearTimeout(histT)
    clearTimeout(saveT)
    if (delToast) toast.dismiss(delToast)
    // proposals first, then the page: the saved copy is the one that counts
    commitProposals().then(() => {}, () => {})
    flush()
  }

  return reactive({
    pageId, locale, translated, locales, shell, loading, error, page, published, proposals, rev, pages, sel, sb, sbLast, sbFull, pane, insertAt, libGrp, device, save, conflict, histI, histN, restored, busy,
    denied, readOnly, canPublish, canDelete, awaiting, returned, tplKey,
    sections, pageTitle, template, h1Id, hasH1, tplFields, hasFields, canAdd, listing, h1Key, tplErrors, tplInfo, pubById, liveProps, curSec, info, changes, errors, status, canUndo, canRedo,
    pubOf, indexOf, secById,
    load, touch, flush, flushAll, pushHist, saveNow, dispose,
    updateData, updateFields, addSection, moveSec, reorder, dupSec, delSec, toggleHide,
    stepHist, acceptProposal, rejectProposal, acceptAll,
    publish, submit, returnToAuthor, revertAll, reloadDraft, showNewVersion, keepMine, saveMeta, createVersion, removeVersion,
  })
}
