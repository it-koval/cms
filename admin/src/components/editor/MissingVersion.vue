<script setup>
// Shown instead of the canvas when the page has no version in the language on screen:
//   «Английской версии этой страницы ещё нет. На сайте её не видно на английском.»  + «Скопировать с русского» / «Начать с пустой страницы».
// Both make a DRAFT of this language (everything counts as unpublished); the page appears on the site in that language only after «Опубликовать».
import { computed, inject } from 'vue'
import VersionChoice from '@/components/ui/VersionChoice.vue'
import { toast } from '@/components/ui/useToast.js'
import { t } from '@/i18n/index.js'
import { app, setContentLocale } from '@/stores/app.js'
import { prefix } from '@/utils/locales.js'
import { versionNone } from '@/components/pages/langWords.js'
import { cap, capParam, fromLang, onLang, version } from '@/utils/langWords.js'
import { EDITOR_KEY, EDITOR_UI_KEY } from './editorState.js'

const ed = inject(EDITOR_KEY)
const ui = inject(EDITOR_UI_KEY)

const main = computed(() => app.languages.main)
const ver = computed(() => version(ed.locale))
const mainVer = computed(() => version(main.value))
const mainTitle = computed(() => ed.pageTitle)
// the address a copy would get (prefix + the same slug); the api adds -2 if it is taken
const addr = computed(() => {
  const v = ed.locales[main.value]
  return v ? prefix(v.url, ed.locale) : ''
})

// what a copy brings over: with or without the template fields, with or without the address
const copyHint = computed(() => {
  const address = addr.value
  if (ed.hasFields) return address ? t('editor.missing_copy_fields_addr', { address }) : t('editor.missing_copy_fields')
  return address ? t('editor.missing_copy_plain_addr', { address }) : t('editor.missing_copy_plain')
})

// the two ways to start
const options = computed(() => ([
  { id: 'copy', icon: 'copy', lead: true, label: cap(t('editor.missing_copy', { from: fromLang(main.value) })), hint: copyHint.value },
  { id: 'blank', icon: 'doc', label: t('editor.missing_blank'), hint: ed.hasFields ? t('editor.missing_blank_fields') : t('editor.missing_blank_plain') },
]))
const busy = computed(() => (ed.busy ? 'x' : ''))
const pick = (id) => make(id === 'copy' ? main.value : null)

async function make(from) {
  const ok = await ed.createVersion(from)
  if (!ok) return
  if (from) {
    toast.show(t('editor.missing_copied', { ver: cap(ver.value.nom) }), { undoLabel: t('editor.missing_undo'), undo: () => ui.openSettings(), timeout: 9000, owner: 'editor' })
  } else {
    toast.show(t(ed.hasFields ? 'editor.missing_blank_done_fields' : 'editor.missing_blank_done_plain', { ver: ver.value.nom }), { undoLabel: t('editor.missing_undo'), undo: () => ui.openSettings(), timeout: 9000, owner: 'editor' })
  }
}
</script>

<template>
  <VersionChoice
    class="stage-empty sheet" :title="cap(t('editor.missing_title', { ver: versionNone(ed.locale) }))" :sub="t('editor.missing_sub', { lang: onLang(ed.locale) })" :options="options" :busy="busy"
    :note="capParam('editor.missing_note', { ver: mainVer.nom, title: mainTitle }, 'ver')" @pick="pick"
  >
    <button type="button" class="btn ghost sm mv-back" @click="setContentLocale(main)">{{ t('editor.missing_back', { lang: onLang(main) }) }}</button>
  </VersionChoice>
</template>

<style scoped>
.mv-back { margin-top: 14px; }
</style>
