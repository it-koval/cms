<script setup>
// Menus of the site: one section per MENU AREA (header, footers, legal links...; the areas come from the `areas` prop). An area is a place for a menu: it has a snippet that the developers insert into a site template
// ({{ itk_menu('footer_main') }}, with «Скопировать»), the list of templates it is inserted in («Пока нигде не вставлена» when none), and can be added, renamed and deleted (saved at once: structure, not content).
// The items of an area: each links to a page or an address; items can be moved up/down and nested one level (a nested item belongs to the item above it).
// Edits a plain object; saving the ITEMS is the view's job. After an area changed the view gets `areas` (fresh list) so it can keep its saved / draft menus in step.
// LANGUAGE: the menus belong to the content language app.locale. The page picker lists only the pages that have a version in that language
// (their addresses carry the prefix: /en/about-us), and an item that leads to a page which is not published in that language is flagged in amber.
// A language whose menu is still empty can take the items of the main language's menu (links are mapped to the translated pages).
import { computed, onMounted, ref } from 'vue'
import { api } from '@/api/index.js'
import { app, can } from '@/stores/app.js'
import { isMain, isMulti, prefix } from '@/utils/locales.js'
import { useToast } from '@/components/ui/useToast.js'
import Icon from '@/components/ui/Icon.vue'
import StatusChip from '@/components/ui/StatusChip.vue'
import MenuRow from './MenuRow.vue'
import { areaKind, flagCount, newMenuItem, pageFlag } from './menuModel.js'
import MenuAreaDialog from './MenuAreaDialog.vue'
import { SNIPPET_HINT } from '@/schema/menuAreas.js'
import { useConfirm } from '@/components/ui/useConfirm.js'
import { onLang } from '@/utils/langWords.js'
import { same, clone } from '@/utils/objects.js'
import { t } from '@/i18n/index.js'
import { nid } from '@/utils/ids.js'
import SortableTree from '@/components/ui/SortableTree.vue'
import * as T from '@/components/ui/treeOps.js'

const props = defineProps({
  modelValue: { type: Object, required: true },
  saved: { type: Object, required: true },
  areas: { type: Array, default: () => [] }, // [{ key, name, depth }] menu areas of the site
})
const emit = defineEmits(['update:modelValue', 'areas'])
const toast = useToast()
const confirm = useConfirm()
const LOC = app.locale
const MAIN = app.languages.main

const rows = ref([]) // pages of the site (flat rows of the content language)
const ready = ref(false)
const pagesFailed = ref(false)
const mainMenus = ref(null) // the saved menus of the main language, for «Скопировать …» (only when another language is open)

async function loadPages() {
  if (!can('pages')) return
  pagesFailed.value = false
  try {
    rows.value = await api.pages.list({ locale: LOC })
    ready.value = true
  } catch {
    pagesFailed.value = true
  }
}

onMounted(() => {
  loadPages()
  if (!isMain(LOC)) api.settings.getMenus(MAIN).then((menus) => { mainMenus.value = menus }).catch(() => {})
})

const pages = computed(() => rows.value.filter((p) => p.translated).map((p) => ({ value: p.url, label: p.title, draft: p.status !== 'published' })))
const on = computed(() => (isMulti() ? onLang(LOC) : ''))
const flagOf = (it) => (ready.value ? pageFlag(it, pages.value, on.value) : '')
const flagsIn = (key) => (ready.value ? flagCount(list(key), pages.value, on.value) : 0)
const noPagesText = computed(() => (isMulti() ? t('settings.menu_no_pages_lang', { lang: on.value }) : t('settings.menu_no_pages')))

const list = (key) => props.modelValue[key] || []
const setList = (key, arr) => emit('update:modelValue', { ...props.modelValue, [key]: arr })
const groupChanged = (key) => !same(list(key), props.saved[key] || [])

