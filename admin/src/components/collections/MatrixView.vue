<script setup>
// The options matrix: one record ('main') of a collection with view 'matrix' (a table of tariffs, packages...). Rows = options (text or yes/no), columns = what is compared, plus a price row.
// The words (what a column and a row are called) are the collection's: schema/matrix.js.
// Edits autosave to the draft; magenta marks what differs from the published table; «Опубликовать» lists the changes first.
// The table has a version per LANGUAGE (own prices notes and option names): on a site with 2+ languages the header has the language select, a language without a table
// shows «Версии ещё нет» (copy from the main language; an empty table would have nothing to translate), publish / revert / «Удалить версию» act on the language on screen.
// RIGHTS (area col:<key>): without «Править» the table is read-only (inert, no buttons); without «Публиковать» the button is «Отправить на публикацию» (the version then waits: «Ждёт публикации»,
// and those who may publish get «Вернуть автору» beside «Опубликовать»).
// Every save names the version of the table it found (baseVersion); a table changed meanwhile (an assistant, another person) stops the autosave and EditConflict asks: show the new version or keep my edits.
import { computed, nextTick, onMounted, ref } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router'
import { api } from '@/api/index.js'
import { app, can, refreshCounters, switchContentLocale, useLocaleGuard } from '@/stores/app.js'
import Icon from '@/components/ui/Icon.vue'
import LanguageSelect from '@/components/ui/LanguageSelect.vue'
import StatusChip from '@/components/ui/StatusChip.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ReadOnlyNote from '@/components/ui/ReadOnlyNote.vue'
import EditConflict from '@/components/ui/EditConflict.vue'
import PublishPopover from './PublishPopover.vue'
import { useConfirm } from '@/components/ui/useConfirm.js'
import { useToast } from '@/components/ui/useToast.js'
import { isConflict, useDraftSave } from './useDraftSave.js'
import { matrixChanges, pubVal } from './matrixChanges.js'
import { fill, MATRIX_WORDS, matrixWords } from '@/schema/matrix.js'
import MissingVersion from './MissingVersion.vue'
import { cap, onLang, siteIn, up, verAcc, verGen, verNom, versionState } from '@/utils/langWords.js'
import { carryTab, takeTab } from './lang.js'
import { isMain, isMulti, langName } from '@/utils/locales.js'
import { clone } from '@/utils/objects.js'
import { locale, t } from '@/i18n/index.js'
import { nid } from '@/utils/ids.js'
import { fmtUpdated } from '@/utils/format.js'

const props = defineProps({ cfg: { type: Object, required: true } })
const confirm = useConfirm()
const toast = useToast()
const COL = props.cfg.key
const LOC = app.locale // the language version on screen (the view is re-mounted when the content language changes)
const MULTI = isMulti() // language select and words only on a site with 2+ languages
const IS_MAIN = isMain(LOC)
const ID = props.cfg.singleton || 'main'
const MX = props.cfg.matrix
const W = matrixWords(props.cfg) // what a column and a row are called

const tbl = ref(null) // working copy { cols, rows }
const pub = ref(null) // published { cols, rows } or null
const meta = ref({ status: 'published', updatedAt: null, awaiting: null, returned: null })
const canEdit = computed(() => can('col:' + COL, 'edit'))
const canPublish = computed(() => can('col:' + COL, 'publish'))
const awaiting = computed(() => meta.value.awaiting || null)
const returned = computed(() => meta.value.returned || null) // { by, at } after a publisher sent the table back to the author
const loaded = ref(false)
const failed = ref('')
const translated = ref(true) // false: the table has no version in this language
const summary = ref(null) // api `locales` of the record, for the language select
const langSel = ref(null)
const startBusy = ref('')
// a plural form of a word (words.rowPlural / colPlural: [one, few, many]): the words of the catalogue follow the language of the interface,
// the words of the schema are written by the developer of the site in the language of the site
const pluralRules = (code) => { try { return new Intl.PluralRules(code) } catch { return new Intl.PluralRules(locale) } }
const catalogForms = pluralRules(locale)
const siteForms = pluralRules(app.languages.main)
const catalogWords = Object.values(MATRIX_WORDS)
const formOf = (n, words) => words[{ one: 0, few: 1 }[(catalogWords.includes(words) ? catalogForms : siteForms).select(n)] ?? 2]
// the saver hands over the version the server last named (baseVersion) and takes the new one from the answer
const saver = useDraftSave(async ({ data }, baseVersion) => {
  try {
    const r = await api.collections.update(COL, ID, data, LOC, baseVersion)
    meta.value = { ...meta.value, updatedAt: r.updatedAt }
    return r
  } catch (e) {
    toast.error(isConflict(e) ? t('collections.mx_conflict') : t('collections.draft_not_saved', { reason: e.message || t('collections.error_word') }))
    throw e
  }
})
/**
 * Saves what waits; false (and the reason on screen) when it did not all go through: the table was changed behind our back and the person has not chosen
 * (publishing now would put the other version on the site), or the save failed (publishing now would put the older table on the site).
 */
