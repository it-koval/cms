<script setup>
// «Страницы»: the page tree (expand / collapse), search and filters (template, status), the row menu
// (Открыть, Настройки страницы, Добавить дочернюю страницу, Опубликовать / Снять с публикации, Удалить) and «Новая страница».
// Every page (products and articles too) opens in the page editor. `?template=product` opens the list already filtered by that template (the rail links «Товары» / «Статьи блога»,
// the old product / article collections and Настройки → «Шаблоны» lead here). A template with a card (product, article) is shown as a FLAT list: thumb, parent page, date or the switch that lists the page in its parent's list («В каталоге»),
// and the button «Добавить статью» / «Добавить товар» instead of «Новая страница».
// The list works in the CONTENT LANGUAGE (app.locale): rows describe that language's version; a page without one has status 'missing' («Нет перевода»).
// New pages are always born in the main language (api.pages.create); the content language then switches to it.
// On a multilingual site every row shows one chip per language (LangChips: click = open that language), the «Перевод» filter finds pages without a version
// or with unpublished work in any language, and the row menu names the language it acts on («Опубликовать английскую версию», «Удалить английскую версию»).
// A one-language site looks and works exactly as before.
// STRUCTURE («Изменить структуру»): the page tree as a SortableTree — drag a page by its handle (or Alt + arrows) under another page or to another place; each move is saved at once (api.pages.tree) and can be undone.
// The same template rules as «Настройки страницы» say where a page may go (canPlace), the home page stays first, and the server checks them again.
// RIGHTS: the list shows only the pages whose template the role may see (an author sees articles only: such a list is flat, its parent pages are not his); «Новая страница» and the child / settings / list-switch actions
// need «Править», publishing and unpublishing «Публиковать», both deletes «Удалять» (each judged for the template of the row); a version that waits for publication wears a «Ждёт публикации» chip.
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { t, collator, formatDate } from '@/i18n/index.js'
import { api, isConflict } from '@/api/index.js'
import { thumbUrl } from '@/utils/media.js'
import { app, can, setContentLocale } from '@/stores/app.js'
import { isMain, isMulti } from '@/utils/locales.js'
import Icon from '@/components/ui/Icon.vue'
import FilterSheet from '@/components/ui/FilterSheet.vue'
import Popover from '@/components/ui/Popover.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import { useToast } from '@/components/ui/useToast.js'
import { useConfirm } from '@/components/ui/useConfirm.js'
import { fmtPageUpdated } from '@/utils/format.js'
import NewPageDialog from '@/components/pages/NewPageDialog.vue'
import PageSettingsDialog from '@/components/pages/PageSettingsDialog.vue'
import LangChips from '@/components/pages/LangChips.vue'
import { cap, homeHeldText, nameIn, onLang, version } from '@/utils/langWords.js'
import { versionNone } from '@/components/pages/langWords.js'
import { homeVersionHeld } from '@/components/pages/pageRules.js'
import { flatWords, hasFlatList, isLeafPage, isRootPage, newPageChoices } from '@/components/pages/pageTemplateRules.js'
import { pageHref, parentIds, treeRows } from '@/components/pages/tree.js'
import SortableTree from '@/components/ui/SortableTree.vue'
import * as T from '@/components/ui/treeOps.js'
import { canPlace, getTemplate, listingSwitch, templateName, templatesFor } from '@/schema/templates.js'
import { toggleNote } from '@/components/collections/labels.js'

const router = useRouter()
const route = useRoute()
const toast = useToast()
const confirm = useConfirm()

const allPages = ref([]) // every page (the rules of «Новая страница» and «Настройки страницы» need the whole tree)
// what the role may see; a role limited to some templates gets a flat list: the parents of its pages are not its pages
const pages = computed(() => allPages.value.filter((p) => can('pages', 'view', { template: p.template })))
const scoped = computed(() => !!app.user && Array.isArray(app.user.templates))
const canNew = computed(() => templatesFor().some((x) => can('pages', 'edit', { template: x.key })))
const loaded = ref(false)
const loadError = ref('')

// ---- filters and tree state (remembered for this browser tab, so coming back from the editor feels the same)
const SS = 'itk-admin:pages-view'
const saved = (() => { try { return JSON.parse(sessionStorage.getItem(SS) || 'null') } catch (e) { return null } })()
const q = ref(saved?.q || '')
// «Шаблон»: '' = any. A flat list (product, article) opens only from a link with ?template=…, so the plain «Страницы» always starts with the tree
const tpl = ref((typeof route.query.template === 'string' && route.query.template) || (saved?.tpl && !hasFlatList(saved.tpl) ? saved.tpl : ''))
const status = ref(saved?.status || '')
const tr = ref(saved?.tr || '') // «Перевод»: '' | 'missing:<code>' | 'unpub' (multilingual sites only)
const multi = computed(() => isMulti())
const trVal = computed(() => (multi.value && (tr.value === 'unpub' || trOpts.value.some((o) => o[0] === tr.value)) ? tr.value : ''))
const openMap = ref(saved?.open || null) // { [pageId]: boolean }; null = not decided yet (first load opens the first level)
function remember() {
  try { sessionStorage.setItem(SS, JSON.stringify({ q: q.value, tpl: tpl.value, status: status.value, tr: tr.value, open: openMap.value })) } catch (e) { /* not important */ }
}