// the items are a tree of two levels at most (a.depth); SortableTree + treeOps do the moving, the rest is editing one item
const treeOpts = (a) => ({ id: '_id', maxDepth: a.depth })
const mapItem = (arr, id, fn) => arr.map((x) => (x._id === id ? fn(x) : (x.children || []).length ? { ...x, children: mapItem(x.children, id, fn) } : x))
const patchItem = (key, id, p) => setList(key, mapItem(list(key), id, (x) => ({ ...x, ...p })))
function stepItem(a, id, dir) {
  const r = T.step(list(a.key), id, dir, treeOpts(a))
  if (r.ok && !r.same) setList(a.key, r.nodes)
  else if (!r.ok) toast.error(r.reason)
}
const canStep = (a, id, dir) => T.canStep(list(a.key), id, dir, treeOpts(a)).ok
function indentHint(a, it) {
  if (a.depth < 2) return t('settings.menu_no_nesting')
  if ((it.children || []).length) return t('settings.menu_unnest_first')
  return T.canStep(list(a.key), it._id, 'in', treeOpts(a)).reason
}
function remove(key, id) {
  const before = list(key)
  const at = T.find(before, id, { id: '_id' })
  if (!at) return
  setList(key, T.drop(before, id, { id: '_id' }))
  const nested = (at.node.children || []).length
  const label = at.node.label || t('settings.menu_item_fallback')
  toast.show(nested ? t('settings.menu_item_removed_nested', { label, nested }) : t('settings.menu_item_removed', { label }), { undoLabel: t('settings.undo'), undo: () => setList(key, before) })
}
// a new item leads to the home page OF THIS LANGUAGE (the main language's home is '/', the English one '/en'), else to the first page there is
function add(key) {
  const home = pages.value.find((p) => p.value === prefix('/', LOC))
  setList(key, [...list(key), newMenuItem(home ? home.value : (pages.value[0] || {}).value || '')])
}

/* ---- areas: add / rename / delete / copy the snippet ---- */
const dlgOpen = ref(false)
const dlgArea = ref(null)
const openDialog = (area = null) => { dlgArea.value = area; dlgOpen.value = true }
async function refreshAreas(extra = {}) { emit('areas', await api.settings.getMenuAreas(), extra) }
async function onAreaSaved({ area, flattened }) {
  try { await refreshAreas(flattened ? { flattened: area.key } : {}) } catch (e) { toast.error(e.message) }
}
async function removeArea(a) {
  const used = a.usedIn.length
  const ok = await confirm({
    title: t('settings.area_delete_title', { name: a.name }),
    message: [
      Object.values(a.counts || {}).some((n) => n) ? (isMulti() ? t('settings.area_delete_items_langs') : t('settings.area_delete_items')) : '',
      used
        ? (a.usedInAll ? t('settings.area_delete_used_all') : t('settings.area_delete_used_some', { templates: a.usedIn.map((tpl) => t('settings.quoted', { text: tpl.name })).join(', ') }))
        : t('settings.area_delete_unused'),
    ].filter(Boolean).join(' '),
    confirmLabel: t('settings.area_delete_confirm'), danger: true,
  })
  if (!ok) return
  try {
    await api.settings.removeMenuArea(a.key)
    await refreshAreas()
    toast.show(t('settings.area_deleted', { name: a.name }))
  } catch (e) { toast.error(e.message) }
}
async function copySnippet(a) {
  try {
    if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(a.snippet)
    else {
      const box = document.createElement('textarea')
      box.value = a.snippet; box.style.position = 'fixed'; box.style.opacity = '0'
      document.body.appendChild(box); box.select(); document.execCommand('copy'); box.remove()
    }
    toast.show(t('settings.snippet_copied', { snippet: a.snippet }))
  } catch (e) { toast.error(t('settings.snippet_copy_failed')) }
}
const usedText = (a) => (a.usedInAll ? t('settings.area_used_all') : t('settings.area_used_some', { templates: a.usedIn.map((tpl) => tpl.name).join(', ') }))

