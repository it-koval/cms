<script setup>
// The calendar view of a list: a month or a week; a record stands on its start date (and runs to its end date, if the view names one).
//   click on a day (or its «+») → `create(day)`: the parent makes a record with that date; drag a record to another day → `move({ row, start, end })`: both dates move by the same number of days
//   (the time of day stays); Alt + ← → moves the focused record by a day, Alt + ↑ ↓ by a week; a click on a record opens it. Records without a start date are listed under the grid.
// It draws and listens, the parent saves. `start` / `end` / `title` are KEYS of the fields (the view's settings in the schema); `titleOf(row)` is the caption of a chip.
// A month cell shows 3 records and «ещё N», which opens the week of that day.
import { computed, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import Icon from '@/components/ui/Icon.vue'
import { vp } from '@/utils/viewport.js'
import { addDays, byDay, dayLabel, dayOf, diffDays, monthDays, shiftMonth, shiftValue, spans, titleOf as periodTitle, today, WEEKDAYS, weekdayShort, weekDays } from './calendarModel.js'
import { t } from '@/i18n/index.js'

const props = defineProps({
  rows: { type: Array, default: () => [] },
  start: { type: String, required: true },
  end: { type: String, default: null },
  titleOf: { type: Function, required: true },
  hrefOf: { type: Function, default: null },
  editable: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  canMove: { type: Function, default: null }, // (row) => boolean | 'reason'
})
const emit = defineEmits(['open', 'create', 'move', 'blocked'])

const mode = ref('month')
const anchor = ref(today())
const todayDay = today()
const days = computed(() => (mode.value === 'month' ? monthDays(anchor.value) : weekDays(anchor.value)))
const all = computed(() => spans(props.rows, props.start, props.end))
const map = computed(() => byDay(all.value, days.value))
const undated = computed(() => props.rows.filter((r) => !dayOf(r[props.start])))
const title = computed(() => periodTitle(mode.value, anchor.value))
const SHOW = 3
// a phone has no room for seven columns: the same records go as a list, day by day (dates are changed in the record's form)
const agenda = computed(() => days.value.filter((d) => (mode.value === 'week' || inMonth(d)) && map.value.get(d).some((e) => e.first || d === days.value[0])).map((d) => ({ day: d, list: map.value.get(d).filter((e) => e.first || d === days.value[0]) })))
const agendaDay = (d) => `${dayLabel(d)}, ${weekdayShort(d)}`
const rangeOf = (row) => (props.end && dayOf(row[props.end]) && dayOf(row[props.end]) !== dayOf(row[props.start]) ? t('collections.cal_until', { date: dayLabel(dayOf(row[props.end])) }) : '')
const inMonth = (d) => d.slice(0, 7) === anchor.value.slice(0, 7)
const items = (d) => (mode.value === 'week' ? map.value.get(d) : map.value.get(d).slice(0, SHOW))
const more = (d) => (mode.value === 'week' ? 0 : Math.max(0, map.value.get(d).length - SHOW))

function shift(n) { anchor.value = mode.value === 'month' ? shiftMonth(anchor.value, n) : addDays(anchor.value, 7 * n) }
const toToday = () => { anchor.value = todayDay }
function openWeek(d) { anchor.value = d; mode.value = 'week' }
function setMode(m) {
  mode.value = m
  if (m === 'week' && anchor.value.slice(0, 7) !== todayDay.slice(0, 7)) anchor.value = `${anchor.value.slice(0, 7)}-01`
}

/* ---------- moving a record ---------- */
const live = ref('')
const drag = reactive({ id: '', from: '', over: '' })
const lockOf = (row) => {
  if (!props.editable) return t('collections.cal_cannot_edit_dates')
  const v = props.canMove ? props.canMove(row) : true
  return v
}
function shiftRow(row, n) {
  if (!n) return
  const verdict = lockOf(row)
  if (verdict !== true) {
    const why = typeof verdict === 'string' ? verdict : t('collections.cal_cannot_move')
    live.value = why
    emit('blocked', why)
    return
  }
  const start = shiftValue(row[props.start], n)
  const end = props.end && dayOf(row[props.end]) ? shiftValue(row[props.end], n) : undefined
  live.value = end
    ? t('collections.cal_moved_range', { title: props.titleOf(row), from: dayLabel(dayOf(start)), to: dayLabel(dayOf(end)) })
    : t('collections.cal_moved_day', { title: props.titleOf(row), from: dayLabel(dayOf(start)) })
  emit('move', { row, start, end, days: n })
}
function onKey(e, row) {
  if (!e.altKey) return
  const n = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key]
  if (!n) return
  e.preventDefault()
  shiftRow(row, n)
}
function onDragStart(e, row, day) {
  if (props.busy || lockOf(row) !== true) { e.preventDefault(); return }
  drag.id = String(row.id)
  drag.from = day
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('text/plain', drag.id)
}
function onDayOver(e, day) {
  if (!drag.id) return
  e.preventDefault()
  e.dataTransfer.dropEffect = 'move'
  drag.over = day
}
function onDrop(e, day) {
  if (!drag.id) return
  e.preventDefault()
  const row = props.rows.find((r) => String(r.id) === drag.id)
  const n = diffDays(day, drag.from)
  drag.id = ''
  drag.over = ''
  if (row) shiftRow(row, n)
}
const onEnd = () => { drag.id = ''; drag.over = '' }