// the link «Статьи блога» / the old product collection (/pages?template=product): the list opens filtered by that template
watch(() => route.query.template, (v) => { tpl.value = typeof v === 'string' ? v : ''; remember() })
// the filter in the toolbar keeps the address in step, so a reload or the back button brings the same list
function onTpl() { remember(); router.replace({ path: '/pages', query: tpl.value ? { template: tpl.value } : {} }) }

async function load() {
  try {
    allPages.value = await api.pages.list({ locale: app.locale })
    loadError.value = ''
    if (!openMap.value) {
      // first visit: the first level is open, deeper levels are folded
      const roots = new Set(pages.value.filter((p) => !p.parentId).map((p) => p.id))
      openMap.value = Object.fromEntries(parentIds(pages.value).filter((id) => roots.has(id)).map((id) => [id, true]))
    }
  } catch (e) {
    loadError.value = e.message || t('pages.load_failed')
  } finally {
    loaded.value = true
  }
}
onMounted(load)

// the templates of the site that have pages (the chosen one always), in the order of the pickers
const tpls = computed(() => templatesFor().filter((x) => x.key === tpl.value || pages.value.some((p) => p.template === x.key)).map((x) => [x.key, x.name]))
// FLAT list of one template (product, article): the template is chosen and has a card
const flatT = computed(() => (tpl.value && hasFlatList(tpl.value) ? getTemplate(tpl.value) : null))
const narrowed = computed(() => !!(q.value.trim() || status.value || trVal.value))
const activeCount = computed(() => [tpl.value && !flatT.value, status.value, tr.value].filter(Boolean).length) // the filters behind «Фильтры» on a phone
const filtering = computed(() => narrowed.value || (!!tpl.value && !flatT.value))
// «Перевод»: one «нет версии» option per non-main language, plus «unpublished work in any version»
const trOpts = computed(() => app.languages.list.filter((l) => !isMain(l.code)).map((l) => [`missing:${l.code}`, cap(t('pages.filter_no_version', { version: versionNone(l.code) }))]))
const isOpen = (id) => !!(openMap.value && openMap.value[id])

const matches = (p) => {
  const needle = q.value.trim().toLowerCase()
  if (needle && !`${p.title} ${p.url}`.toLowerCase().includes(needle)) return false
  if (tpl.value && p.template !== tpl.value) return false
  if (status.value === 'unpub' && !(p.status === 'draft' || p.changed)) return false
  if (status.value === 'draft' && p.status !== 'draft') return false
  if (status.value === 'pub' && (p.status === 'draft' || p.status === 'missing')) return false
  if (trVal.value === 'unpub') return Object.values(p.locales).some((v) => v && (v.status === 'draft' || v.changed))
  if (trVal.value) return !p.locales[trVal.value.slice(8)] // 'missing:<code>'
  return true
}
const ofTemplate = computed(() => (flatT.value ? pages.value.filter((p) => p.template === flatT.value.key) : []))
const byDateDesc = (a, b) => collator.compare(String(b.fields.date || ''), String(a.fields.date || '')) // newest article first, undated last
const flatRows = computed(() => {
  const list = ofTemplate.value.filter(matches)
  return flatT.value && flatT.value.card && flatT.value.card.date ? [...list].sort(byDateDesc) : list
})
const rows = computed(() => (flatT.value
  ? flatRows.value.map((p) => ({ p, lvl: 0, kids: 0 }))
  : filtering.value || scoped.value
    ? pages.value.filter(matches).map((p) => ({ p, lvl: 0, kids: 0 }))
    : treeRows(pages.value, isOpen)))
// from 1400 px two language chips sit on one line (more of them wrap) and the column keeps room for them; narrower windows stack the chips
const stW = computed(() => (multi.value ? { '--lcw': Math.min(app.languages.list.length, 2) * 152 + 28 + 'px' } : undefined))
const folders = computed(() => parentIds(pages.value))
const allOpen = computed(() => folders.value.length > 0 && folders.value.every(isOpen))

function toggle(id) { openMap.value = { ...openMap.value, [id]: !isOpen(id) }; remember() }
function toggleAll() {
  openMap.value = allOpen.value ? {} : Object.fromEntries(folders.value.map((id) => [id, true]))
  remember()
}
function resetFilters() {
  q.value = ''; status.value = ''; tr.value = ''
  if (!flatT.value) tpl.value = '' // a flat list stays on its template: «Все страницы» leaves it
  remember()
  if (!tpl.value && route.query.template) router.replace({ path: '/pages' })
}

