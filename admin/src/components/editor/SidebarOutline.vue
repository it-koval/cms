<script setup>
// Sidebar mode «Структура»: the page as a list of sections. Click a row to edit it; order changes with the arrows or by dragging.
import { computed, inject, ref } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import Silhouette from '@/components/ui/Silhouette.vue'
import { getSectionType, NO_H1_NOTICE, sectionName } from '@/schema/sections.js'
import { t } from '@/i18n/index.js'
import { EDITOR_KEY, EDITOR_UI_KEY, TPL_ID } from './editorState.js'
import { vp } from '@/utils/viewport.js'

const ed = inject(EDITOR_KEY)
const ui = inject(EDITOR_UI_KEY)

const dragId = ref('')
const overId = ref('')
function onDragStart(e, id) {
  dragId.value = id
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('text/plain', 'section')
}
function onDragOver(e, id) {
  if (!dragId.value) return
  e.preventDefault()
  overId.value = id
}
function onDrop(e, id) {
  if (!dragId.value) return
  e.preventDefault()
  ed.reorder(dragId.value, id)
  dragId.value = ''
  overId.value = ''
}
function onDragEnd() { dragId.value = ''; overId.value = '' }
// the label of a row: what it opens, then the states it is in, each a phrase of its own
const tplLabel = computed(() => [
  t('editor.outline_edit_tpl', { name: ed.template.name }),
  ed.tplInfo.chg && t('editor.outline_state_changed'),
  ed.tplInfo.err && t('editor.outline_state_missing'),
].filter(Boolean).join(', '))
const h = (type) => (getSectionType(type) ? getSectionType(type).h : 90)
const rowLabel = (s) => {
  const st = ed.info[s.id]
  return [
    t('editor.outline_edit_sec', { name: sectionName(s.type) }),
    st.chg && t(st.isNew ? 'editor.outline_state_new' : 'editor.outline_state_changed'),
    st.err && t('editor.outline_state_missing'),
    s.hidden && t('editor.outline_state_hidden'),
  ].filter(Boolean).join(', ')
}
const help = computed(() => {
  if (ed.readOnly) return t('editor.outline_help_readonly')
  if (ed.hasFields) return t(vp.touch ? 'editor.outline_help_fields_touch' : 'editor.outline_help_fields')
  return t(vp.touch ? 'editor.outline_help_touch' : 'editor.outline_help')
})
</script>

