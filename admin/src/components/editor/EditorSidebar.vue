<script setup>
// The ONE sidebar on the left. Exactly one mode on screen, never two panels:
//   «Структура» (home) · «Добавить секцию» (block library) · the selected section's form.
import { computed, inject, nextTick, ref } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import SidebarOutline from './SidebarOutline.vue'
import SidebarLibrary from './SidebarLibrary.vue'
import SidebarForm from './SidebarForm.vue'
import SidebarTemplate from './SidebarTemplate.vue'
import SidebarTools from './SidebarTools.vue'
import { getSectionType, sectionName } from '@/schema/sections.js'
import { t } from '@/i18n/index.js'
import { EDITOR_KEY, EDITOR_UI_KEY } from './editorState.js'

const ed = inject(EDITOR_KEY)
const ui = inject(EDITOR_UI_KEY)
const root = ref(null)
const form = ref(null)
const tplForm = ref(null)

const n = computed(() => ed.sections.length)
const sec = computed(() => ed.curSec)
const secIdx = computed(() => (sec.value ? ed.indexOf(sec.value.id) : -1))
const prev = computed(() => ed.sections[secIdx.value - 1] || null)
const next = computed(() => ed.sections[secIdx.value + 1] || null)
const about = computed(() => (sec.value && getSectionType(sec.value.type) ? getSectionType(sec.value.type).about : ''))
const label = computed(() => (ed.sb === 'lib' ? t('editor.sb_lib') : ed.sb === 'tpl' && ed.template ? t('editor.sb_tpl', { name: ed.template.name }) : ed.sb === 'form' && sec.value ? t('editor.sb_form', { name: sectionName(sec.value.type) }) : t('editor.sb_outline')))
const tplChip = computed(() => (ed.tplInfo.chg ? t('editor.edits_count', { count: ed.tplInfo.n }) : ''))
const where = computed(() => {
  const at = Math.min(ed.insertAt, n.value)
  const nm = (i) => sectionName(ed.sections[i].type)
  return at <= 0 ? t('editor.place_start') : at >= n.value ? t('editor.place_end', { name: nm(n.value - 1) }) : t('editor.place_between', { a: nm(at - 1), b: nm(at) })
})
const chip = computed(() => {
  if (!sec.value) return null
  const st = ed.info[sec.value.id]
  if (st.isNew) return { text: t('editor.chip_new'), dot: false }
  const ch = st.keys.length + (ed.pubById[sec.value.id] && !!ed.pubById[sec.value.id].hidden !== !!sec.value.hidden ? 1 : 0)
  return ch ? { text: t('editor.edits_count', { count: ch }), dot: true } : null
})

const q = (sel) => root.value && root.value.querySelector(sel)
function focusHead() { const h = q('#sbT'); if (h) h.focus({ preventScroll: true }) }
function focusRow(id) {
  const el = q(`.ol-row[data-id="${id}"] .ol-go`)
  if (el) { el.focus({ preventScroll: true }); el.scrollIntoView({ block: 'nearest' }) } else focusHead()
}
/** after a reorder in «Структура» focus stays on the moved row's arrow (or its twin when one ran out) */
function focusRowAct(id, act, alt) {
  const row = q(`.ol-row[data-id="${id}"]`)
  const b = row && (row.querySelector(`[data-act="${act}"]:not([disabled])`) || row.querySelector(`[data-act="${alt}"]:not([disabled])`) || row.querySelector('.ol-go'))
  if (b) b.focus({ preventScroll: true })
}
function focusAct(act, alt) {
  const b = q(`[data-act="${act}"]:not([disabled])`) || (alt && q(`[data-act="${alt}"]:not([disabled])`))
  if (b) b.focus({ preventScroll: true }); else focusHead()
}
const body = () => q('.sb-body')
defineExpose({
  focusHead, focusRow, focusRowAct, focusAct, body,
  focusField: (path) => { const f = () => (ed.sb === 'tpl' ? tplForm.value : form.value); return f() ? f().focusField(path) : nextTick().then(() => f() && f().focusField(path)) },
  focusFirst: (select) => (ed.sb === 'tpl' ? tplForm.value : form.value) && (ed.sb === 'tpl' ? tplForm.value : form.value).focusFirst(select),
  showAgent: () => form.value && form.value.showAgent(),
})
</script>

