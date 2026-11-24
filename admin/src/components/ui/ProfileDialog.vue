<script setup>
// «Мой профиль»: name and e-mail of the person at the screen, «Сменить пароль» (a form: current password, new, repeat), «Двухэтапный вход» (TwoFactorPanel), «Мои токены для ИИ-ассистентов» (MyTokens), the role shown read-only (an administrator changes it in Настройки → Пользователи).
import { computed, reactive, ref, watch } from 'vue'
import { api } from '@/api/index.js'
import { app, refreshCurrentUser } from '@/stores/app.js'
import Modal from './Modal.vue'
import Icon from './Icon.vue'
import Avatar from './Avatar.vue'
import TwoFactorPanel from '@/components/profile/TwoFactorPanel.vue'
import MyTokens from '@/components/profile/MyTokens.vue'
import { useToast } from './useToast.js'
import { useConfirm } from './useConfirm.js'
import { locales, setLocale, t } from '@/i18n/index.js'

const props = defineProps({ open: { type: Boolean, default: false } })
const emit = defineEmits(['update:open'])
const toast = useToast()
const confirm = useConfirm()

const form = reactive({ name: '', email: '' })
const submitted = ref(false)
const busy = ref('')
const serverError = ref('')

watch(() => props.open, (o) => {
  if (!o || !app.user) return
  Object.assign(form, { name: app.user.name, email: app.user.email })
  submitted.value = false
  serverError.value = ''
})

const dirty = computed(() => !!app.user && (form.name.trim() !== app.user.name || form.email.trim() !== app.user.email))
const errors = computed(() => ({
  name: form.name.trim() ? '' : t('ui.profile_err_name'),
  email: !form.email.trim() ? t('ui.profile_err_email') : /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim()) ? '' : t('ui.profile_err_email_typo'),
}))

let closing = false
async function requestClose(value = false) {
  if (value || busy.value || closing) return
  closing = true
  try {
    if (dirty.value && !(await confirm({ title: t('settings.leave_title'), message: t('ui.profile_unsaved'), confirmLabel: t('settings.leave_confirm'), cancelLabel: t('settings.stay'), danger: true }))) return
    emit('update:open', false)
  } finally { closing = false }
}

async function save() {
  submitted.value = true
  serverError.value = ''
  if (errors.value.name || errors.value.email || !dirty.value) return
  busy.value = 'save'
  try {
    await api.users.update(app.user.id, { name: form.name, email: form.email })
    await refreshCurrentUser()
    emit('update:open', false)
    toast.show(t('ui.profile_saved'))
  } catch (e) {
    serverError.value = e.message || t('ui.profile_save_failed')
  } finally {
    busy.value = ''
  }
}

// «Язык интерфейса»: for every person, for himself; saved at once, and the page reloads in the new language (a language never changes under a running screen)
const localeError = ref('')
async function changeLocale(code) {
  busy.value = 'locale'
  localeError.value = ''
  try {
    await setLocale(code || null)
  } catch (e) {
    localeError.value = e.message || t('i18n.language_failed')
    busy.value = ''
  }
}

// «Сменить пароль»: the form opens under the button; the server checks the current password (five tries in 15 minutes) and the length of the new one
const MIN_PASSWORD = 10
const pw = reactive({ open: false, current: '', password: '', repeat: '', submitted: false, error: '', server: {} })
const pwErrors = computed(() => ({
  current: pw.current ? '' : t('ui.profile_pw_current_required'),
  password: pw.password.length >= MIN_PASSWORD ? '' : t('ui.profile_pw_too_short', { count: MIN_PASSWORD }),
  repeat: pw.repeat === pw.password ? '' : t('ui.profile_pw_mismatch'),
}))
const pwShown = (k) => (pw.submitted ? pwErrors.value[k] || pw.server[k] || '' : '')
function openPassword() {
  Object.assign(pw, { open: true, current: '', password: '', repeat: '', submitted: false, error: '', server: {} })
}
async function changePassword() {
  pw.submitted = true
  pw.error = ''
  pw.server = {}
  if (pwErrors.value.current || pwErrors.value.password || pwErrors.value.repeat) return
  busy.value = 'pw'
  try {
    await api.users.changePassword({ current: pw.current, password: pw.password, repeat: pw.repeat })
    pw.open = false
    Object.assign(pw, { current: '', password: '', repeat: '' })
    toast.show(t('ui.profile_pw_changed'))
  } catch (e) {
    const d = (e.details && typeof e.details === 'object') ? e.details : {}
    pw.server = { current: d.current || '', password: d.password || '', repeat: d.repeat || '' }
    if (!pw.server.current && !pw.server.password && !pw.server.repeat) pw.error = e.message || t('ui.profile_pw_failed')
  } finally {
    busy.value = ''
  }
}
</script>