async function settled() {
  if (await saver.flush()) return true
  toast.error(saver.conflict.value ? t('collections.mx_settle_first') : t('collections.unsaved_first', { reason: saver.error.value || t('collections.error_word') }))
  return false
}
/** Leaving the table: the same, except that a failed save lets the person decide (the edits are lost with the screen). A conflict keeps them here, the banner asks. */
async function mayLeave() {
  if (await saver.flush()) return true
  if (saver.conflict.value) { toast.error(t('collections.mx_settle_first')); return false }
  return confirm({
    title: t('settings.leave_title'), message: t('collections.leave_unsaved_text', { reason: saver.error.value || t('collections.error_word') }),
    confirmLabel: t('settings.leave_confirm'), cancelLabel: t('people.btn_stay'), danger: true,
  })
}
// leaving the table (the list, another section) with edits that were not saved: the person stays or leaves knowingly (false = stay)
onBeforeRouteLeave(mayLeave)
onBeforeRouteUpdate(mayLeave)
/** «Показать новую версию»: the edits on screen are dropped, the table is loaded as it is now. */
async function showNewVersion() {
  working.value = true
  try {
    saver.reset()
    await load()
  } finally {
    working.value = false
  }
}
/** «Оставить мои правки»: the same save over the version the server named. */
async function keepMine() {
  await saver.keepMine()
  if (!saver.conflict.value && saver.state.value === 'saved') toast.show(t('collections.mine_kept'))
}
// the two answers of the banner are not given twice or crosswise while one of them is still on its way
const choosing = ref(false)
async function choose(fn) {
  if (choosing.value) return
  choosing.value = true
  try { await fn() } finally { choosing.value = false }
}

async function load() {
  try {
    const { item, published, locales } = await api.collections.get(COL, ID, LOC)
    summary.value = locales
    translated.value = !!item
    saver.setVersion(item && item.version)
    if (item) {
      tbl.value = { cols: item.cols || [], rows: item.rows || [] }
      pub.value = published ? { cols: published.cols, rows: published.rows } : null
      meta.value = { status: item.status, updatedAt: item.updatedAt, awaiting: item.awaiting || null, returned: item.returned || null }
    } else {
      tbl.value = null
      pub.value = null
    }
    failed.value = ''
  } catch (e) {
    failed.value = e.message || t('collections.mx_load_failed')
  }
  loaded.value = true
}
const carried = takeTab(COL, ID) // a language switch just re-mounted this screen: put the focus back on the language select
onMounted(async () => {
  await load()
  await nextTick()
  if (carried && langSel.value) langSel.value.focus()
})

/* ---------- editing (every edit is a new object and goes to the autosave) ---------- */
function edit(fn) {
  if (!canEdit.value) return
  // while a write that ends in a reload is on its way (publish, submit, return, revert, deleting a version) the table stands still (inert): its answer reloads it, and what was changed meanwhile would be lost. What still reaches here
  // (the «Отменить» of a toast) is refused aloud: applying it over the reloaded table would be a guess at what the person wants published
  if (working.value) { toast.error(t('collections.wait_busy')); return }
  const next = clone(tbl.value)
  fn(next)
  tbl.value = next
  saver.schedule({ data: { cols: next.cols, rows: next.rows } })
}
const setCol = (i, k, v) => edit((m) => { m.cols[i][k] = v })
const setLabel = (ri, v) => edit((m) => { m.rows[ri].label = v })
const setVal = (ri, ci, v) => edit((m) => { m.rows[ri].vals[ci] = v })

