<script setup>
// Route /collections/:col/:id — one record of a collection (product, athlete, article, review, partner) as a form.
// Left: tabs «Содержимое» / «Для поисковиков» (SchemaForm; articles use the RichText body). Right: how the record looks on the site, live.
// Edits autosave to the draft (api.collections.update); «Опубликовать» lists the changes first.
// Every save names the version of the record it found (baseVersion); a record changed meanwhile (an assistant, another person) stops the autosave and EditConflict asks: show the new version or keep my edits.
// The view is NOT re-mounted when only :id changes: it watches route.params. The options matrix (record 'main') lives in the list route.
// It works in the CONTENT LANGUAGE (app.locale, fixed per mount: the route is re-mounted when the language changes): the form edits that language's version of the
// record. A record with no version in that language shows a message instead of the form (the screens for translations replace it by the «Версии ещё нет» state).
// RIGHTS (area col:<key>): without «Править» the form is read-only (a calm line, inert form, no publish / revert / AI); without «Публиковать» the button is «Отправить на публикацию» (the version then wears «Ждёт публикации»
// and shows up on «Сегодня» of those who may publish; they get «Вернуть автору» next to «Опубликовать»); without «Удалять» there is no delete.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate, useRoute, useRouter } from 'vue-router'
import { api } from '@/api/index.js'
import { app, can, refreshCounters, switchContentLocale, useLocaleGuard } from '@/stores/app.js'
import { getCollectionConfig } from '@/schema/collections.js'
import SchemaForm from '@/components/form/SchemaForm.vue'
import Icon from '@/components/ui/Icon.vue'
import LanguageSelect from '@/components/ui/LanguageSelect.vue'
import StatusChip from '@/components/ui/StatusChip.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ReadOnlyNote from '@/components/ui/ReadOnlyNote.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import { useConfirm } from '@/components/ui/useConfirm.js'
import { useToast } from '@/components/ui/useToast.js'
import CatalogCard from '@/components/collections/CatalogCard.vue'
import ActionDialog from '@/components/collections/actions/ActionDialog.vue'
import { useActions } from '@/components/collections/actions/useActions.js'
import Popover from '@/components/ui/Popover.vue'
import HistoryDrawer from '@/components/history/HistoryDrawer.vue'
import PublishPopover from '@/components/collections/PublishPopover.vue'
import { isConflict, useDraftSave } from '@/components/collections/useDraftSave.js'
import EditConflict from '@/components/ui/EditConflict.vue'
import MissingVersion from '@/components/collections/MissingVersion.vue'
import AddressField from '@/components/collections/AddressField.vue'
import { isMain, isMulti, langName } from '@/utils/locales.js'
import { fmtUpdated, siteOrigin } from '@/utils/format.js'
import { dataFields, labelsOf, pickFields, titleOf } from '@/components/collections/labels.js'
import { cap, onLang, siteIn, up, verAcc, verGen, verNom, versionState } from '@/utils/langWords.js'
import { t } from '@/i18n/index.js'
import { carryTab, takeTab } from '@/components/collections/lang.js'
import { recordChanges } from '@/utils/changes.js'
import { isMissing, reqMessage, validate, valuesEqual } from '@/utils/fields.js'
import { previewField, resolvePreviewPath } from '@/components/editor/previewTarget.js'

const route = useRoute()
const router = useRouter()
const confirm = useConfirm()
const toast = useToast()

const LOC = app.locale
const MULTI = isMulti() // language select, the missing-version state and the language words appear only on a site with 2+ languages
const IS_MAIN = isMain(LOC)
const cfg = computed(() => getCollectionConfig(route.params.col))
const L = computed(() => labelsOf(cfg.value && cfg.value.key))
const area = computed(() => 'col:' + (cfg.value ? cfg.value.key : ''))
const canEdit = computed(() => can(area.value, 'edit'))
const canPublish = computed(() => can(area.value, 'publish'))
const canDelete = computed(() => can(area.value, 'delete'))

const loading = ref(true)
const notFound = ref(false)
const failed = ref('')
const draft = shallowRef(null) // the working copy: the whole record (also id, status, updatedAt); null while loading or when the language has no version
const published = shallowRef(null) // the live copy or null (never published)
const translated = ref(true) // false: the record has no version in the content language (the «Версии ещё нет» state)
const summary = ref(null) // api `locales`: { [code]: { status, changed, changes, title, updatedAt } | null } for the language select
const langSel = ref(null)
const startBusy = ref('') // 'copy' | 'blank' while a translation is being made
const tab = ref('')
const form = ref(null)
const quiet = ref(false) // a new, never published record shows no red «required» errors until the first publish attempt
let target = { col: '', id: '' } // the record on screen: the autosave always carries its own target
let seq = 0
let alive = true
onBeforeUnmount(() => { alive = false; seq++ })

