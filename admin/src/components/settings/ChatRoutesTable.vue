<script setup>
// The table «form → chat» of one messenger (Telegram, MAX): a row per form plus «Остальные формы» (FALLBACK_ROUTE), a «Проверить» button per row.
// The screen owns the data: `routes` ({ '*': ids, '<form key>': ids }) comes in, the whole next object goes out with 'set'.
import { computed, reactive } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import { useToast } from '@/components/ui/useToast.js'
import { FALLBACK_ROUTE, needsFallbackChat } from './settingsSchema.js'
import { t } from '@/i18n/index.js'

const props = defineProps({
  id: { type: String, required: true }, // prefix of the field ids, one per messenger
  routes: { type: Object, default: () => ({}) },
  saved: { type: Object, default: () => ({}) },
  forms: { type: Array, default: () => [] }, // [{ key, title }]
  on: { type: Boolean, default: true },
  serverChat: { type: Boolean, default: false }, // the server has chats of its own (config), so «Остальные формы» may stay empty
  ready: { type: Boolean, default: true }, // the messenger is connected on the server; without it the test is not possible
  tableLabel: { type: String, required: true },
  chatLabel: { type: Function, required: true }, // (form title) => the accessible name of the field of that row
  colLabel: { type: String, required: true },
  placeholder: { type: String, required: true }, // an example id for the «Остальные формы» row
  validate: { type: Function, required: true }, // (value) => '' | error text
  send: { type: Function, required: true }, // ({ form, chatId }) => Promise: a test message
})
const emit = defineEmits(['set'])
const toast = useToast()
const testing = reactive({})

const rows = computed(() => [{ key: FALLBACK_ROUTE, label: t('settings.notif_other_forms'), hint: props.serverChat ? t('settings.notif_hint_chat_server') : t('settings.notif_hint_chat'), main: true }, ...props.forms.map((f) => ({ key: f.key, label: f.title, hint: '', main: false }))])
const valueOf = (k) => props.routes[k] || ''
const effective = (k) => valueOf(k) || (k === FALLBACK_ROUTE ? '' : valueOf(FALLBACK_ROUTE))
const errorOf = (r) => {
  if (!props.on) return changed(r.key) ? props.validate(valueOf(r.key)) : ''
  if (r.main && props.ready && needsFallbackChat(props.routes, props.forms, props.serverChat)) return t('settings.notif_chat_required')
  return props.validate(valueOf(r.key))
}
const changed = (k) => (props.saved[k] || '') !== valueOf(k)
const canTest = (r) => props.on && props.ready && !!effective(r.key) && !props.validate(effective(r.key))

function setRoute(k, v) {
  const next = { ...props.routes, [k]: v }
  if (!v && k !== FALLBACK_ROUTE) delete next[k] // an empty override means «как у остальных форм»
  emit('set', next)
}
async function test(r) {
  if (testing[r.key]) return
  testing[r.key] = true
  const chat = effective(r.key)
  try {
    await props.send({ form: r.key, chatId: chat })
    toast.show(t('settings.notif_test_sent', { chat }))
  } catch (e) {
    toast.error(e.message || t('settings.notif_test_failed'))
  } finally {
    testing[r.key] = false
  }
}
</script>

<template>
  <div class="tn-table" role="table" :aria-label="tableLabel">
    <div class="tn-row tn-head" role="row"><span role="columnheader">{{ t('settings.notif_col_form') }}</span><span role="columnheader">{{ colLabel }}</span><span role="columnheader" class="sr">{{ t('settings.notif_col_test') }}</span></div>
    <div v-for="r in rows" :key="r.key" :class="['tn-row', { chg: changed(r.key), off: !on }]" role="row">
      <div class="tn-name" role="cell">
        <b>{{ r.label }}</b>
        <span v-if="r.hint" class="muted">{{ r.hint }}</span>
      </div>
      <div class="tn-in" role="cell">
        <label class="sr" :for="id + '-' + r.key">{{ chatLabel(r.label) }}</label>
        <input
          :id="id + '-' + r.key" type="text" :value="valueOf(r.key)" :disabled="!on" :placeholder="r.main ? (serverChat ? t('settings.notif_placeholder_server') : placeholder) : t('settings.notif_placeholder_like_others')"
          autocomplete="off" :aria-invalid="errorOf(r) ? 'true' : undefined" :aria-describedby="errorOf(r) ? id + '-error-' + r.key : undefined" @input="setRoute(r.key, $event.target.value.trim())"
        >
        <div v-if="errorOf(r)" :id="id + '-error-' + r.key" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ errorOf(r) }}</span></div>
      </div>
      <div class="tn-btn" role="cell">
        <button type="button" :class="['btn', 'sm', { loading: testing[r.key] }]" :disabled="!canTest(r)" @click="test(r)"><Icon name="send" size="s14" />{{ t('settings.notif_test') }}</button>
      </div>
    </div>
  </div>
</template>

<style>
/* not scoped: the e-mail table of NotificationsPanel wears the same rows */
.set-sec .tn-table{display:flex;flex-direction:column;margin:6px 0 4px}
.set-sec .tn-row{display:grid;grid-template-columns:minmax(0,1fr) 300px 120px;gap:14px;align-items:start;padding:10px 0;border-bottom:1px solid var(--hair-2)}
.set-sec .tn-row:last-child{border-bottom:0}
.set-sec .tn-head{font-size:var(--t-xs);font-weight:620;color:var(--ink-2);padding:6px 0;border-bottom:1px solid var(--hair)}
.set-sec .tn-name{display:flex;flex-direction:column;gap:2px;padding-top:6px}
.set-sec .tn-name b{font-weight:600}
.set-sec .tn-name span{font-size:var(--t-sm)}
.set-sec .tn-in{display:flex;flex-direction:column;gap:5px}
.set-sec .tn-in input[aria-invalid=true]{border-color:var(--red);background:#FFFAFA}
.set-sec .tn-row.chg .tn-in input:not([aria-invalid=true]){border-color:var(--mag)}
.set-sec .tn-row.chg .tn-in input:not([aria-invalid=true]):focus{box-shadow:0 0 0 3px var(--mag-100)}
.set-sec .tn-btn{padding-top:1px}
.set-sec .tn-btn .btn{width:100%}
/* phone: a form's name, its field and the check button one under another (the head row of the columns goes away) */
@media (max-width:767px){
  .set-sec .tn-row{grid-template-columns:minmax(0,1fr);gap:6px;padding:12px 0}
  .set-sec .tn-head{display:none}
  .set-sec .tn-name{padding-top:0}
  .set-sec .tn-btn .btn{width:auto}
}
</style>