async function addRow() {
  const id = nid('r')
  edit((m) => m.rows.push({ _id: id, label: W.rowNew, kind: 'bool', vals: m.cols.map(() => false) }))
  await nextTick()
  const el = document.getElementById('mx-ol-' + id)
  if (el) { el.focus(); el.select() }
}
function moveRow(ri, d) {
  edit((m) => { const r = m.rows.splice(ri, 1)[0]; m.rows.splice(ri + d, 0, r) })
  nextTick(() => {
    const id = tbl.value.rows[ri + d] && tbl.value.rows[ri + d]._id
    const el = id && document.querySelector(`[data-mv="${id}"]:not([disabled])`)
    if (el) el.focus()
  })
}
function removeRow(ri) {
  const row = clone(tbl.value.rows[ri])
  edit((m) => m.rows.splice(ri, 1))
  toast.show(fill(W.rowRemoved, { name: row.label }), { undo: () => edit((m) => m.rows.splice(Math.min(ri, m.rows.length), 0, row)), undoLabel: t('collections.undo') })
}
function toggleKind(ri) {
  edit((m) => {
    const r = m.rows[ri]
    r.kind = r.kind === 'bool' ? 'text' : 'bool'
    r.vals = r.vals.map((v) => (r.kind === 'bool' ? !!v : v === true ? 'Да' : v === false ? '' : v)) // i18n-ignore: the cell is content of the site, not a label of the admin
  })
}
async function addCol() {
  const id = nid('t')
  edit((m) => { m.cols.push({ id, name: W.colNew, price: '', note: '' }); m.rows.forEach((r) => r.vals.push(r.kind === 'bool' ? false : '')) })
  await nextTick()
  const el = document.getElementById('mx-tn-' + id)
  if (el) { el.focus(); el.select(); el.scrollIntoView({ block: 'nearest', inline: 'center' }) }
}
async function removeCol(ci) {
  const c = tbl.value.cols[ci]
  const ok = await confirm({
    title: fill(W.colRemoveAsk, { name: c.name || t('collections.unnamed') }),
    message: W.colRemoveText,
    confirmLabel: t('collections.delete'), danger: true,
  })
  if (!ok) return
  edit((m) => { m.cols.splice(ci, 1); m.rows.forEach((r) => r.vals.splice(ci, 1)) })
}

/* ---------- unpublished marks ---------- */
const changes = computed(() => (tbl.value ? matrixChanges(tbl.value, pub.value, W, MULTI ? t('collections.mx_first_lang', { first: W.first, lang: onLang(LOC) }) : W.first) : []))
const count = computed(() => changes.value.length)
const pubCol = (c) => (pub.value ? pub.value.cols.find((x) => x.id === c.id) : null)
const pubRow = (r) => (pub.value ? pub.value.rows.find((x) => x._id === r._id) : null)
const colChg = (c, k) => !!pub.value && (!pubCol(c) || (pubCol(c)[k] || '') !== (c[k] || ''))
const labelChg = (r) => !!pub.value && (!pubRow(r) || pubRow(r).label !== r.label)
function cellChg(r, c, i) {
  if (!pub.value) return false
  const q = pubRow(r)
  if (!q) return true
  if (q.kind !== r.kind) return true
  const was = pubVal(pub.value, q, c.id)
  return was === undefined || was !== r.vals[i]
}
const chipStatus = computed(() => (!pub.value ? 'draft' : count.value ? 'changed' : 'published'))
const chipLabel = computed(() => (chipStatus.value === 'published' ? t('collections.published') : ''))