// the saver hands over the version the server last named (baseVersion) and takes the new one from the answer; a refused save (the record was changed meanwhile) shows the banner that asks
const saver = useDraftSave(async ({ col, id, data }, baseVersion) => {
  try {
    return await api.collections.update(col, id, data, LOC, baseVersion)
  } catch (e) {
    // the banner sits at the top of the sheet and may be scrolled out of sight: a conflict says so once more here
    toast.error(isConflict(e) ? t('collections.iv_conflict') : t('collections.draft_not_saved', { reason: e.message || t('collections.error_word') }))
    throw e
  }
})
/**
 * Saves what waits; false (and the reason on screen) when it did not all go through: the record was changed behind our back and the person has not chosen
 * (publishing now would put the other version on the site), or the save failed (publishing now would put the older text on the site).
 */
async function settled() {
  if (await saver.flush()) return true
  toast.error(saver.conflict.value ? t('collections.iv_settle_first') : t('collections.unsaved_first', { reason: saver.error.value || t('collections.error_word') }))
  return false
}
/** Leaving the record: the same, except that a failed save lets the person decide (the edits are lost with the screen). A conflict keeps them here, the banner asks. */
async function mayLeave() {
  // A successful form action may redirect while its own busy state is still set.
  if (working.value || startBusy.value) return false
  if (await saver.flush()) return true
  if (saver.conflict.value) { toast.error(t('collections.iv_settle_first')); return false }
  const discard = await confirm({
    title: t('settings.leave_title'), message: t('collections.leave_unsaved_text', { reason: saver.error.value || t('collections.error_word') }),
    confirmLabel: t('settings.leave_confirm'), cancelLabel: t('people.btn_stay'), danger: true,
  })
  if (discard) saver.reset()
  return discard
}
// leaving the record (another record, the list, another section) with edits that were not saved: the person stays or leaves knowingly (false = stay)
onBeforeRouteLeave(mayLeave)
onBeforeRouteUpdate((to, from) => to.params.col === from.params.col && to.params.id === from.params.id ? true : mayLeave())
/** «Показать новую версию»: the edits on screen are dropped, the record is loaded as it is now. */
async function showNewVersion() {
  working.value = true
  try {
    saver.reset()
    await load({ silent: true })
  } finally {
    working.value = false
  }
}
// the two answers of the banner are not given twice or crosswise while one of them is still on its way
const choosing = ref(false)
async function choose(fn) {
  if (choosing.value) return
  choosing.value = true
  try { await fn() } finally { choosing.value = false }
}
/** «Оставить мои правки»: the same save over the version the server named. */
async function keepMine() {
  await saver.keepMine()
  if (!saver.conflict.value && saver.state.value === 'saved') toast.show(t('collections.mine_kept'))
}

/* ---------- actions of the schema for this place (Action::...->form()) ---------- */
// the draft is saved first: an action works on what the server has
const actions = useActions(() => cfg.value || { key: '', actions: [] }, { onDone: () => load({ silent: true }) })
// an action of «Действия» that runs (its answer may reload the record, also through a modal or a plugin) locks the form like any other write that ends in a reload; the flags are cleared by the actions themselves
const locked = computed(() => working.value || !!actions.busy.value || actions.dialog.busy)
const formActions = computed(() => (cfg.value ? actions.at('form') : []))
const actMenu = ref({ open: false, anchor: null })
const runFormAction = async (a) => {
  actMenu.value = { ...actMenu.value, open: false }
  if (!(await settled())) return
  actions.start(a, [route.params.id])
}

