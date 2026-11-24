<script setup>
// Заявки: list with filters (phone, status, form, period) and a drawer per request (route /requests/:id).
// The work status (New / In progress / Closed / Spam), the private note and the delivery to the messengers
// state are all fields of the request (api.requests); «new» is the work status «Новая», for the badge, the bold rows and the filter alike.
// VIEWS: «Таблица» and «Доска» (columns Новая / В работе / Закрыта; a card dragged to another column, or moved with its arrows, changes the work status of the request — the same update as in the drawer).
// The choice is kept for the person on the server (api.prefs, «requests.view»). Spam is not on the board (it is not work), the board says how many are hidden.
// On a site with 2+ languages the list has a «Язык» column (the language version of the site the request came from) and a «Язык» filter; a one-language site has neither.
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api/index.js'
import { app, can, refreshCounters } from '@/stores/app.js'
import Icon from '@/components/ui/Icon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import RequestDrawer from '@/components/requests/RequestDrawer.vue'
import LangTag from '@/components/ui/LangTag.vue'
import WorkChip from '@/components/requests/WorkChip.vue'
import KanbanBoard from '@/components/ui/KanbanBoard.vue'
import FilterSheet from '@/components/ui/FilterSheet.vue'
import ListPager from '@/components/collections/list/ListPager.vue'
import { useToast } from '@/components/ui/useToast.js'
import { WORK_STATUSES, deliveryFailed, isNewRequest, workLabel, workOf } from '@/composables/requestMeta.js'
import { dayOf, fmtDT } from '@/utils/format.js'
import { t } from '@/i18n/index.js'
import { isMulti, langName } from '@/utils/locales.js'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const MULTI = isMulti()
const f = reactive({ q: '', status: '', form: '', locale: '', period: '', from: '', to: '' })
// deep link from «Сегодня»: /requests?status=failed
if (typeof route.query.status === 'string' && [...WORK_STATUSES.map((s) => s[0]), 'failed'].includes(route.query.status)) f.status = route.query.status
const rows = ref([])
const total = ref(0)
const page = ref(1)
const perPage = ref(50)
const forms = ref([])
const loading = ref(true)
const loadError = ref('')
const today = dayOf(new Date())

const visible = rows
const filtersOn = computed(() => !!(f.q || f.status || f.form || f.locale || f.period))
// how many of the filters behind «Фильтры» (phone) are on; the search stays in view
const activeCount = computed(() => [f.status, f.form, f.locale, f.period].filter(Boolean).length)
const badRange = computed(() => f.period === 'custom' && f.from && f.to && f.from > f.to)
const openId = computed(() => (route.params.id ? Number(route.params.id) : null))

let seq = 0
async function load() {
  const my = ++seq
  loading.value = true
  try {
    const list = await api.requests.list({ ...f, page: page.value, perPage: perPage.value })
    if (my !== seq) return // a newer request is on its way
    const last = Math.max(1, Math.ceil(list.total / list.perPage))
    if (list.page > last) return go(last)
    rows.value = list.items
    total.value = list.total
    loadError.value = ''
  } catch (e) {
    if (my === seq) loadError.value = e.message || t('ui.rq_load_failed')
  } finally {
    if (my === seq) loading.value = false
  }
}

function go(next) {
  clearTimeout(timer)
  page.value = next
  return load()
}
function setPerPage(size) {
  perPage.value = size
  go(1)
}

// the view (table / board) is kept for the person; until it is read the table shows
const view = ref('table')
const VIEW_KEY = 'requests.view'
onMounted(async () => {
  forms.value = await api.requests.forms().catch(() => [])
  const saved = await api.prefs.get(VIEW_KEY)
  if (saved === 'board' || saved === 'table') view.value = saved
  await load()
})
function setView(v) {
  view.value = v
  api.prefs.put(VIEW_KEY, v).catch(() => { /* not kept: the screen works all the same */ })
}

// the board: the three working columns; a request moves between them (work status), the order inside a column is by time
const BOARD = WORK_STATUSES.filter((s) => s[0] !== 'spam').map(([value, label]) => ({ value, label }))
const board = computed(() => visible.value.filter((r) => workOf(r) !== 'spam'))
const spamHidden = computed(() => visible.value.length - board.value.length)
const boardBusy = ref(false)
const canEdit = computed(() => can('requests', 'edit'))
async function moveCard({ item, to }) {
  if (!canEdit.value || boardBusy.value) return
  const before = rows.value
  rows.value = rows.value.map((r) => (r.id === item.id ? { ...r, work: to } : r))
  boardBusy.value = true
  try {
    await api.requests.update(item.id, { work: to })
    await onChanged()
    toast.show(t('ui.rq_moved', { id: item.id, status: workLabel(to) }), { undoLabel: t('ui.toast_return'), undo: async () => { try { await api.requests.update(item.id, { work: workOf(item) }); await onChanged() } catch (e) { toast.error(e.message) } } })
  } catch (e) {
    rows.value = before
    toast.error(e.message || t('ui.rq_move_failed'))
  } finally {
    boardBusy.value = false
  }
}

