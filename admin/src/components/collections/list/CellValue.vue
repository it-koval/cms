<script setup>
// One value of a record in a column of the table, by the type of its field. A type of a plugin may bring its own `cell(value, field)` (a function on the component) that returns the text.
//   text / number / select (the label of the option) / relation (titles of the records) / toggle («Да» / «—») / date (22.11.2026) / image (a small picture) / link (its address) / repeater (how many) / rich text (its words)
import { computed } from 'vue'
import { t } from '@/i18n/index.js'
import { fmtD, nf } from '@/utils/format.js'
import { htmlToText } from '@/utils/html.js'
import { thumbUrl } from '@/utils/media.js'
import { pluginField } from '@/plugins/state.js'
import { relationOptions } from '@/components/form/relationOptions.js'
import Icon from '@/components/ui/Icon.vue'

const props = defineProps({ field: { type: Object, required: true }, value: { default: undefined } })

const kind = computed(() => {
  const f = props.field
  if (f.t === 'image' || f.t === 'photos') return 'image'
  if (f.t === 'toggle') return 'toggle'
  return 'text'
})
const text = computed(() => {
  const f = props.field
  const v = props.value
  const custom = pluginField(f.t)
  if (custom && typeof custom.cell === 'function') return String(custom.cell(v, f) ?? '')
  if (v == null || v === '') return ''
  switch (f.t) {
    case 'number': return nf(v)
    case 'select': return ((f.opts || []).find((o) => String(o[0]) === String(v)) || [])[1] ?? String(v)
    case 'headline': return htmlToText(v).replace(/\n+/g, ' ')
    case 'textarea': return f.rich ? htmlToText(v).replace(/\n+/g, ' ') : String(v)
    case 'link': return typeof v === 'object' ? String(v.kind === 'modal' ? t('collections.call_form') : v.url || v.page || '') : String(v)
    case 'relation': {
      const opts = relationOptions(f.to)
      return [].concat(v).map((id) => (opts.find((o) => o[0] === String(id)) || [])[1] || '').filter(Boolean).join(', ')
    }
    case 'repeater': return Array.isArray(v) ? String(v.length) : ''
    case 'text':
      if (f.inputType === 'date' && /^\d{4}-\d{2}-\d{2}/.test(String(v))) return fmtD(String(v).slice(0, 10) + 'T12:00:00')
      return String(v)
    default: return typeof v === 'object' ? '' : String(v)
  }
})
const img = computed(() => {
  const v = props.value
  const first = Array.isArray(v) ? v[0] : v
  if (typeof first === 'string') return first ? { src: first } : null // a photos item added in the admin is its address
  return first && first.src ? first : null
})
</script>

<template>
  <span v-if="kind === 'toggle'" :class="['cv-t', { on: !!value }]"><Icon v-if="value" name="check" size="s16" /><template v-else>—</template><span class="sr">{{ value ? t('collections.yes') : t('collections.no') }}</span></span>
  <img v-else-if="kind === 'image'" class="cv-i" :src="img ? thumbUrl(img.src) : ''" alt="" :hidden="!img">
  <span v-else class="cv-x" :title="text">{{ text || '—' }}</span>
</template>

<style scoped>
.cv-x { display: inline-block; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: bottom; }
.cv-t { color: var(--ink-3); display: inline-flex; }
.cv-t.on { color: var(--green); }
.cv-i { width: 32px; height: 32px; object-fit: cover; border-radius: 4px; border: 1px solid var(--hair); background: var(--hair-2); vertical-align: middle; }
</style>