/* ---------- loading ---------- */
async function load({ silent = false, focus = false } = {}) {
  if (!alive) return
  const c = cfg.value
  if (!c) return
  if (c.view === 'matrix') { router.replace({ path: '/collections/' + c.key, query: route.query, hash: route.hash }); return }
  const { col, id } = route.params
  const my = ++seq
  target = { col, id }
  if (!silent) loading.value = true
  const saves = saver.saves()
  try {
    const res = await api.collections.get(col, id, LOC)
    if (my !== seq) return
    summary.value = res.locales
    translated.value = res.translated
    draft.value = res.item // null when the language has no version
    saver.setVersion(res.item && res.item.version, saves)
    published.value = res.published
    if (!silent) quiet.value = !res.published
    notFound.value = false
    failed.value = ''
  } catch (e) {
    if (my !== seq) return
    if (e.status === 404) notFound.value = true
    else failed.value = e.message || t('collections.iv_load_failed')
  }
  if (my !== seq) return
  loading.value = false
  if (focus && draft.value) {
    await nextTick()
    form.value && form.value.focusField(c.titleField)
  }
}
// a record just created in the list opens with ?new=1: the title is focused, the query is dropped
function newFlag() {
  const on = !!route.query.new
  if (on) { const { new: _new, ...query } = route.query; router.replace({ path: route.path, query }) }
  return on
}
const carried = takeTab(route.params.col, route.params.id) // a language switch just re-mounted this screen: same tab, focus back on the language select
if (carried && carried.tab) tab.value = carried.tab
onMounted(async () => {
  await load({ focus: newFlag() })
  await nextTick()
  if (carried && langSel.value) langSel.value.focus()
})

watch(() => [route.params.col, route.params.id], async ([col, id], [oc, oi]) => {
  if (route.name !== 'collection-item' || (col === oc && id === oi)) return
  popOpen.value = false
  // The route guard has already saved or explicitly discarded the previous record.
  saver.reset()
  tab.value = ''
  await load({ focus: newFlag() })
})

/* ---------- what is on screen ---------- */
const fields = computed(() => (cfg.value ? dataFields(cfg.value) : []))
const tabs = computed(() => (cfg.value ? cfg.value.tabs : []))
const activeTab = computed(() => tabs.value.find((x) => x.key === tab.value) || tabs.value[0])
const title = computed(() => (draft.value ? titleOf(cfg.value, draft.value) : ''))
// the heading of a record without a version in this language is the name it has in the main language
const headTitle = computed(() => {
  if (draft.value) return title.value
  const m = summary.value && summary.value[app.languages.main]
  return (m && String(m.title || '').trim()) || labelsOf(cfg.value && cfg.value.key).untitled
})
const isNew = computed(() => !published.value)

const changeList = computed(() => {
  if (!draft.value) return []
  if (!published.value) return [{ label: MULTI ? t('collections.first_unpublished_lang', { lang: onLang(LOC) }) : L.value.first, val: t('collections.first_publication'), old: '' }]
  return recordChanges(cfg.value, draft.value, published.value).map((c) => ({ label: c.label || c.field.label, val: c.val, old: c.old }))
})
const count = computed(() => changeList.value.length)
// header chip: «Черновик» (never published), «Есть правки», or «Опубликован» in the right grammatical gender
const chip = computed(() => {
  if (!draft.value) return null
  if (isNew.value) return { status: 'draft' }
  return count.value ? { status: 'changed' } : { status: 'published', label: L.value.pubLabel }
})

// the state of every language for the select: the language on screen from the live working copy, the others from the last load
const states = computed(() => {
  const out = {}
  app.languages.list.forEach(({ code }) => {
    if (code === LOC && draft.value) out[code] = isNew.value ? 'draft' : count.value ? 'changed' : 'published'
    else out[code] = versionState(summary.value && summary.value[code])
  })
  return out
})
// versions in other languages (the main language is always there): what «Удалить» of the whole record takes along
const translations = computed(() => (summary.value ? app.languages.list.filter((l) => !isMain(l.code) && summary.value[l.code]).map((l) => l.name) : []))

// a red dot on a tab that holds an unfilled required field
const tabErrors = computed(() => Object.fromEntries(tabs.value.map((x) => [x.key, draft.value && !quiet.value ? validate(x.fields, draft.value).length : 0])))