// ---- the flat list (product, article): words, columns, thumbs
const words = computed(() => (flatT.value ? flatWords(flatT.value.key) : null))
const flatTitle = computed(() => (flatT.value ? flatT.value.shortcut || flatT.value.name : ''))
const hasDate = computed(() => !!(flatT.value && flatT.value.card && flatT.value.card.date))
// the switch that puts a page of this template into the list of its parent page (schema/templates.js listingSwitch), or null
const listSwitch = computed(() => (flatT.value ? listingSwitch(flatT.value.key) : null))
const parentOf = (p) => (p.parentId ? allPages.value.find((x) => x.id === p.parentId) || null : null)
const thumbOf = (p) => {
  const v = flatT.value && flatT.value.card && flatT.value.card.image && p.fields[flatT.value.card.image]
  return v && v.src ? v : null
}
const dayText = (v) => { const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(v || '')); return m ? formatDate(new Date(+m[1], +m[2] - 1, +m[3]), { day: '2-digit', month: '2-digit', year: 'numeric' }) : '' }
// «Названия и адреса — на английском; у страниц без перевода показано русское название.»: what the titles and addresses of the list are written in
const namesNote = () => (isMain(app.locale)
  ? t('pages.sub_names', { language: onLang(app.locale) })
  : t('pages.sub_names_fallback', { language: onLang(app.locale), name: nameIn(app.languages.main) }))
const flatSub = computed(() => {
  const base = words.value.sub(ofTemplate.value.length, flatT.value.name)
  return multi.value ? `${base} ${namesNote()}` : base
})
// «Добавить статью» is possible while the template has a place (a single template that exists already, or a missing parent page, disables it and says why)
const addChoice = computed(() => (flatT.value ? newPageChoices(mainRows.value, undefined).find((c) => c.template.key === flatT.value.key) : null))
const canAddFlat = computed(() => !!flatT.value && can('pages', 'edit', { template: flatT.value.key }))
const awaitingIn = (p) => Object.values(p.locales).some((v) => v && v.awaiting)
const mayEdit = (p) => can('pages', 'edit', { template: p.template })

const subtitle = computed(() => {
  const base = t('pages.sub_count', { count: pages.value.length, domain: app.siteInfo.domain })
  // a page without a version in this language is still listed: it carries the main language's title
  if (multi.value) return `${base} ${namesNote()} ${t('pages.sub_switch_hint')}`
  return `${base} ${t('pages.sub_open_hint')}`
})

const go = (p) => router.push(pageHref(p))
function onRow(p, e) {
  if (e.target.closest('a,button,label,input')) return
  go(p)
}

// ---- row menu
const menuOpen = ref(false)
const menuRow = ref(null)
const menuAnchor = ref(null)
function openMenu(p, e) {
  if (menuOpen.value && menuRow.value && menuRow.value.id === p.id) { menuOpen.value = false; return }
  menuRow.value = p
  menuAnchor.value = e.currentTarget
  menuOpen.value = true
}
const childCount = (p) => allPages.value.filter((x) => x.parentId === p.id).length
const m = computed(() => {
  const p = menuRow.value
  if (!p) return null
  const home = isRootPage(p)
  const leaf = isLeafPage(p) // a product or an article has no child pages
  const kids = childCount(p)
  const lng = multi.value
  const miss = !p.translated // no version in the content language: nothing to publish or unpublish there
  const ver = version(app.locale)
  const held = lng && !isMain(app.locale) && !miss && homeVersionHeld(allPages.value, p, app.locale) // the home page of a language stays while the language has other pages on the site
  const edit = can('pages', 'edit', { template: p.template })
  const pub = can('pages', 'publish', { template: p.template })
  const del = can('pages', 'delete', { template: p.template })
  return {
    miss, edit, del,
    openLabel: miss ? (edit ? cap(t('pages.create_version', { version: ver.acc })) : t('pages.open')) : edit ? t('pages.open') : t('pages.view'),
    publish: pub && !miss && (p.status === 'draft' || p.changed),
    publishLabel: lng ? (p.status === 'draft' ? cap(t('pages.publish_version', { version: ver.acc })) : cap(t('pages.publish_version_changes', { version: ver.gen }))) : (p.status === 'draft' ? t('pages.publish') : t('pages.publish_changes')),
    unpublish: pub && !miss && !home && p.status === 'published',
    unpublishLabel: lng ? cap(t('pages.unpublish_version', { version: ver.acc })) : t('pages.unpublish'),
    nest: edit && canNew.value && !home && !leaf,
    removeVersion: del && lng && !isMain(app.locale) && !miss, // delete ONE translation (never the main language)
    removeVersionLabel: cap(t('pages.delete_version', { version: ver.acc })),
    removeVersionOff: held,
    deleteLabel: lng ? t('pages.delete_whole') : t('pages.delete'),
    settingsOff: miss,
    deleteOff: home || kids > 0,
    note: home ? (held ? homeHeldText(app.locale) : t('pages.home_locked_note'))
      : kids > 0 ? t('pages.delete_has_children', { count: kids })
        : miss ? t('pages.version_missing_note', { version: cap(versionNone(app.locale)) }) : '',
  }
})

// ---- dialogs
const newOpen = ref(false)
const newParent = ref(null)
const setOpen = ref(false)
const setPage = ref(null)
const newTemplate = ref('')
function openNew(parentId = null, template = '') { newParent.value = parentId; newTemplate.value = template; newOpen.value = true }
function openSettings(p) {
  if (!p.translated) { toast.error(t('pages.settings_no_version', { version: cap(versionNone(app.locale)) })); return }
  setPage.value = p
  setOpen.value = true
}
// «Новая страница» works with the addresses of the MAIN language, whatever language the list is shown in
const mainRows = computed(() => allPages.value.map((p) => {
  const v = p.locales[app.languages.main]
  return v ? { ...p, title: v.title, url: v.url } : p
}))

