<script setup>
// One status chip per language of a collection row: «RU ● Опубликован», «EN ○ Нет перевода». Only for sites with 2+ languages (the caller checks isMulti()).
//   <LangChips :cfg="cfg" :row="r" />                every language with its status word
//   <LangChips :cfg="cfg" :row="r" compact />        only the language on screen has the word, the others are «RU ●» (cards are narrow); the tooltip and screen readers have the full text
// `row` is a row of api.collections.list: row.locales = { ru: { status, changed, changes } | null, en: ... }. The chip itself is the shared ui/LangChip (the same as in the pages list).
import { computed } from 'vue'
import { app } from '@/stores/app.js'
import { withLang } from '@/utils/locales.js'
import { versionState } from '@/utils/langWords.js'
import LangChip from '@/components/ui/LangChip.vue'
import { stateWord } from './lang.js'

const props = defineProps({
  cfg: { type: Object, required: true },
  row: { type: Object, required: true },
  compact: { type: Boolean, default: false },
  interactive: { type: Boolean, default: true },
})
const path = computed(() => `/collections/${props.cfg.key}/${props.row.id}`)

const items = computed(() => app.languages.list.map(({ code }) => {
  const s = props.row.locales ? props.row.locales[code] : null
  const state = versionState(s)
  return { code, state, word: stateWord(props.cfg.key, state, s ? s.changes : 0), full: !props.compact || code === app.locale }
}))
</script>

<template>
  <span class="lcs">
    <span class="version-chips"><LangChip v-for="i in items" :key="i.code" :code="i.code" :state="i.state" :word="i.word" :compact="!i.full" :to="interactive ? withLang(path, i.code) : null" /></span>
  </span>
</template>

<style scoped>
.lcs { position: relative; z-index: 1; display: inline-block; min-width: 0; max-width: 100%; }
.version-chips { display: inline-flex; flex-wrap: wrap; align-items: center; gap: 4px; }
.version-chips :deep(.lc-w) { display: none; }
.version-chips :deep(.lc.link) { justify-content: center; min-width: 44px; padding-inline: 6px; }
@media (min-width: 768px) {
  .version-chips :deep(.lc-w) { display: inline; }
}
</style>
