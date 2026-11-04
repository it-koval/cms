<script setup>
// The AI agent's suggestions for this record: old text struck through, new text underlined in cyan, accept / reject.
// Nothing changes until a human accepts (DESIGN.md: the agent never publishes). Cyan, not magenta: it is a suggestion, not an unpublished change yet.
import Icon from '@/components/ui/Icon.vue'
import { htmlToText } from '@/utils/html.js'
import { t } from '@/i18n/index.js'

const props = defineProps({
  items: { type: Array, default: () => [] }, // api.proposals rows of this record
  fields: { type: Array, default: () => [] }, // schema fields: rich ones are shown as plain text
  busyId: { type: String, default: '' },
})
const emit = defineEmits(['accept', 'reject'])
const richKeys = () => new Set(props.fields.filter((f) => f.rich || f.t === 'headline').map((f) => f.k))
const view = (key, v) => (richKeys().has(key) ? htmlToText(v) : String(v ?? ''))
</script>

<template>
  <section v-if="items.length" class="agent-b" :aria-label="t('collections.pr_label')">
    <div class="ah"><Icon name="sparkle" />{{ t('collections.pr_header', { count: items.length }) }}</div>
    <div v-for="p in items" :key="p.id" class="diff">
      <div class="fl">{{ p.label }}</div>
      <del>{{ view(p.key, p.old) || t('collections.empty') }}</del>
      <ins>{{ view(p.key, p.nw) }}</ins>
      <span class="dn">{{ t('collections.pr_note', { note: p.note }) }}</span>
      <div class="da">
        <button type="button" :class="['btn sm primary', { loading: busyId === p.id }]" @click="emit('accept', p)"><Icon name="check" size="s14" />{{ t('collections.pr_accept') }}</button>
        <button type="button" class="btn sm" :disabled="busyId === p.id" @click="emit('reject', p)">{{ t('collections.pr_reject') }}</button>
      </div>
    </div>
  </section>
</template>
