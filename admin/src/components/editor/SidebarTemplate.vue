<script setup>
// Sidebar mode «Поля шаблона»: the page's own fields (an article's title, date, cover and text; a product's card) edited with the schema form,
// with the card preview on top where the template has one. Opened by the fixed first row of «Структура» and by a click on the fixed part of the page.
import { computed, inject, ref } from 'vue'
import SchemaForm from '@/components/form/SchemaForm.vue'
import { asH1Field } from '@/schema/sections.js'
import { t } from '@/i18n/index.js'
import { EDITOR_KEY } from './editorState.js'
import TemplateCard from './TemplateCard.vue'

const ed = inject(EDITOR_KEY)
const body = ref(null)
const form = ref(null)

// the full field list (with the readonly notes); the ONE field the template declares as its h1 says so (and says it is the page name in the lists)
const fields = computed(() => (ed.template ? ed.template.fields : []).map((f) => (f.k === ed.h1Key ? asH1Field(f, [f.t === 'headline' ? t('editor.tpl_h1_headline') : t('editor.tpl_h1_same')]) : f)))
const pub = computed(() => (ed.published ? ed.published.fields || {} : null))
const parentTitle = computed(() => { // the line above an article's title in the list: the category or the blog it stands in
  const p = ed.page.parentId && ed.pages.find((x) => x.id === ed.page.parentId)
  return p ? p.title : ''
})

async function focusField(path) { return form.value ? form.value.focusField(path) : false }
function focusFirst(select) {
  const el = body.value && body.value.querySelector('input[type=text],input[type=date],input[type=time],input[type=email],textarea,[contenteditable="true"]')
  if (!el) return false
  el.focus({ preventScroll: true })
  if (select && el.select) el.select()
  return true
}
defineExpose({ focusField, focusFirst, body })
</script>

<template>
  <div id="sbBody" ref="body" class="sb-body">
    <TemplateCard v-if="ed.template && ed.template.card" :tpl="ed.template" :fields="ed.page.fields" :title="ed.page.title" :parent="ed.template.card.date ? parentTitle : ''" />
    <SchemaForm ref="form" :schema="fields" :model-value="ed.page.fields || {}" :published="pub" :readonly="ed.readOnly" @update:model-value="ed.updateFields($event)" />
  </div>
</template>
