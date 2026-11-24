<script setup>
// One status chip per language of a collection row: «RU ● Опубликован», «EN ○ Нет перевода». Only for sites with 2+ languages (the caller checks isMulti()).
//   <LangChips :cfg="cfg" :row="r" />                every language with its status word
//   <LangChips :cfg="cfg" :row="r" compact />        only the language on screen has the word, the others are «RU ●» (cards are narrow); the tooltip and screen readers have the full text
// `row` is a row of api.collections.list: row.locales = { ru: { status, changed, changes } | null, en: ... }. The chip itself is the shared ui/LangChip (the same as in the pages list).
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { app } from '@/stores/app.js'
import { withLang } from '@/utils/locales.js'
import { t } from '@/i18n/index.js'
import { versionState } from '@/utils/langWords.js'
import LangChip from '@/components/ui/LangChip.vue'
import { stateWord } from './lang.js'

const props = defineProps({
  cfg: { type: Object, required: true },
  row: { type: Object, required: true },
  compact: { type: Boolean, default: false },
  interactive: { type: Boolean, default: true },
})
const router = useRouter()
const path = computed(() => `/collections/${props.cfg.key}/${props.row.id}`)

const items = computed(() => app.languages.list.map(({ code }) => {
  const s = props.row.locales ? props.row.locales[code] : null
  const state = versionState(s)
  return { code, state, word: stateWord(props.cfg.key, state, s ? s.changes : 0), full: !props.compact || code === app.locale }
}))
</script>

<template>
  <span :class="['lcs', { many: items.length > 3, passive: !interactive }]">
    <select v-if="interactive && items.length > 3" class="version-select" value="" :aria-label="`${t('collections.cl_col_languages')}: ${row[cfg.titleField] || ''}`" @change="router.push(withLang(path, $event.target.value)); $event.target.value = ''">
      <option value="" disabled>{{ t('collections.cl_col_languages') }}</option>
      <option v-for="i in items" :key="i.code" :value="i.code">{{ i.code.toUpperCase() }} · {{ i.word }}</option>
    </select>
    <span class="version-chips"><LangChip v-for="i in items" :key="i.code" :code="i.code" :state="i.state" :word="i.word" :compact="!i.full" :to="interactive ? withLang(path, i.code) : null" /></span>
  </span>
</template>

<style scoped>
.lcs { position: relative; z-index: 1; display: inline-block; min-width: 0; max-width: 100%; }
.version-select { width: 100%; min-width: 0; min-height: 44px; font: inherit; font-size: 16px; }
.version-chips { display: inline-flex; flex-wrap: wrap; align-items: center; gap: 4px; }
.many .version-chips { display: none; }
.passive .version-chips { display: inline-flex; }
.version-chips :deep(.lc-w) { display: none; }
.version-chips :deep(.lc.link) { justify-content: center; min-width: 44px; padding-inline: 6px; }
@media (min-width: 360px) {
  .version-select { display: none; }
  .many .version-chips { display: inline-flex; }
}
@media (min-width: 768px) {
  .version-chips :deep(.lc-w) { display: inline; }
}
</style>