/* ---- «Скопировать из меню на русском» ---- */
const sourceItems = (key) => mainMenus.value?.[key] || []
const pointsToPage = (item) => item.link?.kind === 'page'
const needsPageMap = (key) => sourceItems(key).some((item) => pointsToPage(item) || item.children?.some(pointsToPage))
const canCopy = (key) => !isMain(LOC) && !list(key).length && sourceItems(key).length > 0 && (ready.value || !needsPageMap(key))
// the page of this language that is the translation of the main language's page at `url` ('' when it has none yet)
function mapPage(url) {
  const r = rows.value.find((x) => x.locales && x.locales[MAIN] && x.locales[MAIN].url === url)
  return r && r.translated ? r.url : ''
}
function copyItem(it) {
  const out = { ...clone(it), _id: nid('mi') }
  if (out.link && out.link.kind === 'page') out.link = { ...out.link, page: mapPage(out.link.page) }
  if (it.children) out.children = it.children.map(copyItem)
  return out
}
function copyFromMain(key) {
  if (!canCopy(key)) return
  const items = sourceItems(key).map(copyItem)
  setList(key, items)
  const gaps = items.reduce((n, it) => n + (it.link.kind === 'page' && !it.link.page ? 1 : 0) + (it.children || []).filter((c) => c.link.kind === 'page' && !c.link.page).length, 0)
  toast.show(gaps ? t('settings.menu_copied_gaps', { count: gaps }) : t('settings.menu_copied'))
}
</script>

<template>
  <div>
    <div class="ar-intro">
      <p>{{ t('settings.menus_intro') }}</p>
      <button type="button" class="btn" @click="openDialog()"><Icon name="plus" size="s16" />{{ t('settings.menus_add_area') }}</button>
    </div>
    <div v-if="pagesFailed" class="alert err-a mn-page-load" role="alert">
      <span>{{ t('settings.menu_pages_load_failed') }}</span>
      <button type="button" class="btn sm" @click="loadPages">{{ t('settings.retry') }}</button>
    </div>

    <section v-for="a in areas" :key="a.key" class="sheet set-sec">
      <h2 :id="'menu-' + a.key">{{ a.name }}
        <span v-if="groupChanged(a.key)" class="mn-chg"><span class="pmark" aria-hidden="true"><i /></span>{{ t('settings.changed') }}</span>
        <StatusChip v-if="flagsIn(a.key)" status="warn" :label="t('settings.menu_hidden_count', { n: flagsIn(a.key) })" />
        <span class="ar-acts">
          <button type="button" class="btn ghost sm" :aria-label="t('settings.area_rename_aria', { name: a.name })" @click="openDialog(a)"><Icon name="edit" size="s14" />{{ t('settings.rename') }}</button>
          <button type="button" class="btn ghost sm ar-del" :aria-label="t('settings.area_delete_aria', { name: a.name })" @click="removeArea(a)"><Icon name="trash" size="s14" />{{ t('settings.delete') }}</button>
        </span>
      </h2>
      <p class="d">{{ areaKind(a) }}</p>
      <div class="ar-info">
        <div class="ar-snip">
          <code :title="SNIPPET_HINT">{{ a.snippet }}</code>
          <button type="button" class="btn sm" :aria-label="t('settings.area_copy_aria', { name: a.name })" @click="copySnippet(a)"><Icon name="copy" size="s14" />{{ t('settings.copy') }}</button>
          <span class="ar-hint">{{ SNIPPET_HINT }}</span>
        </div>
        <div :class="['ar-use', { none: !a.usedIn.length }]"><Icon :name="a.usedIn.length ? 'check' : 'info'" size="s14" /><span>{{ a.usedIn.length ? usedText(a) : t('settings.area_unused') }}</span></div>
      </div>
      <div class="mn-list">
        <SortableTree
          v-if="list(a.key).length" :nodes="list(a.key)" id-key="_id" :max-depth="a.depth" :tools="false" :foldable="false" bare :aria-label="a.name"
          @move="setList(a.key, $event.nodes)" @invalid="toast.error"
        >
          <template #default="{ node, level }">
            <MenuRow
              :id-base="`mn-${a.key}-${node._id}`" :item="node" :child="level > 1" :pages="pages" :flag="flagOf(node)" :no-pages-text="noPagesText"
              :can-up="canStep(a, node._id, 'up')" :can-down="canStep(a, node._id, 'down')" :can-indent="canStep(a, node._id, 'in')" :indent-hint="indentHint(a, node)"
              @patch="patchItem(a.key, node._id, $event)" @up="stepItem(a, node._id, 'up')" @down="stepItem(a, node._id, 'down')" @indent="stepItem(a, node._id, 'in')" @outdent="stepItem(a, node._id, 'out')" @remove="remove(a.key, node._id)"
            />
          </template>
        </SortableTree>
        <div v-else class="mn-empty">
          <Icon name="info" size="s16" />
          <span>{{ t('settings.menu_empty') }}</span>
          <button v-if="canCopy(a.key)" type="button" class="btn sm mn-copy" @click="copyFromMain(a.key)"><Icon name="copy" size="s14" />{{ t('settings.menu_copy_from', { lang: onLang(MAIN) }) }}</button>
        </div>
      </div>
      <button type="button" class="btn sm mn-add" @click="add(a.key)"><Icon name="plus" size="s14" />{{ t('settings.menu_add_item') }}</button>
    </section>

    <div v-if="!areas.length" class="sheet set-sec ar-none">
      <Icon name="info" size="s16" /><span>{{ t('settings.areas_none') }}</span>
    </div>

    <MenuAreaDialog v-model:open="dlgOpen" :area="dlgArea" :taken="areas.map((x) => x.key)" @saved="onAreaSaved" />
  </div>