const pagePath = computed(() => {
  const d = draft.value
  if (!d) return ''
  return d.url || ''
})
const siteUrl = computed(() => `${siteOrigin(app.siteInfo.domain)}${pagePath.value}`)
const seo = computed(() => {
  const d = draft.value || {}
  return { url: app.siteInfo.domain + pagePath.value, title: d.seoTitle || title.value, desc: d.seoDesc || '' }
})
// In a language with a URL prefix the address field shows the prefix fixed in front of the input (the first field of the «Для поисковиков» tab)
const addrField = computed(() => {
  const f = activeTab.value && activeTab.value.fields[0]
  if (IS_MAIN || !f || (f.k !== 'url' && f.k !== 'slug')) return null
  // «Старые адреса остаются рабочими» is about the main language's old site; a translation has no old addresses
  return f.k === 'slug' ? { ...f, help: t('collections.iv_slug_help') } : f
})
const formSchema = computed(() => (addrField.value ? activeTab.value.fields.slice(1) : activeTab.value ? activeTab.value.fields : []))
const addrChanged = computed(() => !!addrField.value && !!published.value && !valuesEqual(addrField.value, draft.value[addrField.value.k], published.value[addrField.value.k]))
const addrError = computed(() => {
  const f = addrField.value
  return f && !quiet.value && isMissing(f, draft.value[f.k]) ? reqMessage(f) : ''
})
const setAddr = (v) => onEdit({ ...draft.value, [addrField.value.k]: v })
function revertAddr() {
  onEdit({ ...draft.value, [addrField.value.k]: published.value[addrField.value.k] })
  toast.show(t('collections.iv_change_undone'))
}
const awaiting = computed(() => (draft.value && draft.value.awaiting) || null) // { by, at } while the version waits for somebody to publish it
const returned = computed(() => (draft.value && draft.value.returned) || null) // { by, at } after a publisher sent it back to the author

/* ---------- editing ---------- */
function onEdit(next) {
  if (!canEdit.value) return
  // while a write that ends in a reload is on its way (publish, submit, return, revert, deleting) the form stands still (inert): its answer reloads the record, and what was changed meanwhile would be lost. What still reaches here
  // (the «Отменить» of a toast) is refused aloud: applying it over the reloaded record would be a guess at what the person wants published
  if (locked.value) { toast.error(t('collections.wait_busy')); return }
  draft.value = next
  saver.schedule({ col: target.col, id: target.id, data: pickFields(cfg.value, next) })
}
const saveText = computed(() => ({ dirty: t('collections.saving'), saving: t('collections.saving'), saved: t('collections.saved_to_draft'), error: t('collections.not_saved'), idle: '' }[saver.state.value]))

function onTabKey(e) {
  const i = tabs.value.findIndex((x) => x.key === activeTab.value.key)
  const d = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0
  if (!d) return
  const next = tabs.value[(i + d + tabs.value.length) % tabs.value.length]
  tab.value = next.key
  e.preventDefault()
  nextTick(() => { const el = document.getElementById('cl-tab-' + next.key); if (el) el.focus() })
}

async function focusError(path) {
  const root = String(path).split('.')[0]
  const owner = tabs.value.find((x) => x.fields.some((f) => f.k === root))
  if (owner && (!activeTab.value || owner.key !== activeTab.value.key)) { tab.value = owner.key; await nextTick() }
  if (addrField.value && root === addrField.value.k) {
    const el = document.getElementById('adr-' + root)
    if (el) { el.scrollIntoView({ block: 'center' }); el.focus() }
    return
  }
  if (form.value) await form.value.focusField(path)
}

// A click in the real page preview arrives on this record in the same content language.
watch([loading, () => route.query.field, () => route.query.rows], async ([busy, path, rows]) => {
  if (busy || !draft.value || typeof path !== 'string' || !can(area.value, 'view') || !previewField(tabs.value.flatMap((tab) => tab.fields), path)) return
  const currentPath = resolvePreviewPath(tabs.value.flatMap((tab) => tab.fields), draft.value, path, rows)
  if (!currentPath) { toast.show(t('editor.preview_item_unavailable')); return }
  await nextTick()
  await focusError(currentPath)
})

/* ---------- publish / revert / delete ---------- */
const pubBtn = ref(null)
const popOpen = ref(false)
const working = ref(false) // a write whose answer reloads the record is on its way (publish, submit, return, revert, accept, delete, restore): the form stands still, a late edit would be saved over the new version