<template>
  <div id="sbBody" class="sb-body">
    <p class="sb-help">{{ help }}</p>
    <div v-if="!ed.hasH1" class="alert warn-a no-h1" role="status">
      <Icon name="alert" size="s16" />
      <span>{{ NO_H1_NOTICE }} <button v-if="ed.canAdd" type="button" class="revert" @click="ui.addAt(0)">{{ t('editor.outline_add_start') }}</button></span>
    </div>
    <div class="ol-wrap">
      <svg class="reg" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1" aria-hidden="true"><circle cx="7" cy="7" r="4" /><path d="M7 0v14M0 7h14" /></svg>
      <div class="ol-list">
        <!-- the template's own fields: always the first row, not movable -->
        <div v-if="ed.hasFields" :class="['ol-row', 'ol-fixed', { on: ed.sb === 'tpl' }]" :data-id="TPL_ID">
          <button type="button" class="ol-go" :aria-label="tplLabel" @click="ui.openTpl({ scroll: 'start' })">
            <span :class="['ol-th', { chg: ed.tplInfo.chg }]"><Icon name="sliders" size="s20" /></span>
            <span class="ol-tx">
              <b>{{ t('editor.sb_tpl', { name: ed.template.name }) }}</b>
              <span class="ol-tags">
                <span v-if="ed.tplInfo.chg" class="ol-m"><span class="pmark" aria-hidden="true"><i /></span>{{ t('editor.outline_tpl_changed') }}</span>
                <span v-if="ed.tplInfo.err" class="ol-e"><i />{{ t('editor.outline_empty') }}</span>
                <span v-if="!ed.tplInfo.chg && !ed.tplInfo.err">{{ t('editor.outline_tpl_first') }}</span>
              </span>
            </span>
          </button>
        </div>
        <div v-if="ed.hasFields && ed.sections.length" class="ol-sub">{{ t('editor.outline_sections_head') }}</div>
        <p v-if="ed.hasFields && !ed.sections.length" class="ol-sub-empty">{{ t('editor.outline_no_sections') }}</p>
        <div
          v-for="(s, i) in ed.sections" :key="s.id" :data-id="s.id" :draggable="ed.readOnly ? 'false' : 'true'"
          :class="['ol-row', { on: s.id === ed.sel && ed.sb !== 'tpl', hid: s.hidden, dragging: dragId === s.id, over: overId === s.id && dragId !== s.id }]"
          @dragstart="onDragStart($event, s.id)" @dragover="onDragOver($event, s.id)" @drop="onDrop($event, s.id)" @dragend="onDragEnd"
        >
          <button type="button" class="ol-go" :aria-label="rowLabel(s)" @click="ui.openForm(s.id, { scroll: 'start' })">
            <span :class="['ol-th', { chg: ed.info[s.id].chg }]"><Silhouette :type="s.type" :w="184" :h="h(s.type)" /></span>
            <span class="ol-tx">
              <b>{{ sectionName(s.type) }}</b>
              <span class="ol-tags">
                <span v-if="ed.info[s.id].chg" class="ol-m"><span class="pmark" aria-hidden="true"><i /></span>{{ ed.info[s.id].isNew ? t('editor.chip_new') : t('editor.outline_sec_changed') }}</span>
                <span v-if="ed.info[s.id].err" class="ol-e"><i />{{ t('editor.outline_empty') }}</span>
                <span v-if="s.hidden">{{ t('editor.outline_state_hidden') }}</span>
              </span>
            </span>
          </button>
          <span v-if="!ed.readOnly" class="ol-mv">
            <button type="button" class="btn ghost icon sm" data-act="mv-up" :aria-label="t('editor.outline_move_up', { name: sectionName(s.type) })" :disabled="i === 0" @click="ui.moveSec(s.id, -1, true)"><Icon name="up" size="s14" /></button>
            <button type="button" class="btn ghost icon sm" data-act="mv-down" :aria-label="t('editor.outline_move_down', { name: sectionName(s.type) })" :disabled="i === ed.sections.length - 1" @click="ui.moveSec(s.id, 1, true)"><Icon name="down" size="s14" /></button>
          </span>
        </div>
        <!-- the list the template fills by itself: shown last, as on the page -->
        <div v-if="ed.listing" class="ol-row ol-auto">
          <button type="button" class="ol-go" :aria-label="t('editor.outline_auto_label', { label: ed.listing.label })" @click="ui.showListing()">
            <span class="ol-th"><Icon name="refresh" size="s16" /></span>
            <span class="ol-tx"><b>{{ ed.listing.label }}</b><span class="ol-tags"><span>{{ t('editor.outline_auto') }}</span></span></span>
          </button>
        </div>
      </div>
      <i class="cm-b" />
    </div>
    <button v-if="ed.canAdd" type="button" class="btn ol-add" @click="ui.addAt(ed.sections.length)"><Icon name="plus" size="s16" />{{ t('editor.add_section') }}</button>
    <div class="ol-leg">
      <div><span class="pmark" aria-hidden="true"><i /></span>{{ t('editor.outline_legend_changed') }}</div>
      <div><span class="leg-sel" />{{ ed.hasFields ? t('editor.outline_legend_sel_fields') : t('editor.outline_legend_sel') }}</div>
    </div>
  </div>
</template>

<style scoped>
.reg { color: var(--ink-2); }
.no-h1 { margin: 10px 0 2px; }
.no-h1 .revert { color: var(--ink); }
.leg-sel { width: 14px; height: 10px; border: 2px solid var(--cy); border-radius: 2px; display: inline-block; }
</style>
