<script setup>
import { computed } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import { t } from '@/i18n/index.js'
import { isMulti, langName } from '@/utils/locales.js'

const props = defineProps({ request: { type: Object, required: true }, form: { type: Object, default: null } })
const messageKeys = ['comment', 'message', 'note', 'text']
const contacts = new Set(['name', 'phone', 'city'])
const fields = computed(() => props.form?.fields || [])
const fieldByKey = (key) => fields.value.find((field) => field.k === key)
const visible = (key) => fieldByKey(key)?.detail !== false
const label = (key, fallback) => fieldByKey(key)?.label || t(fallback)
const tel = computed(() => visible('phone') ? String(props.request.phone || '').replace(/[^\d+]/g, '') : '')

function hasValue(value) {
  return value != null && typeof value !== 'object' && String(value).trim() !== ''
}

// RequestService::present uses PHP !empty() on scalar message aliases, where '0' also counts as empty.
const messageSource = computed(() => messageKeys.find((key) => {
  const value = props.request.data?.[key]
  return value && value !== '0' && typeof value !== 'object'
}))
const messageLabel = computed(() => {
  if (messageSource.value) return visible(messageSource.value) ? label(messageSource.value, 'ui.rd_message') : null
  const aliases = messageKeys.map(fieldByKey).filter(Boolean)
  if (!aliases.length) return t('ui.rd_message')
  const emptyField = aliases.find((field) => field.detail !== false && !hasValue(props.request.data?.[field.k]))
  return emptyField?.label || null
})
const details = computed(() => {
  const rows = []
  for (const field of fields.value) {
    if (field.detail === false || contacts.has(field.k) || field.k === messageSource.value) continue
    if (!Object.hasOwn(props.request.data || {}, field.k)) continue
    const value = props.request.data[field.k]
    if (!hasValue(value)) continue
    let text = String(value)
    if (typeof value === 'boolean') text = t(value ? 'collections.yes' : 'collections.no')
    if (field.t === 'select') text = field.opts?.find(([key]) => String(key) === text)?.[1] || text
    rows.push({ key: field.k, label: field.label, text })
  }
  return rows
})
</script>

<template>
  <a v-if="tel" class="btn primary rd-call" :href="'tel:' + tel"><Icon name="phone" /><span>{{ t('ui.rd_call', { phone: request.phone }) }}</span></a>
  <dl class="kv rd-details">
    <dt>{{ t('ui.rd_number') }}</dt><dd class="num">{{ t('ui.rq_number', { id: request.id }) }}</dd>
    <template v-if="request.name && visible('name')"><dt>{{ label('name', 'ui.rd_name') }}</dt><dd>{{ request.name }}</dd></template>
    <template v-if="request.phone && visible('phone')"><dt>{{ label('phone', 'ui.rd_phone') }}</dt><dd class="num">{{ request.phone }}</dd></template>
    <template v-if="request.city && visible('city')"><dt>{{ label('city', 'ui.rd_city') }}</dt><dd>{{ request.city }}</dd></template>
    <dt>{{ t('ui.rd_form') }}</dt><dd>{{ request.place }}</dd>
    <template v-if="isMulti() && request.locale"><dt>{{ t('ui.rd_site_language') }}</dt><dd>{{ langName(request.locale) }}</dd></template>
    <template v-if="messageLabel"><dt>{{ messageLabel }}</dt><dd><template v-if="request.note">{{ request.note }}</template><span v-else class="muted">{{ t('ui.rd_no_message') }}</span></dd></template>
    <template v-for="row in details" :key="row.key">
      <dt>{{ row.label }}</dt><dd>{{ row.text }}</dd>
    </template>
  </dl>
</template>

<style scoped>
.rd-call{align-self:flex-start;max-width:100%;height:auto;min-height:40px;white-space:normal}
.rd-call span{min-width:0;overflow-wrap:anywhere}
@media (max-width:767px){.rd-call{min-height:44px;flex:none}}
.rd-details dt,.rd-details dd{min-width:0;overflow-wrap:anywhere}
.rd-details dd{white-space:pre-wrap}
</style>