async function onPublishClick() {
  if (popOpen.value) { popOpen.value = false; return }
  quiet.value = false
  const errs = validate(fields.value, draft.value)
  if (errs.length) {
    toast.error(t('collections.iv_required'))
    await focusError(errs[0].path)
    return
  }
  popOpen.value = true
}
async function doPublish() {
  const record = target
  working.value = true
  try {
    if (!(await settled())) { popOpen.value = false; return }
    if (record !== target) return
    await api.collections.publish(record.col, record.id, LOC, saver.version())
    if (record !== target) return
    popOpen.value = false
    saver.reset() // «сохранено в черновик» is no longer true: the draft is now the live version
    await load({ silent: true })
    refreshCounters()
    toast.show(MULTI ? t('collections.version_published', { version: up(verNom(LOC)) }) : t('collections.published'))
  } catch (e) {
    if (record !== target) return
    popOpen.value = false
    if (isConflict(e)) {
      saver.publishConflict(e, { col: target.col, id: target.id, data: pickFields(cfg.value, draft.value) })
      toast.error(t('collections.iv_conflict'))
    } else if (e.status === 422 && Array.isArray(e.details) && e.details.length) {
      toast.error(e.message)
      await focusError(e.details[0].path)
    } else toast.error(e.message || t('collections.publish_failed'))
  } finally {
    working.value = false
  }
}
/** «Отправить на публикацию» (a role without «Публиковать»): the version waits for somebody who may publish it. */
async function doSubmit() {
  working.value = true
  try {
    if (!(await settled())) { popOpen.value = false; return }
    await api.collections.submit(target.col, target.id, LOC)
    popOpen.value = false
    saver.reset()
    await load({ silent: true })
    toast.show(t('collections.submitted'))
  } catch (e) {
    popOpen.value = false
    if (e.status === 422 && Array.isArray(e.details) && e.details.length) {
      toast.error(e.message)
      await focusError(e.details[0].path)
    } else toast.error(e.message || t('collections.submit_failed'))
  } finally {
    working.value = false
  }
}
/** «Вернуть автору»: the version stops waiting; it stays as the author left it. */
async function doReturn() {
  const ok = await confirm({
    title: t('collections.return_title'),
    message: t('collections.iv_return_text', { title: title.value }),
    confirmLabel: t('collections.return_to_author'), cancelLabel: t('collections.not_now'),
  })
  if (!ok) return
  working.value = true
  try {
    if (!(await settled())) return
    await api.collections.returnToAuthor(target.col, target.id, LOC)
    await load({ silent: true })
    toast.show(t('collections.iv_returned'))
  } catch (e) {
    toast.error(e.message || t('collections.return_failed'))
  } finally {
    working.value = false
  }
}
async function doRevert() {
  const ok = await confirm({
    title: MULTI ? cap(t('collections.iv_revert_title_lang', { version: verGen(LOC) })) : t('collections.iv_revert_title'),
    message: MULTI
      ? t('collections.iv_revert_text_lang', { title: title.value, site: siteIn(LOC) })
      : t('collections.iv_revert_text', { title: title.value }),
    confirmLabel: t('collections.revert_edits'), danger: true,
  })
  if (!ok) return
  working.value = true
  try {
    if (!(await settled())) return
    await api.collections.revert(target.col, target.id, LOC)
    saver.reset()
    await load({ silent: true })
    refreshCounters()
    toast.show(MULTI ? t('collections.iv_reverted_lang', { version: up(verNom(LOC)) }) : t('collections.iv_reverted'))
  } catch (e) {
    toast.error(e.message || t('collections.revert_failed'))
  } finally {
    working.value = false
  }
}
async function doDelete() {
  // the whole record goes with all its translations: say which ones
  const msg = translations.value.length ? t('collections.delete_message_translations', { langs: translations.value.join(', ') }) : L.value.delMsg
  const ok = await confirm({ title: t('collections.delete_title', { title: title.value }), message: msg, confirmLabel: t('collections.delete'), danger: true })
  if (!ok) return
  working.value = true
  try {
    await api.collections.remove(target.col, target.id)
    saver.reset()
    refreshCounters()
    router.replace('/collections/' + cfg.value.key)
    toast.show(t('collections.deleted'))
  } catch (e) {
    toast.error(e.message || t('collections.delete_failed'))
  } finally {
    working.value = false
  }
}
/* ---------- «История»: who changed the record and when; a version can be returned (the draft becomes it) ---------- */
const histOpen = ref(false)
async function openHistory() {
  if (!(await settled())) return // the newest entry is the record as it is on screen
  histOpen.value = true
}
async function onRestored() {
  histOpen.value = false
  working.value = true
  try {
    saver.reset()
    await load({ silent: true })
  } finally {
    working.value = false
  }
  refreshCounters()
}
/* ---------- other languages: make a version, remove a version ---------- */
// from: the main language = copy its fields, null = schema defaults. Either way a draft, visible only after publication.
async function startTranslation(from) {
  if (startBusy.value) return
  startBusy.value = from ? 'copy' : 'blank'
  try {
    await api.collections.createTranslation(target.col, target.id, LOC, { from })
    quiet.value = true // the texts of a blank version are empty on purpose: no red errors until the first publish attempt
    await load({ silent: true, focus: true })
    refreshCounters()
    toast.show(t('collections.iv_version_created', { version: up(verNom(LOC)) }))
  } catch (e) {
    toast.error(e.message || t('collections.create_version_failed'))
  } finally {
    startBusy.value = ''
  }
}
async function removeVersion() {
  if (!canDelete.value || IS_MAIN || locked.value) return
  const ok = await confirm({
    title: cap(t('collections.iv_delete_version_title', { version: verAcc(LOC) })),
    message: t(cfg.value.pageKind ? 'collections.iv_delete_version_text_page' : 'collections.iv_delete_version_text', { title: title.value, site: siteIn(LOC) }),
    confirmLabel: cap(t('collections.delete_version', { version: verAcc(LOC) })), danger: true,
  })
  if (!ok) return
  working.value = true
  try {
    popOpen.value = false
    await api.collections.removeTranslation(target.col, target.id, LOC)
    saver.reset()
    await load({ silent: true })
    refreshCounters()
    toast.show(t('collections.iv_version_deleted', { version: up(verNom(LOC)) }))
  } catch (e) {
    toast.error(e.message || t('collections.delete_version_failed'))
  } finally {
    working.value = false
  }
}
// switching the tab IS switching the content language (the same switch as in the rail): the screen re-mounts in the other language.
// Both ways go through the guard: the edits of this version are saved under their own language and the open tab is carried over to the new screen.
useLocaleGuard(async () => {
  popOpen.value = false
  if (!(await mayLeave())) return false
  if (!draft.value || !translated.value) return true
  carryTab(target.col, target.id, activeTab.value && activeTab.value.key)
  return true
})
const pickLang = (code) => switchContentLocale(code)