/* ---------- publish / revert ---------- */
const pubBtn = ref(null)
const popOpen = ref(false)
const working = ref(false) // a write whose answer reloads the table is on its way (publish, submit, return, revert, delete a version): the table stands still, a late edit would be saved over the new version
// an unnamed column or row would be an empty one on the site: stop before the list of changes
async function onPublishClick() {
  if (popOpen.value) { popOpen.value = false; return }
  const c = tbl.value.cols.find((x) => !String(x.name).trim())
  const r = tbl.value.rows.find((x) => !String(x.label).trim())
  if (c || r) {
    toast.error(c ? W.colNeedName : W.rowNeedName)
    const el = document.getElementById(c ? 'mx-tn-' + c.id : 'mx-ol-' + r._id)
    if (el) { el.scrollIntoView({ block: 'center', inline: 'center' }); el.focus() }
    return
  }
  popOpen.value = true
}
async function doPublish() {
  working.value = true
  try {
    if (!(await settled())) { popOpen.value = false; return }
    await api.collections.publish(COL, ID, LOC)
    saver.reset()
    popOpen.value = false
    await load()
    refreshCounters()
    toast.show(MULTI ? t('collections.version_published', { version: up(verNom(LOC)) }) : t('collections.published'))
  } catch (e) {
    popOpen.value = false
    toast.error(e.message || t('collections.publish_failed'))
  } finally {
    working.value = false
  }
}
/** «Отправить на публикацию» (a role without «Публиковать»): the table waits for somebody who may publish it. */
async function doSubmit() {
  working.value = true
  try {
    if (!(await settled())) { popOpen.value = false; return }
    await api.collections.submit(COL, ID, LOC)
    saver.reset()
    popOpen.value = false
    await load()
    toast.show(t('collections.submitted'))
  } catch (e) {
    popOpen.value = false
    toast.error(e.message || t('collections.submit_failed'))
  } finally {
    working.value = false
  }
}
/** «Вернуть автору»: the table stops waiting; it stays as the author left it. */
async function doReturn() {
  const ok = await confirm({
    title: t('collections.return_title'),
    message: t('collections.mx_return_text'),
    confirmLabel: t('collections.return_to_author'), cancelLabel: t('collections.not_now'),
  })
  if (!ok) return
  working.value = true
  try {
    if (!(await settled())) return
    await api.collections.returnToAuthor(COL, ID, LOC)
    await load()
    toast.show(t('collections.mx_returned'))
  } catch (e) {
    toast.error(e.message || t('collections.return_failed'))
  } finally {
    working.value = false
  }
}
async function doRevert() {
  const ok = await confirm({
    title: MULTI ? t('collections.mx_revert_title_lang', { version: verGen(LOC) }) : t('collections.mx_revert_title'),
    message: MULTI ? t('collections.mx_revert_text_lang', { site: siteIn(LOC) }) : t('collections.mx_revert_text'),
    confirmLabel: t('collections.revert_edits'), danger: true,
  })
  if (!ok) return
  working.value = true
  try {
    if (!(await settled())) return
    await api.collections.revert(COL, ID, LOC)
    saver.reset()
    await load()
    toast.show(t('collections.mx_reverted'))
  } catch (e) {
    toast.error(e.message || t('collections.revert_failed'))
  } finally {
    working.value = false
  }
}

/* ---------- other languages ---------- */
const states = computed(() => {
  const out = {}
  app.languages.list.forEach(({ code }) => {
    if (code === LOC && tbl.value) out[code] = chipStatus.value // 'draft' | 'changed' | 'published'
    else out[code] = versionState(summary.value && summary.value[code])
  })
  return out
})
async function startTranslation() {
  if (startBusy.value) return
  startBusy.value = 'copy'
  try {
    await api.collections.createTranslation(COL, ID, LOC, { from: app.languages.main })
    await load()
    refreshCounters()
    toast.show(t('collections.mx_version_created', { version: up(verNom(LOC)) }))
  } catch (e) {
    toast.error(e.message || t('collections.create_version_failed'))
  } finally {
    startBusy.value = ''
  }
}
async function removeVersion() {
  const ok = await confirm({
    title: t('collections.mx_delete_version_title', { version: verAcc(LOC) }),
    message: t('collections.mx_delete_version_text', { site: siteIn(LOC) }),
    confirmLabel: cap(t('collections.delete_version', { version: verAcc(LOC) })), danger: true,
  })
  if (!ok) return
  working.value = true
  try {
    popOpen.value = false
    saver.reset()
    await api.collections.removeTranslation(COL, ID, LOC)
    await load()
    refreshCounters()
    toast.show(t('collections.mx_version_deleted', { version: up(verNom(LOC)) }))
  } catch (e) {
    toast.error(e.message || t('collections.delete_version_failed'))
  } finally {
    working.value = false
  }
}
// the select and the rail switcher are the same switch: this version's edits are saved under its own language before the screen is replaced
useLocaleGuard(async () => {
  popOpen.value = false
  if (!(await mayLeave())) return false
  carryTab(COL, ID, '')
  return true
})
const pickLang = (code) => switchContentLocale(code)

/* ---------- small helpers ---------- */
// a textarea that grows with its text (field-sizing is not in every browser)
const fit = (el) => { el.style.height = 'auto'; el.style.height = el.scrollHeight + 2 + 'px' }
const vFit = { mounted: fit, updated: fit }
const saveText = computed(() => ({ dirty: t('collections.saving'), saving: t('collections.saving'), saved: t('collections.saved_to_draft'), error: t('collections.not_saved'), idle: '' }[saver.state.value]))
</script>