let timer = null
watch(() => f.q, () => { ++seq; clearTimeout(timer); timer = setTimeout(() => go(1), 220) })
watch(() => [f.status, f.form, f.locale, f.period, f.from, f.to], () => go(1))
onBeforeUnmount(() => { ++seq; clearTimeout(timer) })

function reset() { Object.assign(f, { q: '', status: '', form: '', locale: '', period: '', from: '', to: '' }) }

const open = (r) => router.push({ name: 'requests', params: { id: r.id } })
const closeDrawer = () => router.push({ name: 'requests' })
async function onChanged() {
  await Promise.all([load(), refreshCounters()])
}
const countText = computed(() => t('ui.rq_count', { count: visible.value.length, total: total.value }))
// the name of a row for screen readers: «Заявка № 12, +7…, новая, English»
const rowLabel = (r) => t('ui.rq_card_label', { id: r.id, phone: r.phone }) + (isNewRequest(r) ? ', ' + t('ui.rq_row_new') : '') + (MULTI ? ', ' + langName(r.locale) : '')
</script>

<template>
  <div class="page">
    <div class="page-h">
      <div>
        <h1>{{ t('ui.rq_title') }}</h1>
        <p class="sub">{{ t('ui.rq_sub') }}</p>
      </div>
    </div>

    <div class="sheet">
      <div :class="['toolbar', { 'rq-multi': MULTI }]">
        <div class="search">
          <label class="sr" for="rq-q">{{ t('ui.rq_search_label') }}</label>
          <Icon name="search" size="s16" />
          <input id="rq-q" v-model="f.q" type="search" :placeholder="t('ui.rq_phone')" inputmode="tel" autocomplete="off">
        </div>
        <FilterSheet :count="activeCount" @reset="reset">
        <select v-model="f.status" :aria-label="t('ui.rq_status')">
          <option value="">{{ t('ui.rq_any_status') }}</option>
          <option v-for="s in WORK_STATUSES" :key="s[0]" :value="s[0]">{{ s[1] }}</option>
          <option value="failed">{{ t('ui.rq_failed') }}</option>
        </select>
        <select v-model="f.form" :aria-label="t('ui.rq_form')">
          <option value="">{{ t('ui.rq_any_form') }}</option>
          <option v-for="p in forms" :key="p.key" :value="p.key">{{ p.title }}</option>
        </select>
        <select v-if="MULTI" v-model="f.locale" :aria-label="t('ui.rq_locale_label')">
          <option value="">{{ t('ui.rq_any_language') }}</option>
          <option v-for="l in app.languages.list" :key="l.code" :value="l.code">{{ l.name }}</option>
        </select>
        <label class="sr" for="rq-per">{{ t('ui.rq_period') }}</label>
        <select id="rq-per" v-model="f.period">
          <option value="">{{ t('ui.rq_period_all') }}</option>
          <option value="today">{{ t('ui.rq_period_today') }}</option>
          <option value="7">{{ t('ui.rq_period_7') }}</option>
          <option value="30">{{ t('ui.rq_period_30') }}</option>
          <option value="custom">{{ t('ui.rq_period_custom') }}</option>
        </select>
        <span v-if="f.period === 'custom'" class="rq-dates">
          <label for="rq-from">{{ t('ui.rq_from') }}</label><input id="rq-from" v-model="f.from" type="date" :max="today">
          <label for="rq-to">{{ t('ui.rq_to') }}</label><input id="rq-to" v-model="f.to" type="date" :max="today">
        </span>
        </FilterSheet>
        <span class="grow num" aria-live="polite">{{ loading ? '' : countText }}</span>
        <div class="seg rq-views" role="group" :aria-label="t('ui.rq_view')">
          <button type="button" :aria-pressed="view === 'table'" @click="setView('table')">{{ t('ui.rq_table') }}</button>
          <button type="button" :aria-pressed="view === 'board'" @click="setView('board')">{{ t('ui.rq_board') }}</button>
        </div>
      </div>
      <div v-if="badRange" class="rq-warn" role="alert"><Icon name="alert" size="s16" />{{ t('ui.rq_bad_range') }}</div>

      <div v-if="loading" class="rq-pad"><Skeleton :rows="6" /></div>
      <div v-else-if="loadError" class="rq-pad"><div class="alert err-a"><Icon name="alert" size="s16" /><span>{{ loadError }}</span><button type="button" class="btn sm" @click="load">{{ t('ui.retry') }}</button></div></div>
      <template v-else-if="visible.length && view === 'board'">
        <KanbanBoard :columns="BOARD" :items="board" :column-of="workOf" :label="(r) => t('ui.rq_card_label', { id: r.id, phone: r.phone })" :disabled="boardBusy || !canEdit" :aria-label="t('ui.rq_board_label')" :empty-text="t('ui.rq_board_empty')" @move="moveCard">
          <template #card="{ item }">
            <button type="button" :class="['rq-card', { unread: isNewRequest(item) }]" @click="open(item)">
              <span class="rq-c1"><b class="num">{{ t('ui.rq_number', { id: item.id }) }}</b><span class="muted num">{{ fmtDT(item.dt) }}</span></span>
              <span class="rq-c2 ph">{{ item.phone }}</span>
              <span v-if="item.city || item.place" class="rq-c3 muted">{{ [item.city, item.place].filter(Boolean).join(' · ') }}</span>
              <span v-if="deliveryFailed(item) || MULTI" class="rq-c4"><span v-if="deliveryFailed(item)" class="chip fail"><Icon name="warn" size="s14" />{{ t('ui.rq_not_delivered') }}</span><LangTag v-if="MULTI" :code="item.locale" /></span>
            </button>
          </template>
        </KanbanBoard>
        <p v-if="spamHidden" class="rq-spam muted">{{ t('ui.rq_spam_hidden', { count: spamHidden }) }}</p>
      </template>
      <table v-else-if="visible.length" class="t t-stack rq-t" :aria-label="t('ui.rq_title')">
        <thead><tr><th>{{ t('ui.rq_col_number') }}</th><th>{{ t('ui.rq_col_received') }}</th><th>{{ t('ui.rq_phone') }}</th><th>{{ t('ui.rd_city') }}</th><th class="rq-form">{{ t('ui.rq_form') }}</th><th v-if="MULTI">{{ t('ui.language') }}</th><th>{{ t('ui.rq_status') }}</th></tr></thead>
        <tbody>
          <tr
            v-for="r in visible" :key="r.id" :class="['rq-row', 'click', { unread: isNewRequest(r) }]" tabindex="0"
            :aria-label="rowLabel(r)"
            @click="open(r)" @keydown.enter.prevent="open(r)" @keydown.space.prevent="open(r)"
          >
            <td class="num ts-top"><span class="ts-pre">{{ t('ui.rq_number', { id: r.id }) }}</span><span class="rq-id">{{ r.id }}</span></td>
            <td class="nw num ts-top">{{ fmtDT(r.dt) }}</td>
            <td class="nw ts-main"><span class="ph">{{ r.phone }}</span></td>
            <td :class="['ts-sub', { 'ts-empty': !r.city }]" :data-label="t('ui.rd_city')">{{ r.city }}</td>
            <td class="rq-form ts-line" :class="{ 'ts-empty': !r.place }" :data-label="t('ui.rq_form')">{{ r.place }}</td>
            <td v-if="MULTI" class="nw rq-lg"><LangTag :code="r.locale" /></td>
            <td class="nw ts-st">
              <span class="rq-st">
                <WorkChip :status="workOf(r)" />
                <span v-if="deliveryFailed(r)" class="chip fail"><Icon name="warn" size="s14" />{{ t('ui.rq_not_delivered') }}</span>
              </span>
            </td>
          </tr>
        </tbody>
      </table>
      <EmptyState v-else-if="filtersOn" icon="search" :title="t('ui.rq_empty_filtered_title')" :text="t('ui.rq_empty_filtered_text')">
        <button type="button" class="btn primary" @click="reset">{{ t('ui.rq_reset_filters') }}</button>
      </EmptyState>
      <EmptyState v-else icon="inbox" :title="t('ui.rq_empty_title')" :text="t('ui.rq_empty_text')" />
      <ListPager v-if="total && !loadError" :page="page" :per-page="perPage" :sizes="[25, 50, 100]" :total="total" @update:page="go" @update:per-page="setPerPage" />
    </div>

    <RequestDrawer :request-id="openId" @close="closeDrawer" @changed="onChanged" />
  </div>
