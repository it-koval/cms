<script setup>
// Уведомления: which Telegram chat and which e-mail addresses receive the requests of each form. «Проверить» sends a fake test message to a chat.
// Data lives in the settings object: tgOn (boolean), tgRoutes ({ '*': defaultChatId, '<form name>': chatId }) and mailRoutes ({ '*': 'a@x.com, b@x.com', '<form name>': '…' });
// `mailReady` (a yes / no from the server) tells whether the site can send mail at all.
import { computed, reactive } from 'vue'
import { api } from '@/api/index.js'
import Icon from '@/components/ui/Icon.vue'
import { useToast } from '@/components/ui/useToast.js'
import { FALLBACK_ROUTE, chatIdError, emailsError } from './settingsSchema.js'
import { can } from '@/stores/app.js'
import { same } from '@/utils/objects.js'
import { t } from '@/i18n/index.js'

const props = defineProps({
  data: { type: Object, required: true }, // draft settings
  saved: { type: Object, required: true },
  forms: { type: Array, default: () => [] }, // the site's forms: [{ key, title }]
})
const emit = defineEmits(['set'])
const toast = useToast()
const testing = reactive({})

const on = computed(() => props.data.tgOn !== false)
const routes = computed(() => props.data.tgRoutes || {})
const serverChat = computed(() => !!props.data.tgFallback) // the server has chats of its own (config), so «Остальные формы» may stay empty
const rows = computed(() => [{ key: FALLBACK_ROUTE, label: t('settings.notif_other_forms'), hint: serverChat.value ? t('settings.notif_hint_chat_server') : t('settings.notif_hint_chat'), main: true }, ...props.forms.map((f) => ({ key: f.key, label: f.title, hint: '', main: false }))])
const savedRoutes = computed(() => props.saved.tgRoutes || {})

const valueOf = (k) => routes.value[k] || ''
const effective = (k) => valueOf(k) || (k === FALLBACK_ROUTE ? '' : valueOf(FALLBACK_ROUTE))
const errorOf = (r) => {
  if (!on.value) return ''
  if (r.main && !serverChat.value && !valueOf(r.key).trim()) return t('settings.notif_chat_required')
  return chatIdError(valueOf(r.key))
}
const changed = (k) => (savedRoutes.value[k] || '') !== valueOf(k)
const canTest = (r) => on.value && !!effective(r.key) && !chatIdError(effective(r.key))

function setRoute(k, v) {
  const next = { ...routes.value, [k]: v }
  if (!v && k !== FALLBACK_ROUTE) delete next[k] // an empty override means «как у остальных форм»
  emit('set', 'tgRoutes', next)
}
async function test(r) {
  if (testing[r.key]) return
  testing[r.key] = true
  try {
    await api.settings.sendTelegramTest({ form: r.key, chatId: effective(r.key) })
    toast.show(t('settings.notif_test_sent', { chat: effective(r.key) }))
  } catch (e) {
    toast.error(e.message || t('settings.notif_test_failed'))
  } finally {
    testing[r.key] = false
  }
}
/* ---- письма о заявках ---- */
const mails = computed(() => props.data.mailRoutes || {})
const savedMails = computed(() => props.saved.mailRoutes || {})
const mailValue = (k) => mails.value[k] || ''
const mailChanged = (k) => (savedMails.value[k] || '') !== mailValue(k)
const mailError = (k) => emailsError(mailValue(k))
function setMail(k, v) {
  const next = { ...mails.value, [k]: v }
  if (!v.trim()) delete next[k] // empty = «как у остальных форм» (for the common row: no letters)
  emit('set', 'mailRoutes', next)
}
const mailRows = computed(() => [{ key: FALLBACK_ROUTE, label: t('settings.notif_other_forms'), hint: t('settings.notif_hint_mail'), main: true }, ...props.forms.map((f) => ({ key: f.key, label: f.title, hint: '', main: false }))])
const mailReady = computed(() => !!props.data.mailReady)
const tgChanged = computed(() => !same(!!props.data.tgOn, !!props.saved.tgOn))
const formsChanged = computed(() => (props.data.forms !== false) !== (props.saved.forms !== false))
</script>