async function onCreated(page) {
  toast.show(t('pages.created_toast', { title: page.title }))
  setContentLocale(app.languages.main) // a new page is born in the main language: the editor opens that version
  router.push(`/pages/${page.id}`)
}
async function onSaved(page) {
  await load()
  toast.show(page.status === 'published' ? t('pages.settings_saved_published') : t('pages.settings_saved'))
}

// ---- publish / unpublish / delete
async function publishRow(p) {
  try {
    await api.pages.publish(p.id, p.locale, p.version)
    toast.show(multi.value ? t('pages.published_version', { version: cap(version(p.locale).nom), title: p.title }) : t('pages.published', { title: p.title }))
    await load()
  } catch (e) {
    if (isConflict(e)) await load()
    toast.error(e.status === 422 ? t('pages.publish_refused', { title: p.title, message: `${e.message.charAt(0).toLowerCase()}${e.message.slice(1)}` }) : e.message, e.status === 422 ? { undoLabel: t('pages.open_lower'), undo: () => go(p) } : {})
  }
}
async function unpublishRow(p) {
  const locale = p.locale
  const ver = version(locale)
  const ok = await confirm({
    title: multi.value ? t('pages.unpublish_version_title', { version: ver.acc, title: p.title }) : t('pages.unpublish_title', { title: p.title }),
    message: multi.value
      ? t('pages.unpublish_version_message', { version: cap(ver.nom), url: p.url })
      : t('pages.unpublish_message'),
    confirmLabel: t('pages.unpublish'),
  })
  if (!ok) return
  try {
    await api.pages.unpublish(p.id, locale)
    await load()
    toast.show(multi.value ? t('pages.unpublished_version', { version: cap(ver.nom), title: p.title }) : t('pages.unpublished', { title: p.title }), { undoLabel: t('pages.undo'), undo: async () => { try { await api.pages.undoUnpublish(p.id, locale); await load() } catch (err) { toast.error(err.message) } } })
  } catch (e) {
    toast.error(e.message)
  }
}
async function removeRow(p) {
  const live = multi.value ? Object.values(p.locales).some((v) => v && v.published) : p.status === 'published'
  const names = multi.value ? app.languages.list.filter((l) => p.locales[l.code]).map((l) => l.name) : []
  // every address that is on the site now (one per published language version): they all stop opening
  const urls = multi.value ? app.languages.list.filter((l) => p.locales[l.code] && p.locales[l.code].published).map((l) => p.locales[l.code].url) : [p.url]
  const hint = m.value && m.value.removeVersion ? ` ${t('pages.delete_hint_one_version', { action: cap(t('pages.delete_version', { version: version(app.locale).acc })) })}` : ''
  const ok = await confirm({
    title: t('pages.delete_title', { title: p.title }),
    message: (live
      ? (urls.length > 1 ? t('pages.delete_live_many', { urls: urls.join(', ') }) : t('pages.delete_live_one', { url: urls[0] || p.url }))
      : t('pages.delete_draft'))
      + (names.length > 1 ? ` ${t('pages.delete_with_versions', { names: names.join(', ') })}${hint}` : ''),
    confirmLabel: t('pages.delete'), danger: true,
  })
  if (!ok) return
  try {
    await api.pages.remove(p.id)
    toast.show(t('pages.deleted', { title: p.title }))
    await load()
  } catch (e) {
    toast.error(e.message)
  }
}
/** Delete ONE translation: the page stays on the site in the other languages. */
async function removeVersionRow(p) {
  const code = app.locale
  const ver = version(code)
  const live = p.status === 'published'
  const ok = await confirm({
    title: t('pages.delete_version_title', { version: ver.acc, title: p.title }),
    message: (live
      ? t('pages.delete_version_live', { language: onLang(code), url: p.url })
      : t('pages.delete_version_draft'))
      + ` ${t('pages.delete_version_tail')}`,
    confirmLabel: cap(t('pages.delete_version', { version: ver.acc })), danger: true,
  })
  if (!ok) return
  try {
    await api.pages.removeTranslation(p.id, code)
    toast.show(t('pages.version_deleted', { version: cap(ver.nom), title: p.title }))
    await load()
  } catch (e) {
    toast.error(e.message)
  }
}

const upd = (p) => fmtPageUpdated(p)

// ---- structure: drag and drop in the tree
const arrange = ref(false)
const arrangeBusy = ref(false)
const canArrange = computed(() => canNew.value && !flatT.value && !filtering.value && !scoped.value && pages.value.length > 1)
const pageTree = computed(() => T.nest(pages.value))
const treeLocked = (p) => (isRootPage(p) ? t('pages.tree_home_locked') : !can('pages', 'edit', { template: p.template }) ? t('pages.tree_forbidden') : false)
function treeRule({ node, parent }) {
  const r = canPlace(node.template, parent, allPages.value, node.id)
  return r.ok ? true : r.reason
}
async function onTreeMove(ev) {
  const before = pageTree.value
  const moved = ev.node
  arrangeBusy.value = true
  try {
    await api.pages.tree(T.payload(ev.nodes, ev.groups))
    await load()
    const parentTitle = ev.to.parentId ? (pages.value.find((x) => x.id === ev.to.parentId) || {}).title || '' : null
    toast.show(ev.to.parentId === ev.from.parentId
      ? t('pages.moved_position', { title: moved.title, position: ev.to.index + 1 })
      : parentTitle === null ? t('pages.moved_top', { title: moved.title }) : t('pages.moved_inside', { title: moved.title, parent: parentTitle }), {
      undoLabel: t('pages.undo'),
      undo: async () => { try { await api.pages.tree(T.payload(before, ev.groups)); await load() } catch (e) { toast.error(e.message) } },
    })
  } catch (e) {
    toast.error(e.message || t('pages.move_failed'))
    await load()
  } finally {
    arrangeBusy.value = false
  }
}

