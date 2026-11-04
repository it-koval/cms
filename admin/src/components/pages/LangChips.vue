<script setup>
// One compact chip per language of a page row (multilingual sites only):  RU ● Опубликована   EN ● Есть правки · 2   EN ○ Нет перевода
// Each chip is a link: it opens the page in THAT language (the router switches the content language through ?lang=).
//   <LangChips :page="row" :to="pageHref(row)" />       row = a row of api.pages.list(): row.locales = { ru: {status,changed,changes,...} | null, en: ... }
import { computed } from 'vue'
import { t } from '@/i18n/index.js'
import { app } from '@/stores/app.js'
import { withLang } from '@/utils/locales.js'
import { cap, version } from '@/utils/langWords.js'
import LangChip from '@/components/ui/LangChip.vue'
import { stateOf } from './langWords.js'

const props = defineProps({ page: { type: Object, required: true }, to: { type: String, required: true } })

// a whole sentence per state: the order of the words differs between the languages
const hint = (code, s) => t(`pages.chip_hint_${s.key}`, { version: cap(version(code).nom), changes: (props.page.locales[code] || {}).changes })
const rows = computed(() => app.languages.list.map((l) => {
  const v = props.page.locales[l.code] || null
  const s = stateOf(v)
  return { code: l.code, s, word: s.key === 'changed' ? `${s.label} · ${v.changes}` : s.label }
}))
</script>

<template>
  <LangChip v-for="r in rows" :key="r.code" :code="r.code" :state="r.s.key" :word="r.word" :to="withLang(to, r.code)" :hint="hint(r.code, r.s)" />
</template>
