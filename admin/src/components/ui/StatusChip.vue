<script setup>
// <StatusChip status="published" />   statuses: published | draft | changed | new | delivered | pending | failed | warn | ai
// draft / changed use the magenta «unpublished» colour; failed is the only red.
import { computed } from 'vue'
import Icon from './Icon.vue'
import { t } from '@/i18n/index.js'

const props = defineProps({ status: { type: String, required: true }, label: { type: String, default: '' }, count: { type: Number, default: 0 } })
const MAP = {
  published: ['pub', t('ui.status_published'), 'dot'],
  draft: ['draft', t('ui.status_draft'), 'dot'],
  changed: ['draft', t('ui.status_changed'), 'dot'],
  new: ['new', t('ui.status_new'), ''],
  delivered: ['pub', t('ui.status_delivered'), 'check'],
  pending: ['', t('ui.status_pending'), ''],
  failed: ['fail', t('ui.status_failed'), 'warn'],
  warn: ['warn', '', ''],
  ai: ['ai', '', 'sparkle'],
}
const m = computed(() => MAP[props.status] || ['', props.status, ''])
</script>

<template>
  <span :class="['chip', m[0]]">
    <span v-if="m[2] === 'dot'" class="dot" />
    <Icon v-else-if="m[2]" :name="m[2]" size="s14" />
    {{ label || m[1] }}<template v-if="count"> · {{ count }}</template>
  </span>
</template>