// ---- the list switch of the flat list: a draft change of the page, it goes live on publish
async function toggleListed(p, ev) {
  const val = ev.target.checked
  const sw = listSwitch.value
  const set = async (v) => { await api.pages.saveDraft(p.id, app.locale, { fields: { ...p.fields, [sw.key]: v } }); await load() }
  try {
    await set(val)
    toast.show(toggleNote(p.title, sw.label, val), {
      undo: () => set(!val).catch((e) => toast.error(e.message)), undoLabel: t('pages.undo'),
    })
  } catch (e) {
    ev.target.checked = !val
    toast.error(e.message || t('pages.toggle_failed'))
  }
}
</script>

<template>
  <div class="page">
    <div class="page-h">
      <div v-if="flatT && loaded && !loadError">
        <router-link class="back" to="/pages"><Icon name="chevL" size="s14" />{{ t('pages.all_pages') }}</router-link>
        <h1>{{ flatTitle }}</h1>
        <p class="sub">{{ flatSub }}</p>
      </div>
      <div v-else><h1>{{ t('pages.title') }}</h1><p class="sub">{{ loaded && !loadError ? subtitle : t('pages.subtitle_default') }}</p></div>
      <div class="actions">
        <button v-if="flatT && loaded && canAddFlat" class="btn primary" type="button" :disabled="!addChoice || !addChoice.ok" :title="addChoice && !addChoice.ok ? addChoice.reason : undefined" @click="openNew(null, flatT.key)"><Icon name="plus" size="s16" />{{ words.add }}</button>
        <router-link v-if="!arrange" class="btn" :to="flatT ? { path: '/trash', query: { kind: 'page', key: flatT.key } } : '/trash'"><Icon name="trash" size="s16" />{{ t('pages.trash') }}</router-link>
        <button v-if="!flatT && canArrange && !arrange" class="btn" type="button" @click="arrange = true"><Icon name="grip" size="s16" />{{ t('pages.arrange') }}</button>
        <button v-else-if="arrange" class="btn primary" type="button" @click="arrange = false"><Icon name="check" size="s16" />{{ t('pages.done') }}</button>
        <button v-if="!flatT && canNew && !arrange" class="btn primary" type="button" @click="openNew()"><Icon name="plus" size="s16" />{{ t('pages.new_page') }}</button>
      </div>
    </div>

    <div v-if="loadError" class="sheet" style="padding:22px">
      <div class="alert err-a" role="alert"><Icon name="alert" size="s16" /><div>{{ loadError }}</div></div>
      <button class="btn" type="button" style="margin-top:14px" @click="load">{{ t('pages.retry') }}</button>
    </div>
    <div v-else-if="!loaded" class="sheet" style="padding:18px 22px" aria-busy="true"><Skeleton :rows="8" /></div>

    <div v-else class="sheet">
      <div v-if="!arrange" class="toolbar">
        <div class="search">
          <label class="sr" for="pq">{{ t('pages.search_label') }}</label>
          <Icon name="search" size="s16" />
          <input id="pq" v-model="q" type="search" :placeholder="t('pages.search_placeholder')" autocomplete="off" @input="remember">
        </div>
        <FilterSheet :count="activeCount" @reset="resetFilters">
        <select id="pk" v-model="tpl" :aria-label="t('pages.template_label')" @change="onTpl">
          <option value="">{{ t('pages.filter_all_templates') }}</option>
          <option v-for="k in tpls" :key="k[0]" :value="k[0]">{{ k[1] }}</option>
        </select>
        <select id="ps" v-model="status" :aria-label="t('pages.status_label')" @change="remember">
          <option value="">{{ t('pages.filter_any_status') }}</option>
          <option value="unpub">{{ t('pages.filter_unpublished') }}</option>
          <option value="draft">{{ t('pages.filter_drafts_only') }}</option>
          <option value="pub">{{ t('pages.filter_published') }}</option>
        </select>
        <select v-if="multi" id="pt" v-model="tr" :aria-label="t('pages.translation_label')" @change="remember">
          <option value="">{{ t('pages.filter_any_translation') }}</option>
          <option v-for="o in trOpts" :key="o[0]" :value="o[0]">{{ o[1] }}</option>
          <option value="unpub">{{ t('pages.filter_edits_any') }}</option>
        </select>
        </FilterSheet>
        <button v-if="filtering" class="btn ghost sm" type="button" @click="resetFilters"><Icon name="x" size="s14" />{{ t('pages.reset') }}</button>
        <button v-else-if="folders.length && !flatT && !scoped" class="btn ghost sm" type="button" @click="toggleAll">{{ allOpen ? t('pages.collapse_all') : t('pages.expand_all') }}</button>
        <span v-if="filtering" class="grow" aria-live="polite">{{ t('pages.found', { found: rows.length, total: flatT ? ofTemplate.length : pages.length }) }}</span>
      </div>

      <div v-if="arrange" class="pg-arr">
        <p class="pg-note"><Icon name="info" size="s16" />{{ t('pages.arrange_note') }}</p>
        <SortableTree :nodes="pageTree" :can-drop="treeRule" :locked="treeLocked" :disabled="arrangeBusy" :aria-label="t('pages.tree_label')" @move="onTreeMove" @invalid="toast.error">
          <template #default="{ node }">
            <div class="pg-n">
              <router-link :to="pageHref(node)" class="pg-t">{{ node.title }}</router-link>
              <span class="muted pg-u">{{ node.translated ? node.url : '' }}</span>
              <span class="muted pg-k">{{ templateName(node.template) }}</span>
              <span v-if="node.status === 'draft'" class="chip draft"><span class="dot" />{{ t('pages.draft') }}</span>
            </div>
          </template>
        </SortableTree>
      </div>

      <table v-else-if="rows.length && flatT" class="t t-stack pages-t flat-t">
        <thead><tr>
          <th>{{ t('pages.col_title') }}</th><th>{{ t('pages.col_url') }}</th><th>{{ t('pages.parent_label') }}</th>
          <th v-if="hasDate">{{ t('pages.col_date') }}</th><th v-if="listSwitch" class="tg">{{ listSwitch.label }}</th>
          <th>{{ multi ? t('pages.col_versions') : t('pages.status_label') }}</th><th>{{ t('pages.col_changed') }}</th><th class="act"><span class="sr">{{ t('pages.col_actions') }}</span></th>
        </tr></thead>
        <tbody>
          <tr v-for="r in rows" :key="r.p.id" :class="['click', { mis: !r.p.translated }]" @click="onRow(r.p, $event)">
            <td class="ts-main">
              <div class="ft">
                <img v-if="thumbOf(r.p)" class="thumb" :src="thumbUrl(thumbOf(r.p).src)" alt="">
                <span v-else class="thumb nophoto"><Icon name="image" size="s16" /></span>
                <router-link :to="pageHref(r.p)" :title="r.p.translated ? undefined : t('pages.version_missing_title', { version: cap(versionNone(app.locale)) })">{{ r.p.title }}</router-link>
              </div>
            </td>
            <td class="url ts-sub"><span v-if="r.p.translated" class="u" :title="r.p.url">{{ r.p.url }}</span><span v-else class="muted">—</span></td>
            <td class="nw ts-sub" :data-label="t('pages.col_parent')"><span v-if="parentOf(r.p)">{{ parentOf(r.p).title }}</span><span v-else class="muted">—</span></td>
            <td v-if="hasDate" class="nw ts-sub" :data-label="t('pages.col_date')">{{ dayText(r.p.fields.date) || '—' }}</td>
            <td v-if="listSwitch" class="tg ts-sub" :data-label="listSwitch.label">
              <label class="sw" :title="r.p.translated ? undefined : t('pages.add_translation_first')">
                <input type="checkbox" :checked="!!r.p.fields[listSwitch.key]" :disabled="!r.p.translated || !mayEdit(r.p)" @change="toggleListed(r.p, $event)">
                <span class="tr" /><span class="sr">{{ listSwitch.label }}: {{ r.p.title }}</span>
              </label>
            </td>
            <td class="ts-st">
              <div v-if="multi" class="chips lc-chips"><LangChips :page="r.p" :to="pageHref(r.p)" /><span v-if="awaitingIn(r.p)" class="chip warn">{{ t('pages.awaiting') }}</span></div>
              <div v-else class="chips">
                <span v-if="r.p.status === 'missing'" class="chip" :title="t('pages.missing_title')">{{ t('pages.no_translation') }}</span>
                <span v-else-if="r.p.status === 'draft'" class="chip draft"><span class="dot" />{{ t('pages.draft') }}</span>
                <template v-else>
                  <span class="chip pub"><span class="dot" />{{ t('pages.status_published') }}</span>
                  <span v-if="r.p.changed" class="chip draft" :title="t('pages.unpublished_changes')">{{ t('pages.changes', { count: r.p.changes }) }}</span>
                </template>
                <span v-if="r.p.awaiting" class="chip warn">{{ t('pages.awaiting') }}</span>
              </div>
            </td>
            <td class="muted nw upd ts-sub">{{ r.p.translated ? upd(r.p) : '—' }}</td>
            <td class="act ts-act"><button type="button" class="btn ghost icon sm" aria-haspopup="menu" :aria-expanded="menuOpen && menuRow && menuRow.id === r.p.id" :aria-label="t('pages.row_actions', { title: r.p.title })" @click="openMenu(r.p, $event)"><Icon name="more" /></button></td>
          </tr>
        </tbody>
      </table>

      <table v-else-if="rows.length" class="t t-stack pages-t">
        <thead><tr><th>{{ t('pages.col_title') }}</th><th>{{ t('pages.col_url') }}</th><th>{{ t('pages.template_label') }}</th><th :class="{ lcs: multi }" :style="stW">{{ multi ? t('pages.col_versions') : t('pages.status_label') }}</th><th>{{ t('pages.col_changed') }}</th><th class="act"><span class="sr">{{ t('pages.col_actions') }}</span></th></tr></thead>
        <tbody>
          <tr v-for="r in rows" :key="r.p.id" :class="['click', 'lvl' + Math.min(r.lvl, 2), { mis: !r.p.translated }]" @click="onRow(r.p, $event)">
            <td class="ts-main">
              <div class="tr-ttl" :style="{ '--l': r.lvl }">
                <button v-if="r.kids && !filtering && !scoped" type="button" :class="['twist', { open: isOpen(r.p.id) }]" :aria-expanded="isOpen(r.p.id)" :aria-label="t(isOpen(r.p.id) ? 'pages.collapse_row' : 'pages.expand_row', { title: r.p.title })" @click="toggle(r.p.id)"><Icon name="chevR" size="s14" /></button>
                <span v-else class="sp" />
                <router-link :to="pageHref(r.p)" :title="r.p.translated ? undefined : t('pages.version_missing_title', { version: cap(versionNone(app.locale)) })">{{ r.p.title }}</router-link>
                <span v-if="r.kids && !filtering && !scoped" class="muted kids" :title="t('pages.children_count', { count: r.kids })">{{ r.kids }}</span>
              </div>
            </td>
            <td class="url ts-sub"><span v-if="r.p.translated" class="u" :title="r.p.url">{{ r.p.url }}</span><span v-else class="muted">—</span></td>
            <td class="kind nw ts-sub">{{ templateName(r.p.template) }}</td>
            <td class="ts-st">
              <div v-if="multi" class="chips lc-chips"><LangChips :page="r.p" :to="pageHref(r.p)" /><span v-if="awaitingIn(r.p)" class="chip warn">{{ t('pages.awaiting') }}</span></div>
              <div v-else class="chips">
                <span v-if="r.p.status === 'missing'" class="chip" :title="t('pages.missing_title')">{{ t('pages.no_translation') }}</span>
                <span v-else-if="r.p.status === 'draft'" class="chip draft"><span class="dot" />{{ t('pages.draft') }}</span>
                <template v-else>
                  <span class="chip pub"><span class="dot" />{{ t('pages.status_published') }}</span>
                  <span v-if="r.p.changed" class="chip draft" :title="t('pages.unpublished_changes')">{{ t('pages.changes', { count: r.p.changes }) }}</span>
                </template>
                <span v-if="r.p.awaiting" class="chip warn">{{ t('pages.awaiting') }}</span>
              </div>
            </td>
            <td class="muted nw upd ts-sub">{{ r.p.translated ? upd(r.p) : '—' }}</td>
            <td class="act ts-act"><button type="button" class="btn ghost icon sm" aria-haspopup="menu" :aria-expanded="menuOpen && menuRow && menuRow.id === r.p.id" :aria-label="t('pages.row_actions', { title: r.p.title })" @click="openMenu(r.p, $event)"><Icon name="more" /></button></td>
          </tr>
        </tbody>
      </table>

      <EmptyState v-else-if="flatT && !narrowed" icon="pages" :title="words.none" :text="t('pages.flat_empty_text', { template: flatT.name })">
        <button v-if="canAddFlat" class="btn primary" type="button" :disabled="!addChoice || !addChoice.ok" @click="openNew(null, flatT.key)"><Icon name="plus" size="s16" />{{ words.add }}</button>
      </EmptyState>
      <EmptyState v-else-if="filtering" icon="search" :title="t('pages.nothing_found')" :text="t('pages.nothing_found_text')">
        <button class="btn" type="button" @click="resetFilters">{{ t('pages.reset_search') }}</button>
      </EmptyState>
      <EmptyState v-else icon="pages" :title="t('pages.empty_title')" :text="canNew ? t('pages.empty_text_can') : t('pages.empty_text_cant')">
        <button v-if="canNew" class="btn primary" type="button" @click="openNew()"><Icon name="plus" size="s16" />{{ t('pages.new_page') }}</button>
      </EmptyState>
    </div>

    <Popover v-model:open="menuOpen" :anchor="menuAnchor" :width="multi ? 320 : 290" align="right">
      <template v-if="menuRow && m">
        <button class="mi" type="button" @click="go(menuRow)"><Icon :name="m.miss && m.edit ? 'plus' : m.edit ? 'edit' : 'eye'" size="s16" />{{ m.openLabel }}</button>
        <button v-if="m.edit" class="mi" type="button" :disabled="m.settingsOff" @click="openSettings(menuRow)"><Icon name="sliders" size="s16" />{{ t('pages.settings_title') }}</button>
        <button v-if="m.nest" class="mi" type="button" @click="openNew(menuRow.id)"><Icon name="plus" size="s16" />{{ t('pages.add_child') }}</button>
        <hr v-if="m.publish || m.unpublish">
        <button v-if="m.publish" class="mi" type="button" @click="publishRow(menuRow)"><Icon name="check" size="s16" />{{ m.publishLabel }}</button>
        <button v-if="m.unpublish" class="mi" type="button" @click="unpublishRow(menuRow)"><Icon name="eyeOff" size="s16" />{{ m.unpublishLabel }}</button>
        <hr v-if="m.del">
        <button v-if="m.removeVersion" class="mi danger" type="button" :disabled="m.removeVersionOff" @click="removeVersionRow(menuRow)"><Icon name="trash" size="s16" />{{ m.removeVersionLabel }}</button>
        <button v-if="m.del" class="mi danger" type="button" :disabled="m.deleteOff" @click="removeRow(menuRow)"><Icon name="trash" size="s16" />{{ m.deleteLabel }}</button>
        <p v-if="m.note" class="mi-note">{{ m.note }}</p>
      </template>
    </Popover>

    <NewPageDialog v-model:open="newOpen" :pages="mainRows" :parent-id="newParent" :template="newTemplate" @created="onCreated" />
    <PageSettingsDialog v-model:open="setOpen" :page="setPage" :pages="allPages" @saved="onSaved" />
  </div>
