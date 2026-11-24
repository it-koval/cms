<script setup>
// Sidebar mode «form»: the selected section edited with the schema form («Вид секции» first).
import { computed, inject, nextTick, ref } from 'vue'
import SchemaForm from '@/components/form/SchemaForm.vue'
import Icon from '@/components/ui/Icon.vue'
import { formFields } from '@/schema/sections.js'
import { t } from '@/i18n/index.js'
import { EDITOR_KEY, EDITOR_UI_KEY } from './editorState.js'

const ed = inject(EDITOR_KEY)
const ui = inject(EDITOR_UI_KEY)
const body = ref(null)
const form = ref(null)

const sec = computed(() => ed.curSec)
// the full field list (with the readonly notes), not only the editable ones; the headline of the section that carries the page's h1 is labelled «Заголовок страницы (H1)»
const fields = computed(() => (sec.value ? formFields(sec.value.type, ed.h1Id === sec.value.id) : []))
const pub = computed(() => (sec.value ? ed.pubOf(sec.value.id) : null))
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
defineExpose({ focusField, focusFirst, body })
</script>

<template>
  <div id="sbBody" ref="body" class="sb-body">
    <div v-if="sec && sec.hidden" class="alert warn-a hid-note">
      <Icon name="eyeOff" size="s16" />
      <span>{{ t('editor.form_hidden') }} <button v-if="!ed.readOnly" type="button" class="revert" @click="ui.toggleHide(sec.id, true)">{{ t('editor.form_show') }}</button></span>
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
</style>
