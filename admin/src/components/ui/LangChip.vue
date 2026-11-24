<script setup>
// ONE chip for the state of ONE language version, the same in every list (pages, collections):  RU ● Опубликована   EN ● Есть правки · 2   EN ○ Нет перевода
//   <LangChip code="en" state="missing" word="Нет перевода" />                   a plain chip
//   <LangChip code="en" state="changed" word="Есть правки · 2" :to="route" />     with `to` it is a link to that version
//   :compact                                                                      the word is hidden (cards are narrow); the title and screen readers keep the full text
// state: published (green dot) · changed / draft (magenta dot: unpublished work) · missing (an empty ring on a plain ground: it is a choice, not a problem).
// The caller decides the words (a page is «Опубликована», a product «Опубликован»), this component decides how every language chip looks.
import { RouterLink } from 'vue-router'
import { langName, tag } from '@/utils/locales.js'

defineProps({
  code: { type: String, required: true },
  state: { type: String, default: 'published' },
  word: { type: String, required: true },
  compact: { type: Boolean, default: false },
  to: { type: [String, Object], default: null },
  hint: { type: String, default: '' }, // tooltip / accessible name (default: «English: Опубликована»)
})
</script>

<template>
  <component :is="to ? RouterLink : 'span'" v-bind="to ? { to } : {}" :class="['lc', state, { link: !!to }]" :title="hint || `${langName(code)}: ${word}`" :aria-label="to ? hint || `${langName(code)}: ${word}` : undefined">
    <span class="lc-tag" aria-hidden="true">{{ tag(code) }}</span>
    <span class="lc-dot" aria-hidden="true" />
    <span v-if="!compact" class="lc-w" :aria-hidden="to ? 'true' : undefined">{{ word }}</span>
    <span v-if="!to" class="sr">{{ langName(code) }}: {{ word }}</span>
  </component>
</template>

<style scoped>
.lc { display: inline-flex; align-items: center; gap: 5px; height: 22px; padding: 0 9px 0 8px; border-radius: 11px; font-size: var(--t-xs); font-weight: 600; white-space: nowrap; background: var(--hair-2); color: var(--ink-2); text-decoration: none; transition: box-shadow var(--dur), background var(--dur), color var(--dur); }
.lc-tag { font-weight: 700; letter-spacing: .03em; }
.lc-dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; flex: none; box-sizing: border-box; }
.lc.published { background: var(--green-50); color: var(--green); }
.lc.draft, .lc.changed { background: var(--mag-50); color: var(--accent-text-d); }
.lc.draft .lc-dot, .lc.changed .lc-dot { background: var(--mag); }
.lc.missing { background: transparent; color: var(--ink-3); box-shadow: inset 0 0 0 1px var(--ctl); }
.lc.missing .lc-tag { color: var(--ink-2); }
.lc.missing .lc-dot { background: transparent; box-shadow: inset 0 0 0 1.5px currentColor; }
.lc.link:hover { box-shadow: 0 0 0 2px var(--cy-100); text-decoration: none; }
.lc.link.missing:hover { box-shadow: inset 0 0 0 1px var(--cy), 0 0 0 2px var(--cy-100); color: var(--brand-text-d); }
.lc.link:focus-visible { outline: 2px solid var(--cy); outline-offset: 2px; }
.lc.link { min-height: 44px; box-sizing: border-box; }
</style>
