<script setup>
// Language select of the page editor top bar (multilingual sites only):   [ ● RU · Русский ⌄ ]
// Every row is one language version of THIS page with its status (dot + «Опубликована» / «Есть правки · 2» / «Черновик» / «Нет перевода»). Picking one switches the whole editor to that
// version (canvas, structure, form, undo history and publish counter are per language): setContentLocale(code), PageEditorView waits for the last autosave and re-creates the editor.
// The status of the language on screen follows the live state of the editor (ed.status); the others come from the status summary loaded with the page.
import { computed, inject, onMounted, ref } from 'vue'
import { app, setContentLocale } from '@/stores/app.js'
import { t } from '@/i18n/index.js'
import { isMulti } from '@/utils/locales.js'
import { stateOf } from '@/components/pages/langWords.js'
import LanguageSelect from '@/components/ui/LanguageSelect.vue'
import { EDITOR_KEY } from './editorState.js'

const ed = inject(EDITOR_KEY)
const sel = ref(null)
// switching re-creates the editor, so the select would lose keyboard focus: the new editor hands it back to the select
onMounted(() => {
  if (!refocus) return
  refocus = false
  if (sel.value) sel.value.focus()
})

const statuses = computed(() => Object.fromEntries(app.languages.list.map((l) => {
  let v = ed.locales[l.code] || null
  if (l.code === ed.locale) v = ed.translated && ed.page ? { status: ed.status === 'draft' ? 'draft' : 'published', changed: ed.status === 'changed', changes: ed.changes.length } : null
  return [l.code, { key: stateOf(v).key, count: v && v.changes }]
})))

function pick(code) {
  if (code === ed.locale || ed.busy) return
  refocus = true
  setContentLocale(code)
}
</script>

<script>
let refocus = false // module level: survives the re-creation of the editor
</script>

<template>
  <LanguageSelect
    v-if="isMulti()" ref="sel" :model-value="ed.locale" :languages="app.languages.list" :statuses="statuses" :label="t('editor.lang_select')" :disabled="ed.busy" @update:model-value="pick"
  />
</template>