</template>

<style scoped>
.mn-list{margin:10px 0 4px}
.mn-list :deep(.st-item){padding-left:calc(var(--lv)*34px)}
.mn-add{margin:6px 0 10px}
.mn-empty{display:flex;gap:8px;align-items:center;color:var(--ink-2);font-size:var(--t-sm);padding:10px 12px;border:1px dashed var(--ctl);border-radius:var(--r)}
.mn-copy{margin-left:auto}
.mn-page-load{margin-bottom:12px;flex-wrap:wrap}
.mn-chg{display:inline-flex;align-items:center;gap:6px;font-size:var(--t-xs);font-weight:620;color:var(--accent-text-d)}
.ar-intro{display:flex;align-items:center;gap:16px;margin-bottom:14px}
.ar-intro p{flex:1;color:var(--ink-2);font-size:var(--t-sm);line-height:1.5;max-width:64ch}
.ar-acts{margin-left:auto;display:flex;gap:2px;font-weight:550}
.ar-del{color:var(--ink-2)}
.ar-del:hover{background:var(--red-50);color:var(--red)}
.ar-info{display:flex;flex-direction:column;gap:7px;margin:2px 0 10px;padding:10px 12px;border-radius:var(--r);background:var(--sheet-2);border:1px solid var(--hair)}
.ar-snip{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.ar-snip code{padding:4px 9px;border-radius:5px;background:#fff;border:1px solid var(--ctl);font:13px/1.4 ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--ink)}
.ar-hint{font-size:var(--t-xs);color:var(--ink-2)}
.ar-use{display:flex;align-items:flex-start;gap:7px;font-size:var(--t-sm);color:var(--ink);line-height:1.4}
.ar-use .ic{flex:none;margin-top:2px;color:var(--brand-text)}
.ar-use.none{color:var(--ink-2)}
.ar-use.none .ic{color:var(--ink-3)}
.ar-none{display:flex;gap:8px;align-items:center;color:var(--ink-2);font-size:var(--t-sm)}
@media (max-width:767px){
  .mn-list :deep(.st-item){padding-left:calc(var(--lv)*14px)}
  .ar-intro{flex-direction:column;align-items:stretch;gap:10px}
  .ar-intro .btn,.mn-add{align-self:stretch}
  .set-sec>h2{flex-wrap:wrap}
  .ar-acts{margin-left:0;flex:1 1 100%}
  .ar-snip code{flex:1 1 100%;overflow-x:auto}
  .mn-empty{flex-wrap:wrap}
  .mn-copy{margin-left:0}
}
</style>