</script>

<template>
  <!-- unknown collection: the list view redirects, here we only wait -->
  <div v-if="!cfg" class="page" />

  <div v-else-if="notFound || failed" class="page">
    <div class="sheet">
      <EmptyState :icon="notFound ? 'search' : 'alert'" :title="notFound ? t('collections.iv_not_found') : t('collections.iv_not_loaded')" :text="notFound ? t('collections.iv_deleted_hint') : failed">
        <router-link class="btn" :to="'/collections/' + cfg.key">{{ t('collections.iv_back_to_list', { title: cfg.title }) }}</router-link>
      </EmptyState>
    </div>
  </div>

  <div v-else class="page">
    <div class="page-h">
      <div class="cl-hd">
        <router-link class="cl-back" :to="'/collections/' + cfg.key"><Icon name="chevL" size="s14" />{{ cfg.title }}</router-link>
        <div class="cl-h1">
          <h1 v-if="draft || summary">{{ headTitle }}</h1>
          <h1 v-else class="cl-h-sk">&nbsp;</h1>
          <LanguageSelect v-if="MULTI && summary" ref="langSel" :model-value="LOC" :languages="app.languages.list" :statuses="states" :label="t('collections.iv_language')" :disabled="locked || !!startBusy" @update:model-value="pickLang" />
        </div>
      </div>
      <div v-if="!loading && !translated" class="actions"><StatusChip status="missing" :label="t('collections.state_missing')" /></div>
      <div v-else-if="draft" class="actions">
        <span v-if="canEdit" class="cl-save" :class="saver.state.value" role="status" aria-live="polite">
          <Icon v-if="saver.state.value === 'saved'" name="check" size="s14" />{{ saveText }}
        </span>
        <StatusChip v-bind="chip" />
        <button
          v-if="formActions.length" type="button" class="btn" aria-haspopup="menu" :aria-expanded="actMenu.open" :class="{ loading: !!actions.busy.value }"
          @click="actMenu = { open: !actMenu.open, anchor: $event.currentTarget }"
        >{{ t('collections.iv_actions') }}<Icon name="chevD" size="s14" /></button>
        <StatusChip v-if="awaiting" status="warn" :label="t('collections.awaiting')" :title="t('collections.sent_for_publishing_by', { by: awaiting.by })" />
        <button type="button" class="btn" @click="openHistory"><Icon name="clock" size="s16" />{{ t('collections.iv_history') }}</button>
        <template v-if="cfg.pageKind">
          <a v-if="!isNew" class="btn" :href="siteUrl" target="_blank" rel="noopener">{{ t('collections.iv_open_on_site') }}<Icon name="external" size="s14" /></a>
        </template>
        <button v-if="canEdit && !isNew && count" type="button" class="btn ghost" @click="doRevert"><Icon name="undo" size="s16" />{{ t('collections.revert_edits') }}</button>
        <button v-if="canEdit && canPublish && awaiting" type="button" class="btn" @click="doReturn">{{ t('collections.return_to_author') }}</button>
        <button
          v-if="canEdit" ref="pubBtn" type="button" class="btn primary" :disabled="!count || (!canPublish && !!awaiting)" :title="canPublish && MULTI ? cap(t('collections.pp_publish_version', { version: verAcc(LOC) })) : undefined"
          :aria-expanded="popOpen" aria-haspopup="dialog" @click="onPublishClick"
        >{{ canPublish ? t('collections.publish') : awaiting ? t('collections.awaiting') : t('collections.pp_send') }}<span v-if="count && (canPublish || !awaiting)" class="cnt">{{ count }}</span></button>
      </div>
    </div>

    <!-- no version in this language: nothing to edit, two ways to start -->
    <div v-if="!loading && !translated" class="sheet cl-miss">
      <MissingVersion v-if="canEdit" :cfg="cfg" :locale="LOC" :busy="startBusy" @copy="startTranslation(app.languages.main)" @blank="startTranslation(null)" />
      <EmptyState v-else icon="globe" :title="t('collections.iv_missing_title')" :text="t('collections.iv_missing_text')" />
    </div>

    <div v-else class="prod-form">
      <div class="sheet">
        <div v-if="loading || !draft" class="cl-sk"><Skeleton :rows="9" /></div>
        <template v-else>
          <EditConflict v-if="saver.conflict.value" class="cl-note" what="item" :current="saver.conflict.value" @show="choose(showNewVersion)" @keep="choose(keepMine)" :busy="choosing" />
          <ReadOnlyNote v-if="!canEdit" class="cl-note" :what="t('collections.iv_readonly_what', { title: cfg.title })" />
          <div v-else-if="awaiting" class="alert warn-a cl-note" role="status">
            <Icon name="clock" size="s16" />
            <span v-if="canPublish"><b>{{ t('collections.awaiting_label') }}</b> {{ t('collections.iv_awaiting_publisher', { by: awaiting.by, when: fmtUpdated(awaiting.at) }) }}</span>
            <span v-else><b>{{ t('collections.awaiting_label') }}</b> {{ app.user && awaiting.by === app.user.name ? t('collections.awaiting_mine', { when: fmtUpdated(awaiting.at) }) : t('collections.awaiting_other', { by: awaiting.by, when: fmtUpdated(awaiting.at) }) }}</span>
          </div>
          <div v-else-if="returned && !canPublish" class="alert warn-a cl-note" role="status">
            <Icon name="undo" size="s16" />
            <span><b>{{ t('collections.returned_label') }}</b> {{ t('collections.returned_rest', { by: returned.by, when: fmtUpdated(returned.at) }) }}</span>
          </div>
          <div v-if="tabs.length > 1" class="tabs cl-tabs" role="tablist" :aria-label="t('collections.iv_sections')" @keydown="onTabKey">
            <button
              v-for="tb in tabs" :id="'cl-tab-' + tb.key" :key="tb.key" type="button" role="tab" :aria-selected="tb.key === activeTab.key"
              :tabindex="tb.key === activeTab.key ? 0 : -1" aria-controls="cl-panel" @click="tab = tb.key"
            >{{ tb.label }}<span v-if="tabErrors[tb.key]" class="cl-dot" :title="t('collections.iv_tab_errors')" /></button>
          </div>
          <div id="cl-panel" :class="['cl-panel', { 'ro-zone': !canEdit }]" :inert="!canEdit || locked" role="tabpanel" :aria-labelledby="tabs.length > 1 ? 'cl-tab-' + activeTab.key : undefined">
            <AddressField
              v-if="addrField" :key="'adr:' + activeTab.key" :field="addrField" :path="addrField.k" :model-value="draft[addrField.k]" top :changed="addrChanged" :error="addrError"
              :locale="LOC" :mode="addrField.k === 'slug' ? 'slug' : 'url'" @update:model-value="setAddr" @revert="revertAddr"
            />
            <SchemaForm
              :key="draft.id + ':' + activeTab.key" ref="form" :schema="formSchema" :model-value="draft" :published="published" :quiet="quiet" :readonly="!canEdit"
              @update:model-value="onEdit"
            />
            <div v-if="activeTab.key === 'seo'" class="field">
              <div class="lbl"><span>{{ t('collections.iv_seo_preview') }}</span></div>
              <div class="seo-prev">
                <div class="su">{{ seo.url }}</div>
                <div class="st">{{ seo.title }}</div>
                <div class="sd">{{ seo.desc }}</div>
              </div>
            </div>
          </div>
          <div v-if="canDelete" class="cl-foot">
            <!-- the main version is the record itself: deleting it deletes the record; another language removes only its own version -->
            <button v-if="IS_MAIN" type="button" class="btn danger sm" @click="doDelete"><Icon name="trash" size="s14" />{{ L.del }}</button>
            <template v-else>
              <button type="button" class="btn danger sm" @click="removeVersion"><Icon name="trash" size="s14" />{{ cap(t('collections.delete_version', { version: verAcc(LOC) })) }}</button>
              <span class="muted cl-foot-n">{{ t('collections.iv_foot', { lang: langName(app.languages.main) }) }}</span>
            </template>
          </div>
        </template>
      </div>

      <aside class="cl-aside" :aria-label="t('collections.iv_aside')">
        <div class="sheet cl-aside-sheet">
          <div class="cl-aside-t">{{ L.asideTitle }}</div>
          <Skeleton v-if="loading || !draft" :rows="4" />
          <template v-else>
            <CatalogCard :cfg="cfg" :data="draft" />
            <p class="muted cl-hint">{{ L.asideHint }}</p>
          </template>
        </div>
      </aside>
    </div>

    <Popover :open="actMenu.open" :anchor="actMenu.anchor" align="right" :width="240" @update:open="(v) => (actMenu = { ...actMenu, open: v })">
      <button v-for="a in formActions" :key="a.key" type="button" :class="['mi', { danger: a.danger }]" @click="runFormAction(a)"><Icon :name="a.icon || (a.danger ? 'trash' : 'chevR')" size="s16" />{{ a.label }}</button>
    </Popover>
    <ActionDialog :actions="actions" />
    <HistoryDrawer v-if="draft" v-model:open="histOpen" kind="item" :resource-id="String(route.params.id)" :locale="LOC" :can-restore="canEdit" @restored="onRestored" />

    <PublishPopover v-model:open="popOpen" :anchor="pubBtn" :changes="changeList" :busy="working" :lang-code="MULTI ? LOC : ''" :send="!canPublish" @confirm="canPublish ? doPublish() : doSubmit()" />
  </div>