<template>
  <Modal v-if="app.user" :open="open" :title="t('ui.profile_title')" :width="480" initial-focus="#pf-name" @update:open="requestClose">
    <div class="pf-head">
      <Avatar :name="form.name || app.user.name" :size="48" />
      <div class="pf-id"><b>{{ app.user.roleName }}</b><span class="muted">{{ app.siteInfo.name }}</span></div>
    </div>
    <form id="pf-form" class="pf-form" novalidate @submit.prevent="save">
      <div :class="['field', { 'is-error': submitted && !!errors.name }]">
        <div class="lbl"><label for="pf-name">{{ t('ui.profile_name') }}</label></div>
        <input id="pf-name" v-model="form.name" type="text" maxlength="60" autocomplete="off" :aria-invalid="submitted && errors.name ? 'true' : undefined" :aria-describedby="submitted && errors.name ? 'pf-name-error' : undefined">
        <div v-if="submitted && errors.name" id="pf-name-error" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ errors.name }}</span></div>
      </div>
      <div :class="['field', { 'is-error': submitted && !!errors.email }]">
        <div class="lbl"><label for="pf-email">{{ t('ui.profile_email') }}</label></div>
        <input id="pf-email" v-model="form.email" type="text" inputmode="email" maxlength="80" autocomplete="off" :aria-invalid="submitted && errors.email ? 'true' : undefined" :aria-describedby="submitted && errors.email ? 'pf-email-error' : undefined">
        <div v-if="submitted && errors.email" id="pf-email-error" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ errors.email }}</span></div>
      </div>
      <div class="field">
        <div class="lbl"><label for="pf-role">{{ t('ui.profile_role') }}</label></div>
        <input id="pf-role" type="text" :value="app.user.roleName" disabled>
        <div class="help">{{ t('ui.profile_role_help') }}</div>
      </div>
      <div v-if="serverError" class="alert err-a" role="alert"><Icon name="alert" size="s16" /><span>{{ serverError }}</span></div>
    </form>
    <div class="pf-pw">
      <div class="pf-pw-row">
        <div class="pf-t"><b>{{ t('ui.profile_password') }}</b><span>{{ t('ui.profile_password_hint') }}</span></div>
        <button v-if="!pw.open" type="button" class="btn sm" @click="openPassword">{{ t('ui.profile_pw_change') }}</button>
      </div>
      <form v-if="pw.open" id="pf-pw-form" class="pf-form pf-pw-form" novalidate @submit.prevent="changePassword">
        <div :class="['field', { 'is-error': !!pwShown('current') }]">
          <div class="lbl"><label for="pf-pw-current">{{ t('ui.profile_pw_current') }}</label></div>
          <input id="pf-pw-current" v-model="pw.current" type="password" autocomplete="current-password" :aria-invalid="pwShown('current') ? 'true' : undefined">
          <div v-if="pwShown('current')" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ pwShown('current') }}</span></div>
        </div>
        <div :class="['field', { 'is-error': !!pwShown('password') }]">
          <div class="lbl"><label for="pf-pw-new">{{ t('ui.profile_pw_new') }}</label></div>
          <input id="pf-pw-new" v-model="pw.password" type="password" autocomplete="new-password" :aria-invalid="pwShown('password') ? 'true' : undefined">
          <div class="help">{{ t('ui.profile_pw_help', { count: MIN_PASSWORD }) }}</div>
          <div v-if="pwShown('password')" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ pwShown('password') }}</span></div>
        </div>
        <div :class="['field', { 'is-error': !!pwShown('repeat') }]">
          <div class="lbl"><label for="pf-pw-repeat">{{ t('ui.profile_pw_repeat') }}</label></div>
          <input id="pf-pw-repeat" v-model="pw.repeat" type="password" autocomplete="new-password" :aria-invalid="pwShown('repeat') ? 'true' : undefined">
          <div v-if="pwShown('repeat')" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ pwShown('repeat') }}</span></div>
        </div>
        <div v-if="pw.error" class="alert err-a" role="alert"><Icon name="alert" size="s16" /><span>{{ pw.error }}</span></div>
        <div class="pf-pw-btns">
          <button type="submit" :class="['btn', 'primary', 'sm', { loading: busy === 'pw' }]">{{ t('ui.profile_pw_save') }}</button>
          <button type="button" class="btn sm" @click="pw.open = false">{{ t('ui.cancel') }}</button>
        </div>
      </form>
    </div>
    <div v-if="locales().length > 1" class="pf-2fa">
      <div class="pf-form">
        <div class="field">
          <div class="lbl"><label for="pf-locale">{{ t('i18n.language_label') }}</label></div>
          <select id="pf-locale" :value="app.user.localePreference || ''" :disabled="busy === 'locale'" @change="changeLocale($event.target.value)">
            <option value="">{{ t('i18n.language_default') }}</option>
            <option v-for="l in locales()" :key="l.code" :value="l.code">{{ l.name }}</option>
          </select>
          <div class="help">{{ t('i18n.language_help') }}</div>
          <div v-if="localeError" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ localeError }}</span></div>
        </div>
      </div>
    </div>
    <div class="pf-2fa"><TwoFactorPanel /></div>
    <div v-if="open" class="pf-2fa"><MyTokens /></div>
    <template #footer>
      <button type="button" class="btn" @click="requestClose(false)">{{ dirty ? t('ui.cancel') : t('ui.close') }}</button>
      <button type="submit" form="pf-form" :class="['btn', 'primary', { loading: busy === 'save' }]" :disabled="!dirty">{{ t('ui.save') }}</button>
    </template>
  </Modal>
</template>

<style scoped>
.pf-head { display: flex; align-items: center; gap: 14px; margin-bottom: 4px; }
.pf-id { display: flex; flex-direction: column; gap: 2px; }
.pf-id .muted { font-size: var(--t-sm); }
.pf-form { display: flex; flex-direction: column; }
.pf-form .field { margin: 0 -12px; }
.pf-pw { padding: 13px 0 2px; border-top: 1px solid var(--hair-2); margin-top: 6px; }
.pf-pw-row { display: flex; align-items: center; gap: 14px; }
.pf-pw-form { margin-top: 8px; }
.pf-pw-btns { display: flex; gap: 8px; margin-top: 4px; }
.pf-t { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.pf-2fa { padding: 13px 0 2px; border-top: 1px solid var(--hair-2); margin-top: 13px; }
.pf-t b { font-weight: 600; font-size: var(--t-sm); }
.pf-t span { font-size: var(--t-sm); color: var(--ink-2); line-height: 1.4; }
</style>
