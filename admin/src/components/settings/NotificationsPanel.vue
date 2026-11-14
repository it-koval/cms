<script setup>
// Уведомления: which Telegram and MAX chats and which e-mail addresses receive the requests of each form. «Проверить» sends a fake test message to a chat.
// Data lives in the settings object: tgOn / maxOn (boolean), tgRoutes / maxRoutes ({ '*': defaultChatId, '<form name>': chatId }; MAX takes several ids with commas) and mailRoutes ({ '*': 'a@x.com, b@x.com', '<form name>': '…' });
// `mailReady` and `maxReady` (a yes / no from the server) tell whether the site can send mail and whether MAX is connected at all.
import { computed } from 'vue'
import { api } from '@/api/index.js'
import Icon from '@/components/ui/Icon.vue'
import ChatRoutesTable from './ChatRoutesTable.vue'
import { FALLBACK_ROUTE, chatIdError, emailsError, maxChatIdsError } from './settingsSchema.js'
import { can } from '@/stores/app.js'
import { same } from '@/utils/objects.js'
import { t } from '@/i18n/index.js'

const props = defineProps({
  data: { type: Object, required: true }, // draft settings
  saved: { type: Object, required: true },
  forms: { type: Array, default: () => [] }, // the site's forms: [{ key, title }]
})
const emit = defineEmits(['set'])

const on = computed(() => props.data.tgOn !== false)
const tgReady = computed(() => props.data.tgReady !== false)
const serverChat = computed(() => !!props.data.tgFallback)
const maxOn = computed(() => props.data.maxOn !== false)
const maxServerChat = computed(() => !!props.data.maxFallback)
const maxReady = computed(() => !!props.data.maxReady) // the MAX client is configured on the server (a token is set)
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
const maxChanged = computed(() => !same(!!props.data.maxOn, !!props.saved.maxOn))
const tgChanged = computed(() => !same(!!props.data.tgOn, !!props.saved.tgOn))
const formsChanged = computed(() => (props.data.forms !== false) !== (props.saved.forms !== false))
</script>

<template>
  <section class="sheet set-sec">
    <h2>{{ t('settings.notif_tg_title') }}</h2>
    <p class="d">{{ t('settings.notif_tg_desc') }}</p>
    <div v-if="!tgReady" class="alert warn-a mn-al" role="status">
      <Icon name="alert" size="s16" />
      <span>{{ t('settings.notif_tg_not_ready') }} <router-link v-if="can('set:bots')" :to="{ name: 'settings', params: { tab: 'bots' } }">{{ t('settings.notif_bots_setup') }}</router-link><template v-else>{{ t('settings.notif_bots_ask') }}</template></span>
    </div>

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

    <ChatRoutesTable
      id="tn" :routes="data.tgRoutes || {}" :saved="saved.tgRoutes || {}" :forms="forms" :on="on" :ready="tgReady" :server-chat="serverChat" :table-label="t('settings.notif_chats_table')" :chat-label="(form) => t('settings.notif_tg_chat_label', { form })"
      :col-label="t('settings.notif_col_chat')" placeholder="-1001234567890" :validate="chatIdError" :send="api.settings.sendTelegramTest" @set="(next) => emit('set', 'tgRoutes', next)"
    />
    <p class="muted tn-note">{{ t('settings.notif_tg_note') }}</p>
  </section>

  <section class="sheet set-sec" aria-labelledby="mx-h">
    <h2 id="mx-h">{{ t('settings.notif_max_title') }}</h2>
    <p class="d">{{ t('settings.notif_max_desc') }}</p>
    <div v-if="!maxReady" class="alert warn-a mn-al" role="status">
      <Icon name="alert" size="s16" />
      <span>{{ t('settings.notif_max_not_ready') }} <router-link v-if="can('set:bots')" :to="{ name: 'settings', params: { tab: 'bots' } }">{{ t('settings.notif_bots_setup') }}</router-link><template v-else>{{ t('settings.notif_bots_ask') }}</template></span>
    </div>

    <div :class="['field', 'tn-on', { 'is-changed': maxChanged }]">
      <div class="lbl">
        <label class="sw"><input type="checkbox" :checked="maxOn" @change="emit('set', 'maxOn', $event.target.checked)"><span class="tr" /><span>{{ t('settings.notif_max_on') }}</span></label>
        <span class="mark"><span class="pmark" aria-hidden="true"><i /></span>{{ t('settings.changed') }}</span>
      </div>
      <div class="help">{{ t('settings.notif_max_on_help') }}</div>
    </div>

    <ChatRoutesTable
      id="mx" :routes="data.maxRoutes || {}" :saved="saved.maxRoutes || {}" :forms="forms" :on="maxOn" :ready="maxReady" :server-chat="maxServerChat" :table-label="t('settings.notif_max_chats_table')" :chat-label="(form) => t('settings.notif_max_chat_label', { form })"
      :col-label="t('settings.notif_max_col_chat')" placeholder="-70000000000001" :validate="maxChatIdsError" :send="api.settings.sendMaxTest" @set="(next) => emit('set', 'maxRoutes', next)"
    />
    <p class="muted tn-note">{{ t('settings.notif_max_note') }}</p>
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
.tn-note{font-size:var(--t-sm);margin:10px 0 8px;max-width:64ch}
.tn-row.mn-row{grid-template-columns:minmax(0,1fr) 420px}
.mn-al{margin:6px 0 10px}
@media (max-width:767px){
  .tn-row.mn-row{grid-template-columns:minmax(0,1fr)}
}
</style>