const clickDay = (e, d) => { if (props.editable && !props.busy && e.target === e.currentTarget) emit('create', d) }
const chipTitle = (row) => props.titleOf(row)
// the caption stands on the first day of a record, on the first day of every week row and in the week view; the other days of a long record are a bar
const isMonday = (d) => diffDays(d, '1970-01-05') % 7 === 0
const shows = (e, d) => e.first || mode.value === 'week' || d === days.value[0] || isMonday(d)
</script>

<template>
  <div class="cv" @dragend="onEnd">
    <div class="cv-bar">
      <div class="cv-nav" role="group" :aria-label="t('collections.cal_period')">
        <button type="button" class="btn icon sm" :aria-label="t('collections.cal_back')" @click="shift(-1)"><Icon name="chevL" size="s16" /></button>
        <button type="button" class="btn sm" @click="toToday">{{ t('collections.cal_today') }}</button>
        <button type="button" class="btn icon sm" :aria-label="t('collections.cal_forward')" @click="shift(1)"><Icon name="chevR" size="s16" /></button>
      </div>
      <h2 class="cv-title" aria-live="polite">{{ title }}</h2>
      <div class="cv-mode" role="group" :aria-label="t('collections.cal_scale')">
        <button type="button" :aria-pressed="mode === 'month'" @click="setMode('month')">{{ t('collections.cal_month') }}</button>
        <button type="button" :aria-pressed="mode === 'week'" @click="setMode('week')">{{ t('collections.cal_week') }}</button>
      </div>
    </div>

    <div v-if="vp.phone" class="cv-agenda" :aria-label="title">
      <p v-if="!agenda.length" class="cv-none">{{ mode === 'month' ? t('collections.cal_none_month') : t('collections.cal_none_week') }}</p>
      <section v-for="a in agenda" :key="a.day" :class="['cv-ag', { today: a.day === todayDay }]">
        <h3>{{ agendaDay(a.day) }}<span v-if="a.day === todayDay"> · {{ t('collections.cal_today_lower') }}</span></h3>
        <ul>
          <li v-for="e in a.list" :key="e.row.id">
            <component :is="hrefOf ? RouterLink : 'button'" :to="hrefOf ? hrefOf(e.row) : undefined" :type="hrefOf ? undefined : 'button'" :class="['cv-agi', { draft: e.row.status === 'draft' }]" @click="!hrefOf && emit('open', e.row)">
              <b>{{ chipTitle(e.row) }}</b><span v-if="rangeOf(e.row)">{{ rangeOf(e.row) }}</span><span v-if="e.row.status === 'draft'" class="chip draft"><span class="dot" />{{ t('collections.cal_draft') }}</span>
            </component>
          </li>
        </ul>
      </section>
    </div>
    <div v-else :class="['cv-grid', mode]" role="grid" :aria-label="title">
      <div class="cv-head" role="row"><span v-for="w in WEEKDAYS" :key="w" role="columnheader">{{ w }}</span></div>
      <div class="cv-days">
        <div
          v-for="d in days" :key="d" role="gridcell" :class="['cv-day', { out: mode === 'month' && !inMonth(d), today: d === todayDay, over: drag.over === d, can: editable }]"
          @click="clickDay($event, d)" @dragover="onDayOver($event, d)" @drop="onDrop($event, d)"
        >
          <div class="cv-dh" @click="clickDay($event, d)">
            <span class="cv-num num">{{ +d.slice(8) }}</span>
            <button v-if="editable" type="button" class="cv-plus" :aria-label="t('collections.cal_add_on', { day: dayLabel(d) })" :title="t('collections.cal_add_on_title')" @click.stop="emit('create', d)"><Icon name="plus" size="s14" /></button>
          </div>
          <ul class="cv-ev">
            <li v-for="e in items(d)" :key="e.row.id">
              <component
                :is="hrefOf ? RouterLink : 'button'" :to="hrefOf ? hrefOf(e.row) : undefined" :type="hrefOf ? undefined : 'button'"
                :class="['cv-chip', { draft: e.row.status === 'draft', cont: !e.first, endcap: e.last, dragging: drag.id === String(e.row.id), still: lockOf(e.row) !== true }]"
                :draggable="editable && !busy && lockOf(e.row) === true" :title="chipTitle(e.row)"
                @dragstart="onDragStart($event, e.row, d)" @keydown="onKey($event, e.row)" @click="!hrefOf && emit('open', e.row)"
              ><span :class="{ sr: !shows(e, d) }">{{ chipTitle(e.row) }}</span></component>
            </li>
          </ul>
          <button v-if="more(d)" type="button" class="cv-more" :aria-label="t('collections.cal_show_all', { day: dayLabel(d) })" @click.stop="openWeek(d)">{{ t('collections.cal_more', { count: more(d) }) }}</button>
        </div>
      </div>
    </div>

    <p v-if="editable && (vp.phone || vp.touch)" class="cv-hint"><Icon name="info" size="s14" />{{ t('collections.cal_hint_open') }}</p>
    <p v-else-if="editable" class="cv-hint"><Icon name="info" size="s14" />{{ t('collections.cal_hint_drag') }}</p>
    <div v-if="undated.length" class="cv-und">
      <b>{{ t('collections.cal_undated') }}</b>
      <component :is="hrefOf ? RouterLink : 'button'" v-for="r in undated" :key="r.id" :to="hrefOf ? hrefOf(r) : undefined" :type="hrefOf ? undefined : 'button'" class="cv-chip free" @click="!hrefOf && emit('open', r)">{{ chipTitle(r) }}</component>
    </div>
    <div class="sr" role="status" aria-live="polite" aria-atomic="true">{{ live }}</div>
  </div>
