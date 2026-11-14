<script setup>
// Sidebar mode «form»: the selected section edited with the schema form («Вид секции» first), plus the AI agent's proposals for this section.
import { computed, inject, nextTick, ref } from 'vue'
import SchemaForm from '@/components/form/SchemaForm.vue'
import Icon from '@/components/ui/Icon.vue'
import { formFields } from '@/schema/sections.js'
import { t } from '@/i18n/index.js'
import { EDITOR_KEY, EDITOR_UI_KEY, propText } from './editorState.js'
import { scrollIn } from './scroll.js'

const ed = inject(EDITOR_KEY)
const ui = inject(EDITOR_UI_KEY)
const body = ref(null)
const form = ref(null)
const agent = ref(null)

const sec = computed(() => ed.curSec)
// the full field list (with the readonly notes), not only the editable ones; the headline of the section that carries the page's h1 is labelled «Заголовок страницы (H1)»
const fields = computed(() => (sec.value ? formFields(sec.value.type, ed.h1Id === sec.value.id) : []))
const pub = computed(() => (sec.value ? ed.pubOf(sec.value.id) : null))
const props_ = computed(() => (sec.value ? ed.liveProps.filter((p) => p.sectionId === sec.value.id) : []))
const fieldOf = (key) => fields.value.find((f) => f.k === key) || null
const labelOf = (p) => (fieldOf(p.key) ? fieldOf(p.key).label : p.label || p.key)
// «было» is what the field holds now (the client may have edited it since the agent looked)
const oldText = (p) => propText(fieldOf(p.key), sec.value.data[p.key] ?? p.old)
const newText = (p) => propText(null, p.nw)

async function focusField(path) {
  await nextTick()
  return form.value ? form.value.focusField(path) : false
}
function focusFirst(select) {
  const el = body.value && body.value.querySelector('input[type=text],textarea,[contenteditable="true"]')
  if (!el) return false
  el.focus({ preventScroll: true })
  if (select) {
    if (el.select) el.select()
    else { const r = document.createRange(); r.selectNodeContents(el); const s = getSelection(); s.removeAllRanges(); s.addRange(r) } // a new section's placeholder text is replaced by typing
  }
  return true
}
const showAgent = () => scrollIn(body.value, agent.value, 'center')

async function accept(p) {
  ed.acceptProposal(p.id)
  await nextTick()
  // the pressed button is gone: the next proposal's «Принять», or the field that was just decided
  const next = body.value && body.value.querySelector('[data-act="agent-accept"]')
  if (next) next.focus({ preventScroll: true })
  else focusField(p.key)
}
async function reject(p) {
  ed.rejectProposal(p.id)
  await nextTick()
  const next = body.value && body.value.querySelector('[data-act="agent-accept"]')
  if (next) next.focus({ preventScroll: true })
  else focusField(p.key)
}
async function acceptAll() {
  const mine = ed.acceptAll(sec.value.id)
  await nextTick()
  if (mine.length) focusField(mine[0].key)
}
defineExpose({ focusField, focusFirst, showAgent, body })
</script>

<template>
  <div id="sbBody" ref="body" class="sb-body">
    <div v-if="sec && sec.hidden" class="alert warn-a hid-note">
      <Icon name="eyeOff" size="s16" />
      <span>{{ t('editor.form_hidden') }} <button v-if="!ed.readOnly" type="button" class="revert" @click="ui.toggleHide(sec.id, true)">{{ t('editor.form_show') }}</button></span>
    </div>
    <div v-if="props_.length" id="agentB" ref="agent" class="agent-b">
      <div class="ah">
        <Icon name="sparkle" />{{ t('editor.agent_offered', { count: props_.length }) }}
        <button v-if="props_.length > 1" type="button" class="btn sm all" @click="acceptAll">{{ t('editor.agent_accept_all') }}</button>
      </div>
      <div v-for="p in props_" :key="p.id" class="diff">
        <div class="fl">{{ labelOf(p) }}</div>
        <del>{{ oldText(p) }}</del>
        <ins>{{ newText(p) }}</ins>
        <span class="dn">{{ t('editor.agent_note', { note: p.note }) }}</span>
        <div class="da">
          <button type="button" class="btn sm primary" data-act="agent-accept" @click="accept(p)"><Icon name="check" size="s14" />{{ t('editor.agent_accept') }}</button>
          <button type="button" class="btn sm" @click="reject(p)">{{ t('editor.agent_reject') }}</button>
        </div>
      </div>
    </div>
    <SchemaForm
      v-if="sec" :key="sec.id" ref="form" :schema="fields" :model-value="sec.data" :published="pub" :readonly="ed.readOnly"
      @update:model-value="ed.updateData(sec.id, $event)"
    />
  </div>
</template>

<style scoped>
.hid-note { margin: 12px 0 4px; }
.hid-note .revert { color: var(--ink); }
.agent-b .all { margin-left: auto; }
</style>