<template>
  <div class="page cl-mx-page">
    <div class="page-h">
      <div class="cl-hd">
        <div class="cl-h1">
          <h1>{{ cfg.title }}</h1>
          <LanguageSelect v-if="MULTI && summary" ref="langSel" :model-value="LOC" :languages="app.languages.list" :statuses="states" :label="t('collections.mx_language')" @update:model-value="pickLang" />
        </div>
        <p class="sub">{{ W.about }}<template v-if="MULTI"> {{ t('collections.mx_per_language') }}</template></p>
      </div>
      <div v-if="loaded && !tbl && !failed" class="actions"><StatusChip status="missing" :label="t('collections.state_missing')" /></div>
      <div v-else-if="tbl" class="actions">
        <span v-if="canEdit" class="cl-save" :class="saver.state.value" role="status" aria-live="polite">
          <template v-if="saver.state.value === 'saved'"><Icon name="check" size="s14" /></template>{{ saveText }}
        </span>
        <StatusChip :status="chipStatus" :label="chipLabel" />
        <StatusChip v-if="awaiting" status="warn" :label="t('collections.awaiting')" :title="t('collections.sent_for_publishing_by', { by: awaiting.by })" />
        <button v-if="canEdit && pub && count" type="button" class="btn ghost" @click="doRevert"><Icon name="undo" size="s16" />{{ t('collections.revert_edits') }}</button>
        <button v-if="canEdit && canPublish && awaiting" type="button" class="btn" @click="doReturn">{{ t('collections.return_to_author') }}</button>
        <button
          v-if="canEdit" ref="pubBtn" type="button" class="btn primary" :disabled="!count || (!canPublish && !!awaiting)" :title="canPublish && MULTI ? cap(t('collections.pp_publish_version', { version: verAcc(LOC) })) : undefined"
          :aria-expanded="popOpen" aria-haspopup="dialog" @click="onPublishClick"
        >{{ canPublish ? t('collections.publish') : awaiting ? t('collections.awaiting') : t('collections.pp_send') }}<span v-if="count && (canPublish || !awaiting)" class="cnt">{{ count }}</span></button>
      </div>
    </div>

    <div v-if="!loaded" class="sheet" style="padding:18px 22px"><Skeleton :rows="7" /></div>
    <div v-else-if="failed" class="sheet"><EmptyState icon="alert" :title="t('collections.mx_load_error_title')" :text="failed"><button type="button" class="btn" @click="load">{{ t('collections.mx_retry') }}</button></EmptyState></div>

    <div v-else-if="!tbl" class="sheet cl-miss">
      <MissingVersion v-if="canEdit" :cfg="cfg" :locale="LOC" :busy="startBusy" @copy="startTranslation" />
      <EmptyState v-else icon="globe" :title="t('collections.mx_missing_title')" :text="t('collections.mx_missing_text')" />
    </div>

    <EditConflict v-if="loaded && tbl && saver.conflict.value" class="cl-wait" what="table" :current="saver.conflict.value" :busy="choosing" @show="choose(showNewVersion)" @keep="choose(keepMine)" />
    <ReadOnlyNote v-if="loaded && tbl && !canEdit" :what="W.what" />
    <div v-if="loaded && tbl && canEdit && awaiting" class="alert warn-a cl-wait" role="status">
      <Icon name="clock" size="s16" />
      <span v-if="canPublish"><b>{{ t('collections.awaiting_label') }}</b> {{ t('collections.mx_awaiting_publisher', { by: awaiting.by, when: fmtUpdated(awaiting.at) }) }}</span>
      <span v-else><b>{{ t('collections.awaiting_label') }}</b> {{ app.user && awaiting.by === app.user.name ? t('collections.awaiting_mine', { when: fmtUpdated(awaiting.at) }) : t('collections.awaiting_other', { by: awaiting.by, when: fmtUpdated(awaiting.at) }) }}</span>
    </div>
    <div v-else-if="loaded && tbl && canEdit && returned && !canPublish" class="alert warn-a cl-wait" role="status">
      <Icon name="undo" size="s16" />
      <span><b>{{ t('collections.returned_label') }}</b> {{ t('collections.returned_rest', { by: returned.by, when: fmtUpdated(returned.at) }) }}</span>
    </div>

    <div v-if="loaded && tbl && !failed" class="sheet">
      <div class="mx-wrap">
        <table :class="['mx', { 'ro-zone': !canEdit }]" :inert="!canEdit || working" :aria-label="W.ariaLabel">
          <thead>
            <tr>
              <th class="opt">{{ W.corner }}</th>
              <th v-for="(c, i) in tbl.cols" :key="c.id" :class="{ chg: colChg(c, 'name') || colChg(c, 'note') }" class="cl-th">
                <div class="tcol-h">
                  <div class="cl-th-r">
                    <label class="sr" :for="'mx-tn-' + c.id">{{ W.colName }} {{ i + 1 }}</label>
                    <input :id="'mx-tn-' + c.id" type="text" class="cl-tn" :value="c.name" :maxlength="MX.colFields[0].max" :aria-invalid="!String(c.name).trim() ? 'true' : undefined" :placeholder="t('collections.mx_name_ph')" @input="setCol(i, 'name', $event.target.value)">
                    <button type="button" class="btn ghost icon sm" :disabled="tbl.cols.length <= 1" :aria-label="`${W.colRemove}: ${c.name || i + 1}`" :title="W.colRemove" @click="removeCol(i)"><Icon name="trash" size="s14" /></button>
                  </div>
                  <label class="sr" :for="'mx-tt-' + c.id">{{ W.colNote }} {{ c.name }}</label>
                  <input :id="'mx-tt-' + c.id" type="text" class="cl-tnote" :value="c.note" :maxlength="MX.colFields[2].max" :placeholder="t('collections.mx_note_ph')" @input="setCol(i, 'note', $event.target.value)">
                </div>
              </th>
              <th class="cl-ord">{{ t('collections.mx_order') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td class="opt">
                <b style="font-weight:620">{{ W.priceLabel }}</b>
                <div v-if="W.priceHint" class="muted" style="font-size:var(--t-xs)">{{ W.priceHint }}</div>
              </td>
              <td v-for="(c, i) in tbl.cols" :key="c.id" :class="['cell', { chg: colChg(c, 'price') }]">
                <label class="sr" :for="'mx-tp-' + c.id">{{ t('collections.mx_price_label', { label: W.priceLabel, name: c.name }) }}</label>
                <input :id="'mx-tp-' + c.id" type="text" inputmode="numeric" class="mx-price" :value="c.price" :maxlength="MX.colFields[1].max" :placeholder="W.priceEmpty" @input="setCol(i, 'price', $event.target.value)">
              </td>
              <td />
            </tr>
            <tr class="cg"><td class="opt" :colspan="tbl.cols.length + 2" style="position:static"><span class="cl-cg">{{ W.group }}</span></td></tr>
            <tr v-for="(r, ri) in tbl.rows" :key="r._id">
              <td :class="['opt', { chg: labelChg(r) }]">
                <div class="opt-c">
                  <label class="sr" :for="'mx-ol-' + r._id">{{ W.rowName }}</label>
                  <textarea :id="'mx-ol-' + r._id" v-fit rows="1" :value="r.label" :maxlength="MX.rowLabel.max" :aria-invalid="!String(r.label).trim() ? 'true' : undefined" :placeholder="W.rowName" @input="setLabel(ri, $event.target.value)" />
                  <button
                    type="button" class="btn ghost sm cl-kind" :title="r.kind === 'bool' ? t('collections.mx_kind_to_text') : t('collections.mx_kind_to_bool')"
                    :aria-label="r.kind === 'bool' ? t('collections.mx_kind_aria_bool') : t('collections.mx_kind_aria_text')" @click="toggleKind(ri)"
                  >{{ r.kind === 'bool' ? t('collections.mx_kind_bool') : t('collections.mx_kind_text') }}</button>
                </div>
              </td>
              <td v-for="(c, i) in tbl.cols" :key="c.id" :class="['cell', { chg: cellChg(r, c, i) }]">
                <label v-if="r.kind === 'bool'" class="sw">
                  <input type="checkbox" :checked="!!r.vals[i]" @change="setVal(ri, i, $event.target.checked)">
                  <span class="tr" /><span class="sr">{{ r.label }}: {{ c.name }}</span>
                </label>
                <template v-else>
                  <label class="sr" :for="`mx-c-${r._id}-${c.id}`">{{ r.label }}: {{ c.name }}</label>
                  <input :id="`mx-c-${r._id}-${c.id}`" type="text" :value="r.vals[i]" @input="setVal(ri, i, $event.target.value)">
                </template>
              </td>
              <td>
                <span class="cl-mv">
                  <button type="button" class="btn ghost icon sm" :data-mv="r._id" :disabled="ri === 0" :aria-label="t('collections.mx_move_up', { label: r.label })" @click="moveRow(ri, -1)"><Icon name="up" size="s14" /></button>
                  <button type="button" class="btn ghost icon sm" :data-mv="r._id" :disabled="ri === tbl.rows.length - 1" :aria-label="t('collections.mx_move_down', { label: r.label })" @click="moveRow(ri, 1)"><Icon name="down" size="s14" /></button>
                  <button type="button" class="btn ghost icon sm" :aria-label="t('collections.mx_remove_row', { label: r.label })" @click="removeRow(ri)"><Icon name="trash" size="s14" /></button>
                </span>
              </td>
            </tr>
            <tr v-if="!tbl.rows.length"><td class="opt" :colspan="tbl.cols.length + 2" style="position:static;text-align:center" ><span class="muted">{{ W.rowNone }}</span></td></tr>
          </tbody>
        </table>
      </div>
      <div :class="['mx-notes', { 'ro-zone': !canEdit }]" :inert="!canEdit || working">
        <button type="button" class="btn sm" @click="addRow"><Icon name="plus" size="s14" />{{ W.rowAdd }}</button>
        <button type="button" class="btn sm" @click="addCol"><Icon name="plus" size="s14" />{{ W.colAdd }}</button>
        <span>{{ tbl.rows.length }} {{ formOf(tbl.rows.length, W.rowPlural) }} × {{ tbl.cols.length }} {{ formOf(tbl.cols.length, W.colPlural) }}</span>
        <span v-if="meta.updatedAt" class="muted">{{ t('collections.changed_at', { date: fmtUpdated(meta.updatedAt) }) }}</span>
        <span class="cl-leg"><span class="pmark" aria-hidden="true"><i /></span>{{ t('collections.mx_legend') }}</span>
      </div>
      <div v-if="MULTI && !IS_MAIN && canEdit" class="cl-foot">
        <button type="button" class="btn danger sm" @click="removeVersion"><Icon name="trash" size="s14" />{{ cap(t('collections.delete_version', { version: verAcc(LOC) })) }}</button>
        <span class="muted">{{ t('collections.mx_foot', { lang: langName(app.languages.main) }) }}</span>
      </div>
    </div>

    <PublishPopover v-model:open="popOpen" :anchor="pubBtn" :changes="changes" :busy="working" :subject="W.subject" :limit="10" :lang-code="MULTI ? LOC : ''" :send="!canPublish" @confirm="canPublish ? doPublish() : doSubmit()" />
  </div>
</template>

<style scoped>
.cl-mx-page { max-width: 1320px; }
.cl-hd { min-width: 240px; flex: 1 1 0; }
.cl-h1 { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; } /* the language select sits under the heading, as on every record screen */
.cl-miss { padding: 0; }
.cl-wait { margin: 0 0 14px; }
.cl-foot { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; padding: 12px 22px 16px; border-top: 1px solid var(--hair-2); font-size: var(--t-sm); }
.cl-cg { position: sticky; left: 12px; display: inline-block; }
.cl-save { font-size: var(--t-sm); color: var(--ink-2); display: inline-flex; align-items: center; gap: 5px; min-height: 20px; }
.cl-save.saved { color: var(--green); }
.cl-save.error { color: var(--red); font-weight: 600; }
.cl-th { min-width: 170px; }
table.mx thead th.chg { background: var(--mag-50); }
.cl-th-r { display: flex; gap: 2px; align-items: center; }
.cl-tn { font-weight: 650; height: 32px; }
.cl-tnote { height: 26px; font-size: var(--t-xs); border-color: transparent; background: transparent; padding: 0 10px; color: var(--ink-2); }
.cl-tnote:hover { border-color: var(--ctl); background: #fff; }
.cl-tnote:focus { border-color: var(--cy); background: #fff; }
.cl-ord { width: 130px; }
.mx input[aria-invalid="true"], .mx textarea[aria-invalid="true"] { border-color: var(--red); background: #FFFAFA; }
.cl-mv { display: flex; gap: 0; }
.cl-kind { width: auto; padding: 0 8px; font-size: var(--t-xs); color: var(--ink-2); flex: none; }
.cl-leg { display: inline-flex; gap: 6px; align-items: center; margin-left: auto; }
</style>
