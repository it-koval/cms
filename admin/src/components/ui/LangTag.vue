<script setup>
// A small neutral language tag for lists: «EN». It names where something belongs (the English version of a page, a request from the English site), it is not a status.
//   <LangTag v-if="isMulti()" :code="u.locale" />      shown only on a site with 2+ languages (the caller checks). Screen readers read the language name.
import { langName, tag } from '@/utils/locales.js'
defineProps({ code: { type: String, required: true } })
</script>

<template>
  <span class="lg" :title="langName(code)"><span aria-hidden="true">{{ tag(code) }}</span><span class="sr"> ({{ langName(code) }})</span></span>
</template>

<style scoped>
.lg { display: inline-flex; align-items: center; height: 18px; padding: 0 6px; margin-left: 8px; border: 1px solid var(--hair); border-radius: 4px; background: var(--sheet-2); color: var(--ink-2); font-size: var(--t-xs); font-weight: 700; letter-spacing: .04em; line-height: 1; vertical-align: 1px; white-space: nowrap; }
</style>