</template>

<style scoped>
.cl-hd { min-width: 240px; flex: 1 1 0; }
.cl-hd h1 { overflow-wrap: anywhere; }
.cl-h1 { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; margin-top: 2px; }
.cl-miss { padding: 0; }
.cl-note { margin: 14px 0 0; }
.cl-foot-n { font-size: var(--t-sm); }
.cl-h-sk { min-height: 1.15em; }
.cl-back { display: inline-flex; align-items: center; gap: 2px; font-size: var(--t-sm); color: var(--ink-2); text-decoration: none; margin-left: -3px; }
.cl-back:hover { color: var(--brand-text); }
.cl-save { font-size: var(--t-sm); color: var(--ink-2); display: inline-flex; align-items: center; gap: 5px; min-height: 20px; }
.cl-save.saved { color: var(--green); }
.cl-save.error { color: var(--red); font-weight: 600; }
.cl-sk { padding: 18px 0; }
.cl-tabs { margin: 0 -22px; padding: 0 14px; }
.cl-tabs button { display: inline-flex; align-items: center; gap: 6px; }
.cl-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--red); }
.cl-panel { padding-top: 8px; }
.cl-foot { margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--hair-2); display: flex; justify-content: flex-start; align-items: center; gap: 14px; flex-wrap: wrap; }
.cl-aside { position: sticky; top: 20px; }
.prod-form .cl-aside-sheet { padding: 14px; }
.cl-aside-t { font-weight: 620; margin-bottom: 10px; }
.cl-hint { font-size: var(--t-sm); margin-top: 10px; }
</style>