<template>
  <section class="sheet set-sec">
    <h2>{{ t('settings.notif_tg_title') }}</h2>
    <p class="d">{{ t('settings.notif_tg_desc') }}</p>

    <div :class="['field', 'tn-on', { 'is-changed': formsChanged }]">
      <div class="lbl">
        <label class="sw"><input type="checkbox" :checked="data.forms !== false" @change="emit('set', 'forms', $event.target.checked)"><span class="tr" /><span>{{ t('settings.notif_accept') }}</span></label>
        <span class="mark"><span class="pmark" aria-hidden="true"><i /></span>{{ t('settings.changed') }}</span>
      </div>
      <div class="help">{{ t('settings.notif_accept_help') }}</div>
    </div>

    <div :class="['field', 'tn-on', { 'is-changed': tgChanged }]">
      <div class="lbl">
        <label class="sw"><input type="checkbox" :checked="on" @change="emit('set', 'tgOn', $event.target.checked)"><span class="tr" /><span>{{ t('settings.notif_tg_on') }}</span></label>
        <span class="mark"><span class="pmark" aria-hidden="true"><i /></span>{{ t('settings.changed') }}</span>
      </div>
      <div class="help">{{ t('settings.notif_tg_on_help') }}</div>
    </div>

    <div class="tn-table" role="table" :aria-label="t('settings.notif_chats_table')">
      <div class="tn-row tn-head" role="row"><span role="columnheader">{{ t('settings.notif_col_form') }}</span><span role="columnheader">{{ t('settings.notif_col_chat') }}</span><span role="columnheader" class="sr">{{ t('settings.notif_col_test') }}</span></div>
      <div v-for="r in rows" :key="r.key" :class="['tn-row', { chg: changed(r.key), off: !on }]" role="row">
        <div class="tn-name" role="cell">
          <b>{{ r.label }}</b>
          <span v-if="r.hint" class="muted">{{ r.hint }}</span>
        </div>
        <div class="tn-in" role="cell">
          <label class="sr" :for="'tn-' + r.key">{{ t('settings.notif_chat_label', { form: r.label }) }}</label>
          <input
            :id="'tn-' + r.key" type="text" :value="valueOf(r.key)" :disabled="!on" :placeholder="r.main ? (serverChat ? t('settings.notif_placeholder_server') : '-1002145550192') : t('settings.notif_placeholder_like_others')"
            autocomplete="off" :aria-invalid="errorOf(r) ? 'true' : undefined" @input="setRoute(r.key, $event.target.value.trim())"
          >
          <div v-if="errorOf(r)" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ errorOf(r) }}</span></div>
        </div>
        <div class="tn-btn" role="cell">
          <button type="button" :class="['btn', 'sm', { loading: testing[r.key] }]" :disabled="!canTest(r)" @click="test(r)"><Icon name="send" size="s14" />{{ t('settings.notif_test') }}</button>
        </div>
      </div>
    </div>
    <p class="muted tn-note">{{ t('settings.notif_tg_note') }}</p>
  </section>

  <section class="sheet set-sec" aria-labelledby="mn-h">
    <h2 id="mn-h">{{ t('settings.notif_mail_title') }}</h2>
    <p class="d">{{ t('settings.notif_mail_desc') }}</p>
    <div v-if="!mailReady" class="alert warn-a mn-al" role="status">
      <Icon name="alert" size="s16" />
      <span>{{ t('settings.notif_mail_not_ready') }} <router-link v-if="can('set:mail')" :to="{ name: 'settings', params: { tab: 'mail' } }">{{ t('settings.notif_mail_setup') }}</router-link><template v-else>{{ t('settings.notif_mail_ask') }}</template></span>
    </div>
    <div class="tn-table" role="table" :aria-label="t('settings.notif_mail_table')">
      <div class="tn-row tn-head mn-row" role="row"><span role="columnheader">{{ t('settings.notif_col_form') }}</span><span role="columnheader">{{ t('settings.notif_col_mails') }}</span></div>
      <div v-for="r in mailRows" :key="r.key" :class="['tn-row', 'mn-row', { chg: mailChanged(r.key) }]" role="row">
        <div class="tn-name" role="cell">
          <b>{{ r.label }}</b>
          <span v-if="r.hint" class="muted">{{ r.hint }}</span>
        </div>
        <div class="tn-in" role="cell">
          <label class="sr" :for="'mn-' + r.key">{{ t('settings.notif_mail_label', { form: r.label }) }}</label>
          <input
            :id="'mn-' + r.key" type="text" inputmode="email" :value="mailValue(r.key)" :placeholder="r.main ? 'manager@example.com, boss@example.com' : t('settings.notif_placeholder_like_others')"
            autocomplete="off" spellcheck="false" :aria-invalid="mailError(r.key) ? 'true' : undefined" @input="setMail(r.key, $event.target.value)"
          >
          <div v-if="mailError(r.key)" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ mailError(r.key) }}</span></div>
        </div>
      </div>
    </div>
    <p class="muted tn-note">{{ t('settings.notif_mail_note') }}</p>
  </section>
</template>

<style scoped>
.tn-on{margin-bottom:6px}
.tn-table{display:flex;flex-direction:column;margin:6px 0 4px}
.tn-row{display:grid;grid-template-columns:minmax(0,1fr) 300px 120px;gap:14px;align-items:start;padding:10px 0;border-bottom:1px solid var(--hair-2)}
.tn-row:last-child{border-bottom:0}
.tn-head{font-size:var(--t-xs);font-weight:620;color:var(--ink-2);padding:6px 0;border-bottom:1px solid var(--hair)}
.tn-name{display:flex;flex-direction:column;gap:2px;padding-top:6px}
.tn-name b{font-weight:600}
.tn-name span{font-size:var(--t-sm)}
.tn-in{display:flex;flex-direction:column;gap:5px}
.tn-in input[aria-invalid=true]{border-color:var(--red);background:#FFFAFA}
.tn-row.chg .tn-in input{border-color:var(--mag)}
.tn-row.chg .tn-in input:focus{box-shadow:0 0 0 3px var(--mag-100)}
.mn-row{grid-template-columns:minmax(0,1fr) 420px}
.mn-al{margin:6px 0 10px}
/* phone: a form's name, its field and the check button one under another (the head row of the columns goes away) */
@media (max-width:767px){
  .tn-row,.mn-row{grid-template-columns:minmax(0,1fr);gap:6px;padding:12px 0}
  .tn-head{display:none}
  .tn-name{padding-top:0}
  .tn-btn .btn{width:auto}
}
.tn-btn{padding-top:1px}
.tn-btn .btn{width:100%}
.tn-note{font-size:var(--t-sm);margin:10px 0 8px;max-width:64ch}
</style>