</template>

<style scoped>
.cv { padding: 14px; }
.cv-bar { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 12px; }
.cv-nav { display: inline-flex; gap: 4px; }
.cv-title { margin: 0; font-size: var(--t-lg, 17px); font-weight: 650; text-transform: capitalize; min-width: 11ch; }
.cv-mode { margin-left: auto; display: inline-flex; border: 1px solid var(--hair); border-radius: var(--r); overflow: hidden; background: #fff; }
.cv-mode button { border: 0; background: none; height: 30px; padding: 0 12px; font-size: var(--t-sm); font-weight: 550; color: var(--ink-2); }
.cv-mode button + button { border-left: 1px solid var(--hair); }
.cv-mode button[aria-pressed=true] { background: var(--cy-50); color: var(--cy-dd); font-weight: 650; }
.cv-grid { border: 1px solid var(--hair); border-radius: var(--r-lg); overflow: hidden; background: #fff; }
.cv-head { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); background: var(--sheet-2); border-bottom: 1px solid var(--hair); }
.cv-head span { padding: 7px 10px; font-size: var(--t-xs); font-weight: 650; color: var(--ink-2); text-transform: uppercase; letter-spacing: .04em; }
.cv-days { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); }
.cv-day { position: relative; min-height: 104px; padding: 4px 4px 6px; border-right: 1px solid var(--hair-2); border-bottom: 1px solid var(--hair-2); display: flex; flex-direction: column; gap: 3px; }
.cv-day:nth-child(7n) { border-right: 0; }
.cv-grid.week .cv-day { min-height: 280px; }
.cv-day.can { cursor: pointer; }
.cv-day.out { background: var(--sheet-2); }
.cv-day.out .cv-num { color: var(--ink-3); }
.cv-day.over { background: var(--cy-50); box-shadow: inset 0 0 0 2px var(--cy); }
.cv-dh { display: flex; align-items: center; justify-content: space-between; min-height: 24px; }
.cv-num { min-width: 24px; height: 24px; padding: 0 5px; display: inline-grid; place-items: center; border-radius: 12px; font-size: var(--t-sm); font-weight: 600; color: var(--ink); }
.cv-day.today .cv-num { background: var(--cy); color: var(--brand-ink); }
.cv-plus { width: 24px; height: 24px; display: grid; place-items: center; border: 0; border-radius: 4px; background: none; color: var(--ink-3); opacity: 0; padding: 0; }
.cv-day:hover .cv-plus, .cv-plus:focus-visible { opacity: 1; }
.cv-plus:hover { background: var(--cy-50); color: var(--brand-text); }
@media (hover: none) { .cv-plus { opacity: 1; width: 44px; height: 44px; margin: -10px -4px -10px 0; } }
.cv-ev { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 3px; }
.cv-chip { display: block; width: 100%; min-height: 22px; padding: 2px 7px; border: 0; border-radius: 4px; background: var(--cy-100); color: var(--cy-dd); font-size: var(--t-xs); font-weight: 600; line-height: 1.35; text-align: left; text-decoration: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; cursor: grab; }
.cv-chip:hover { background: var(--cy-200); color: var(--cy-dd); }
.cv-chip.draft { background: #fff; box-shadow: inset 0 0 0 1.5px var(--cy-200); border: 0; }
.cv-chip.cont { border-top-left-radius: 0; border-bottom-left-radius: 0; margin-left: -4px; width: calc(100% + 4px); }
.cv-chip:not(.endcap) { border-top-right-radius: 0; border-bottom-right-radius: 0; margin-right: -4px; width: calc(100% + 4px); }
.cv-chip.cont:not(.endcap) { width: calc(100% + 8px); }
.cv-chip.dragging { opacity: .4; }
.cv-chip.still, .cv-chip.free { cursor: pointer; }
.cv-more { align-self: flex-start; border: 0; background: none; padding: 0 4px; font-size: var(--t-xs); font-weight: 600; color: var(--brand-text); }
.cv-more:hover { text-decoration: underline; }
.cv-hint { display: flex; gap: 6px; align-items: flex-start; margin: 10px 2px 0; font-size: var(--t-sm); color: var(--ink-2); }
.cv-hint .ic { flex: none; margin-top: 2px; color: var(--brand-text); }
.cv-und { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 12px; }
.cv-und b { font-size: var(--t-sm); color: var(--ink-2); margin-right: 4px; }
.cv-und .cv-chip { width: auto; max-width: 240px; }
@media (max-width: 767px) { .cv-day { min-height: 70px; } .cv-head span { padding: 6px 4px; } .cv-chip { padding: 2px 4px; } }
/* phone: a list of days */
.cv-agenda { display: flex; flex-direction: column; gap: 14px; }
.cv-none { padding: 18px 8px; text-align: center; color: var(--ink-2); }
.cv-ag h3 { margin: 0 0 6px; font-size: var(--t-sm); font-weight: 650; color: var(--ink-2); }
.cv-ag.today h3 { color: var(--brand-text); }
.cv-ag ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.cv-agi { display: flex; flex-wrap: wrap; align-items: center; gap: 2px 10px; min-height: 48px; padding: 8px 12px; width: 100%; border: 1px solid var(--hair); border-radius: var(--r); background: #fff; color: var(--ink); text-decoration: none; text-align: left; font: inherit; box-shadow: var(--sh-1); }
.cv-agi b { font-weight: 600; }
.cv-agi span:not(.chip) { font-size: var(--t-sm); color: var(--ink-2); }
.cv-agi.draft { border-style: dashed; }
@media (max-width: 767px) {
  .cv { padding: 12px; }
  .cv-bar { gap: 8px; }
  .cv-nav { order: 2; flex: 1; }
  .cv-nav .btn.sm { flex: 1; }
  .cv-title { order: 1; flex: 1 1 100%; }
  .cv-mode { order: 3; margin-left: 0; width: 100%; }
  .cv-mode button { flex: 1; height: 44px; }
}
</style>