</template>

<style scoped>
.page-h .sub{max-width:74ch}
.pages-t th,.pages-t td{padding-left:10px;padding-right:10px}
.pages-t th:first-child,.pages-t td:first-child{padding-left:14px}
.pages-t th.act,.pages-t td.act{width:1%;padding-left:2px;padding-right:10px;text-align:right}
.pages-t .u{display:block;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
/* two language chips sit on one line from 1400 px and take their room: the name keeps about 260 px so a long product name wraps to two lines, not four */
@media (min-width:1400px){.pages-t th:first-child,.pages-t td:first-child{min-width:260px}}
.pages-t td.upd{font-size:var(--t-sm)}
.kids{font-size:var(--t-xs);font-variant-numeric:tabular-nums}
.back{display:inline-flex;align-items:center;gap:4px;margin:0 0 6px -2px;font-size:var(--t-sm);font-weight:600;color:var(--brand-text);text-decoration:none}
.back:hover{color:var(--brand-text-d);text-decoration:underline;text-underline-offset:.2em}
/* the flat list of a template: a thumb and the name, the parent page, a date or the list switch */
.flat-t th.tg,.flat-t td.tg{width:1%;white-space:nowrap}
.flat-t .lc-chips{flex-direction:column;flex-wrap:nowrap}
.ft{display:flex;align-items:center;gap:12px}
.ft a{font-weight:600;color:var(--ink);text-decoration:none}
.ft a:hover{color:var(--brand-text);text-decoration:underline;text-underline-offset:.2em}
.ft .thumb{width:44px;height:44px;object-fit:contain;background:#fff}
.ft .nophoto{display:grid;place-items:center;color:var(--ink-3);background:var(--hair-2)}
.flat-t tr.mis .ft a{color:var(--ink-2);font-weight:500}
.pages-t .act .btn{color:var(--ink-2)}
.pages-t .act .btn .ic{stroke-width:3}
.pages-t .act .btn:hover,.pages-t .act .btn[aria-expanded=true]{color:var(--brand-text);background:var(--cy-50)}
.lc-chips{gap:4px;flex-direction:column;align-items:flex-start}
@media (min-width:1400px){.lc-chips{flex-direction:row;flex-wrap:wrap}.pages-t th.lcs{min-width:var(--lcw)}}
.pages-t tr.mis .tr-ttl a{color:var(--ink-2);font-weight:500}
/* a disabled destructive item looks disabled (the shared .danger colour would keep it red) */
.pop button.mi.danger[disabled]{color:#7B8A94}
.pop button.mi.danger[disabled]:hover{background:none}
.pg-arr{padding:14px}
.pg-note{display:flex;gap:8px;align-items:flex-start;margin:0 0 12px;max-width:78ch;font-size:var(--t-sm);line-height:1.45;color:var(--ink-2)}
.pg-note .ic{flex:none;margin-top:2px;color:var(--brand-text)}
.pg-n{display:flex;align-items:center;gap:12px;min-width:0}
.pg-t{font-weight:600;color:var(--ink);text-decoration:none;white-space:nowrap}
.pg-t:hover{color:var(--brand-text);text-decoration:underline}
.pg-u{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:var(--t-sm)}
.pg-k{font-size:var(--t-sm);white-space:nowrap;margin-left:auto}
.mi-note{padding:6px 10px 5px;font-size:var(--t-xs);line-height:1.4;color:var(--ink-2);max-width:280px}
/* phone: the rows are cards (t-stack); the indent of a child page is smaller, the twist and the menu are finger-sized */
@media (max-width:767px){
  .pages-t th,.pages-t td,.pages-t th:first-child,.pages-t td:first-child{padding:0}
  .pages-t th.act,.pages-t td.act{width:auto}
  .pages-t .u{max-width:none;white-space:normal;overflow-wrap:anywhere}
  .tr-ttl{padding-left:calc(var(--l,0)*14px);gap:2px}
  .tr-ttl .twist{width:44px;height:44px;margin:-8px 0 -8px -12px}
  .tr-ttl .sp{width:32px}
  .tr-ttl a{font-size:var(--t-base)}
  .pages-t td.act .btn{margin:0}
  .ft .thumb{width:48px;height:48px}
  .flat-t .lc-chips{flex-direction:row;flex-wrap:wrap}
  .pg-arr{padding:10px}
  .pg-n{flex-wrap:wrap;gap:0 10px}
  .pg-t{white-space:normal}
  .pg-k{margin-left:0}
  .pg-u{flex:1 1 100%}
}
</style>