</template>

<style scoped>
/* on a phone the ts-pre label carries the whole number, so the bare id steps aside */
@media (max-width:767px){.rq-id{display:none}}
.rq-pad{padding:14px}
.rq-lg :deep(.lg){margin-left:0}
.rq-form{overflow-wrap:anywhere}
@media (min-width:768px){.rq-form{width:22%;min-width:9rem}}
/* one more filter on a multilingual site: the search gives some room so the toolbar stays on one line */
.rq-multi .search{flex-basis:160px}
.rq-multi select{min-width:130px}
.rq-st{display:inline-flex;gap:6px;align-items:center}
.rq-warn{display:flex;gap:8px;align-items:center;padding:9px 14px;font-size:var(--t-sm);color:var(--amber);background:var(--amber-50);border-bottom:1px solid var(--hair)}
.rq-views{width:auto;flex:none;background:var(--hair-2)}
.rq-views button{color:var(--ink-2);padding:0 12px}
.rq-views button:hover{color:var(--ink)}
.rq-views button[aria-pressed=true]{background:#fff;color:var(--brand-text)}
.rq-card{display:flex;flex-direction:column;align-items:flex-start;gap:3px;width:100%;padding:0;border:0;background:none;text-align:left;font:inherit;color:var(--ink);cursor:pointer}
.rq-card.unread .rq-c2{font-weight:650}
.rq-c1{display:flex;gap:10px;justify-content:space-between;width:100%;font-size:var(--t-sm)}
.rq-c3{font-size:var(--t-sm)}
.rq-c4{display:flex;gap:6px;align-items:center;margin-top:3px}
.rq-spam{padding:0 14px 12px;font-size:var(--t-sm)}
.rq-row:focus-visible{outline-offset:-2px;background:var(--cy-50)}
</style>