<template>
  <aside id="sb" ref="root" class="sidebar" :hidden="!ed.sb" :data-mode="ed.sb || undefined" :aria-label="label">
    <div v-if="ed.sb" :key="ed.sb" class="sb-main in">
      <!-- Структура -->
      <header v-if="ed.sb === 'outline'" class="sb-h">
        <div class="sb-ti">
          <div class="sb-t"><h2 id="sbT" tabindex="-1">{{ t('editor.outline_title') }}</h2><p>{{ t('editor.sections_count', { count: n }) }}</p></div>
          <SidebarTools />
        </div>
      </header>
      <!-- Добавить секцию -->
      <header v-else-if="ed.sb === 'lib'" class="sb-h">
        <div class="sb-nav">
          <button class="btn ghost sm sb-back" type="button" :title="t('editor.back_to_outline')" @click="ui.goSb('outline', { focus: 'row' })"><Icon name="chevL" size="s16" />{{ t('editor.outline_title') }}</button>
          <i class="sp" />
          <SidebarTools />
        </div>
        <div class="sb-ti"><div class="sb-t"><h2 id="sbT" tabindex="-1">{{ t('editor.add_section') }}</h2><p>{{ where }}</p></div></div>
      </header>
      <!-- поля шаблона -->
      <header v-else-if="ed.sb === 'tpl' && ed.template" class="sb-h">
        <div class="sb-nav">
          <button class="btn ghost sm sb-back" type="button" :title="t('editor.back_to_outline')" @click="ui.goSb('outline', { focus: 'row' })"><Icon name="chevL" size="s16" />{{ t('editor.outline_title') }}</button>
          <i class="sp" />
          <SidebarTools />
        </div>
        <div class="sb-ti">
          <div class="sb-t">
            <div class="sb-tt">
              <h2 id="sbT" tabindex="-1">{{ t('editor.sb_tpl', { name: ed.template.name }) }}</h2>
              <span v-if="tplChip" class="chip draft"><span class="dot" />{{ tplChip }}</span>
            </div>
            <p class="cl" :title="ed.template.about">{{ ed.template.about }}</p>
          </div>
        </div>
      </header>
      <!-- форма секции -->
      <header v-else-if="ed.sb === 'form' && sec" class="sb-h">
        <div class="sb-nav">
          <button class="btn ghost sm sb-back" type="button" :title="t('editor.back_to_outline')" @click="ui.goSb('outline', { focus: 'row' })"><Icon name="chevL" size="s16" />{{ t('editor.outline_title') }}</button>
          <i class="sp" />
          <button
            class="btn ghost icon sm sb-up" type="button" data-act="sb-prev" aria-describedby="sbT" :aria-label="prev ? t('editor.sec_prev_named', { name: sectionName(prev.type) }) : t('editor.sec_prev')" :title="t('editor.sec_prev')" :disabled="!prev"
            @click="ui.stepSection(-1)"
          ><Icon name="chevD" size="s16" /></button>
          <button
            class="btn ghost icon sm" type="button" data-act="sb-next" aria-describedby="sbT" :aria-label="next ? t('editor.sec_next_named', { name: sectionName(next.type) }) : t('editor.sec_next')" :title="t('editor.sec_next')" :disabled="!next"
            @click="ui.stepSection(1)"
          ><Icon name="chevD" size="s16" /></button>
          <SidebarTools />
        </div>
        <div class="sb-ti">
          <div class="sb-t">
            <div class="sb-tt">
              <h2 id="sbT" tabindex="-1">{{ sectionName(sec.type) }}</h2>
              <span v-if="chip" class="chip draft"><span v-if="chip.dot" class="dot" />{{ chip.text }}</span>
            </div>
            <p class="cl" :title="about">{{ about }}</p>
          </div>
        </div>
      </header>

      <SidebarOutline v-if="ed.sb === 'outline'" />
      <SidebarLibrary v-else-if="ed.sb === 'lib'" />
      <SidebarTemplate v-else-if="ed.sb === 'tpl' && ed.template" ref="tplForm" />
      <SidebarForm v-else-if="ed.sb === 'form' && sec" ref="form" />
    </div>
  </aside>
</template>
