<script setup>
// The state of a record that has no version in the content language: what that means for the site and two ways to start (the look is the shared ui/VersionChoice).
//   <MissingVersion :cfg="cfg" :locale="'en'" :busy="''" @copy @blank />
// «Скопировать с русского» copies every field incl. photos into a DRAFT of the new language; «Начать с пустой записи» keeps only what is the same in every language
// (title, address, switches, selects, dates, photos) and leaves the texts empty. The options matrix has only the first: an empty table would have no rows to translate.
import { computed } from 'vue'
import { app } from '@/stores/app.js'
import VersionChoice from '@/components/ui/VersionChoice.vue'
import { cap, fromLang, siteIn, up, verGen } from '@/utils/langWords.js'
import { t } from '@/i18n/index.js'
import { matrixWords } from '@/schema/matrix.js'

const props = defineProps({
  cfg: { type: Object, required: true },
  locale: { type: String, required: true },
  busy: { type: String, default: '' }, // 'copy' | 'blank' while the new version is being made
})
const emit = defineEmits(['copy', 'blank'])
const main = computed(() => app.languages.main)
const matrix = computed(() => props.cfg.view === 'matrix')
const linked = computed(() => !!props.cfg.pageKind) // a product / an article has its own page: its version appears together with the record's
const options = computed(() => [
  { id: 'copy', icon: 'copy', lead: true, label: cap(t('collections.mv_copy_from', { lang: fromLang(main.value) })), hint: matrix.value ? matrixWords(props.cfg).copyHint : t('collections.mv_copy_hint') },
  ...(matrix.value ? [] : [{ id: 'blank', icon: 'doc', label: t('collections.mv_blank'), hint: t('collections.mv_blank_hint') }]),
])
</script>

<template>
  <VersionChoice
    :title="t('collections.mv_title', { version: up(verGen(locale)) })"
    :sub="t(linked ? 'collections.mv_sub_linked' : 'collections.mv_sub', { site: siteIn(locale) })"
    :options="options" :busy="busy" :note="t('collections.mv_note')" @pick="(id) => emit(id)"
  />
</template>
