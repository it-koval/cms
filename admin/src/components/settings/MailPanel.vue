<script setup>
// Настройки → Почта: the mail server the site sends letters through (area set:mail; the administrator by default). Saved at once with its own button (like «Оформление админки»).
// The password is never shown: the server says only «saved or not». An empty field keeps the saved password; «Удалить пароль» removes it.
// The saved password stays tied to its server: when the address of the server or the login changes, the password is typed again (the server insists too: 422).
// «Отправить тестовое письмо» sends a real letter NOW through the SAVED settings and shows the reason when the server refuses (the password is cut out of the text by the server).
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { api } from '@/api/index.js'
import { app, can } from '@/stores/app.js'
import Icon from '@/components/ui/Icon.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import ReadOnlyNote from '@/components/ui/ReadOnlyNote.vue'
import { useToast } from '@/components/ui/useToast.js'
import { useConfirm } from '@/components/ui/useConfirm.js'
import { fmtDT } from '@/utils/format.js'
import { t } from '@/i18n/index.js'

const toast = useToast()
const confirm = useConfirm()
const canEdit = computed(() => can('set:mail', 'edit'))

const ENCRYPTIONS = [
  { value: 'starttls', label: t('integrations.ml_enc_starttls') },
  { value: 'ssl', label: t('integrations.ml_enc_ssl') },
  { value: 'none', label: t('integrations.ml_enc_none') },
]
const PORTS = { starttls: 587, ssl: 465, none: 25 }

const loading = ref(true)
const loadError = ref('')
const saving = ref(false)
const testing = ref(false)
const state = ref(null) // the answer of the server for the saved settings
const draft = reactive({ enabled: false, host: '', port: '', encryption: 'starttls', username: '', fromAddress: '', fromName: '' })
const password = ref('') // typed now; never filled from the server
const clearPassword = ref(false) // «Удалить пароль» pressed, applied with «Сохранить»
const errors = reactive({})
const testTo = ref('')
const testResult = reactive({ ok: '', error: '' })

const FIELDS = ['enabled', 'host', 'port', 'encryption', 'username', 'fromAddress', 'fromName']
const same = (a, b) => String(a ?? '') === String(b ?? '')
const dirty = computed(() => !!state.value && (FIELDS.some((k) => !same(draft[k], state.value[k])) || password.value !== '' || clearPassword.value))
const passwordSet = computed(() => !!state.value && state.value.passwordSet && !clearPassword.value)
const SERVER_CHANGED = t('integrations.ml_server_changed')
const serverMoved = computed(() => !!state.value && passwordSet.value && password.value === ''
  && (draft.host.trim().toLowerCase() !== String(state.value.host || '').trim().toLowerCase() || draft.username !== String(state.value.username || '')))
const plainLogin = computed(() => draft.encryption === 'none' && draft.username.trim() !== '')

function fill(s) {
  state.value = s
  Object.assign(draft, { enabled: !!s.enabled, host: s.host || '', port: s.port ? String(s.port) : '', encryption: s.encryption || 'starttls', username: s.username || '', fromAddress: s.fromAddress || '', fromName: s.fromName || '' })
  password.value = ''
  clearPassword.value = false
}

async function load() {
  loading.value = true
  try {
    fill(await api.mail.get())
    loadError.value = ''
  } catch (e) {
    loadError.value = e.message || t('integrations.ml_load_failed')
  } finally {
    loading.value = false
  }
}
onMounted(() => { testTo.value = (app.user && app.user.email) || ''; load() })

// another kind of protection: the port that was the usual one of the old kind follows to the usual one of the new kind (a port typed by hand stays)
watch(() => draft.encryption, (now, was) => {
  if (was && (draft.port === '' || String(draft.port) === String(PORTS[was]))) draft.port = String(PORTS[now])
})

watch([password, () => draft.host, () => draft.username], () => { delete errors.password })

const emailLike = (v) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(v).trim())
function check() {
  Object.keys(errors).forEach((k) => delete errors[k])
  if (draft.host && !/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/.test(draft.host.trim())) errors.host = t('integrations.ml_err_host_format')
  if (draft.port !== '' && !(/^\d+$/.test(String(draft.port).trim()) && +draft.port >= 1 && +draft.port <= 65535)) errors.port = t('integrations.ml_err_port')
  if (draft.fromAddress && !emailLike(draft.fromAddress)) errors.fromAddress = t('integrations.ml_err_from_typo')
  if (serverMoved.value) errors.password = SERVER_CHANGED
  if (draft.enabled) {
    if (!draft.host.trim()) errors.host ||= t('integrations.ml_err_host_required')
    if (!draft.fromAddress.trim()) errors.fromAddress ||= t('integrations.ml_err_from_required')
  }
  return Object.keys(errors).length === 0
}

async function save() {
  if (saving.value || !dirty.value || !canEdit.value) return
  if (!check()) { toast.error(t('integrations.ml_save_invalid')); return }
  saving.value = true
  testResult.ok = testResult.error = ''
  try {
    const body = { ...draft, port: draft.port === '' ? '' : Number(draft.port) }
    if (password.value) body.password = password.value
    if (clearPassword.value) body.clearPassword = true
    fill(await api.mail.save(body))
    toast.show(t('integrations.ml_saved'))
  } catch (e) {
    Object.keys(errors).forEach((k) => delete errors[k])
    Object.assign(errors, (e && e.details && typeof e.details === 'object') ? e.details : {})
    toast.error((e && e.message) || t('integrations.ml_save_failed'))
  } finally {
    saving.value = false
  }
}

function undo() { fill(state.value); Object.keys(errors).forEach((k) => delete errors[k]) }

async function dropPassword() {
  if (!(await confirm({
    title: t('integrations.ml_drop_title'),
    message: t('integrations.ml_drop_message'),
    confirmLabel: t('integrations.ml_drop_btn'), cancelLabel: t('integrations.btn_keep'), danger: true,
  }))) return
  clearPassword.value = true
  password.value = ''
}

async function sendTest() {
  if (testing.value || dirty.value) return
  testing.value = true
  testResult.ok = testResult.error = ''
  try {
    const r = await api.mail.sendTest(testTo.value.trim())
    testResult.ok = t('integrations.ml_test_ok', { to: r.to })
    state.value = await api.mail.get()
  } catch (e) {
    testResult.error = (e && e.message) || t('integrations.ml_test_failed')
    try { state.value = await api.mail.get() } catch (x) { /* the screen stays as it is */ }
  } finally {
    testing.value = false
  }
}

const sourceText = computed(() => {
  const s = state.value
  if (!s) return ''
  if (!s.configured) return s.problem || t('integrations.ml_not_configured')
  return s.source === 'env' ? t('integrations.ml_source_env') : t('integrations.ml_source_own')
})
const last = computed(() => {
  const l = state.value && state.value.last
  if (!l) return { text: '', bad: false }
  if (l.error && (!l.okAt || (l.errorAt || '') >= l.okAt)) return { text: t('integrations.ml_last_failed', { time: fmtDT(l.errorAt), error: l.error }), bad: true }
  return { text: l.okAt ? t('integrations.ml_last_ok', { time: fmtDT(l.okAt) }) : '', bad: false }
})
const lastText = computed(() => last.value.text)
const lastBad = computed(() => last.value.bad)
</script>

<template>
  <div class="ml">
    <ReadOnlyNote v-if="!loading && !loadError && !canEdit" :text="t('integrations.ml_readonly')" />
    <div v-if="loading" class="sheet set-sec"><Skeleton :rows="6" /></div>
    <div v-else-if="loadError" class="alert err-a"><Icon name="alert" size="s16" /><span>{{ loadError }}</span><button type="button" class="btn sm" @click="load">{{ t('integrations.btn_retry') }}</button></div>
    <template v-else>
      <section class="sheet set-sec" aria-labelledby="ml-h">
        <h2 id="ml-h">{{ t('integrations.ml_title') }}</h2>
        <p class="d">{{ t('integrations.ml_intro') }}</p>
        <div :class="['alert', state.configured ? 'info-a' : 'warn-a']" role="status"><Icon :name="state.configured ? 'info' : 'alert'" size="s16" /><span>{{ sourceText }}</span></div>
        <div v-if="lastText" :class="['alert', lastBad ? 'err-a' : 'info-a', 'ml-last']" role="status"><Icon :name="lastBad ? 'alert' : 'info'" size="s16" /><span>{{ lastText }}</span></div>

        <div :class="{ 'ro-zone': !canEdit }" :inert="!canEdit || undefined">
          <div :class="['field', 'ml-first', { 'is-changed': !same(draft.enabled, state.enabled) }]">
            <div class="lbl">
              <label class="sw"><input v-model="draft.enabled" type="checkbox"><span class="tr" /><span>{{ t('integrations.ml_enabled_label') }}</span></label>
              <span class="mark"><span class="pmark" aria-hidden="true"><i /></span>{{ t('integrations.changed') }}</span>
            </div>
            <div class="help">{{ t('integrations.ml_enabled_help') }}</div>
          </div>

          <div :class="['field', { 'is-error': errors.host, 'is-changed': !same(draft.host, state.host) }]">
            <div class="lbl"><label for="ml-host">{{ t('integrations.ml_host_label') }}</label><span class="mark"><span class="pmark" aria-hidden="true"><i /></span>{{ t('integrations.changed') }}</span></div>
            <input id="ml-host" v-model="draft.host" type="text" autocomplete="off" spellcheck="false" placeholder="smtp.example.com" :aria-invalid="errors.host ? 'true' : undefined">
            <div class="help">{{ t('integrations.ml_host_help') }}</div>
            <div v-if="errors.host" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ errors.host }}</span></div>
          </div>

          <div class="ml-two">
            <div :class="['field', { 'is-error': errors.encryption, 'is-changed': !same(draft.encryption, state.encryption) }]">
              <div class="lbl"><label for="ml-enc">{{ t('integrations.ml_enc_label') }}</label><span class="mark"><span class="pmark" aria-hidden="true"><i /></span>{{ t('integrations.changed') }}</span></div>
              <select id="ml-enc" v-model="draft.encryption">
                <option v-for="o in ENCRYPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
              </select>
              <div class="help">{{ t('integrations.ml_enc_help') }}</div>
              <div v-if="plainLogin" class="alert warn-a" role="status"><Icon name="alert" size="s16" /><span>{{ t('integrations.ml_plain_warn') }}</span></div>
            </div>
            <div :class="['field', { 'is-error': errors.port, 'is-changed': !same(draft.port, state.port) }]">
              <div class="lbl"><label for="ml-port">{{ t('integrations.ml_port_label') }}</label><span class="mark"><span class="pmark" aria-hidden="true"><i /></span>{{ t('integrations.changed') }}</span></div>
              <input id="ml-port" v-model="draft.port" type="text" inputmode="numeric" autocomplete="off" :placeholder="String(PORTS[draft.encryption] || 587)" :aria-invalid="errors.port ? 'true' : undefined">
              <div class="help">{{ t('integrations.ml_port_help') }}</div>
              <div v-if="errors.port" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ errors.port }}</span></div>
            </div>
          </div>

          <div :class="['field', { 'is-error': errors.username, 'is-changed': !same(draft.username, state.username) }]">
            <div class="lbl"><label for="ml-user">{{ t('integrations.ml_user_label') }}</label><span class="mark"><span class="pmark" aria-hidden="true"><i /></span>{{ t('integrations.changed') }}</span></div>
            <input id="ml-user" v-model="draft.username" type="text" autocomplete="off" spellcheck="false" :aria-invalid="errors.username ? 'true' : undefined">
            <div class="help">{{ t('integrations.ml_user_help') }}</div>
            <div v-if="errors.username" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ errors.username }}</span></div>
          </div>

          <div :class="['field', { 'is-error': errors.password, 'is-changed': password !== '' || clearPassword }]">
            <div class="lbl"><label for="ml-pass">{{ t('integrations.ml_pass_label') }}</label><span class="mark"><span class="pmark" aria-hidden="true"><i /></span>{{ t('integrations.changed') }}</span></div>
            <div class="row">
              <input id="ml-pass" v-model="password" type="password" autocomplete="new-password" :placeholder="passwordSet ? t('integrations.ml_pass_saved_ph') : t('integrations.ml_pass_empty_ph')" :aria-invalid="errors.password ? 'true' : undefined">
              <button v-if="passwordSet" type="button" class="btn sm" @click="dropPassword">{{ t('integrations.ml_drop_btn') }}</button>
            </div>
            <div class="help">{{ t('integrations.ml_pass_help') }}<template v-if="clearPassword"> {{ t('integrations.ml_pass_clear_note') }}</template></div>
            <div v-if="serverMoved && !errors.password" class="help">{{ t('integrations.ml_pass_moved_help') }}</div>
            <div v-if="errors.password" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ errors.password }}</span></div>
          </div>

          <div :class="['field', { 'is-error': errors.fromAddress, 'is-changed': !same(draft.fromAddress, state.fromAddress) }]">
            <div class="lbl"><label for="ml-from">{{ t('integrations.ml_from_label') }}</label><span class="mark"><span class="pmark" aria-hidden="true"><i /></span>{{ t('integrations.changed') }}</span></div>
            <input id="ml-from" v-model="draft.fromAddress" type="text" inputmode="email" autocomplete="off" spellcheck="false" placeholder="site@example.com" :aria-invalid="errors.fromAddress ? 'true' : undefined">
            <div class="help">{{ t('integrations.ml_from_help') }}</div>
            <div v-if="errors.fromAddress" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ errors.fromAddress }}</span></div>
          </div>

          <div :class="['field', { 'is-error': errors.fromName, 'is-changed': !same(draft.fromName, state.fromName) }]">
            <div class="lbl"><label for="ml-name">{{ t('integrations.ml_name_label') }}</label><span class="mark"><span class="pmark" aria-hidden="true"><i /></span>{{ t('integrations.changed') }}</span></div>
            <input id="ml-name" v-model="draft.fromName" type="text" autocomplete="off" :placeholder="t('integrations.ml_name_ph')" :aria-invalid="errors.fromName ? 'true' : undefined">
            <div class="help">{{ t('integrations.ml_name_help') }}</div>
            <div v-if="errors.fromName" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ errors.fromName }}</span></div>
          </div>
        </div>

        <div v-if="canEdit" class="ml-bar">
          <button type="button" :class="['btn', 'primary', { loading: saving }]" :disabled="!dirty || saving" @click="save"><Icon name="save" size="s16" />{{ t('integrations.btn_save') }}</button>
          <button v-if="dirty" type="button" class="btn" :disabled="saving" @click="undo">{{ t('integrations.ml_undo') }}</button>
          <span v-if="dirty" class="muted">{{ t('integrations.ml_unsaved') }}</span>
        </div>
      </section>

      <section v-if="canEdit" class="sheet set-sec" aria-labelledby="ml-t">
        <h2 id="ml-t">{{ t('integrations.ml_test_title') }}</h2>
        <p class="d">{{ t('integrations.ml_test_intro') }}</p>
        <div class="field">
          <div class="lbl"><label for="ml-to">{{ t('integrations.ml_to_label') }}</label></div>
          <div class="row">
            <input id="ml-to" v-model="testTo" type="text" inputmode="email" autocomplete="off" spellcheck="false" :placeholder="t('integrations.ml_to_ph')">
            <button type="button" :class="['btn', { loading: testing }]" :disabled="dirty || testing || !testTo.trim()" @click="sendTest"><Icon name="send" size="s14" />{{ t('integrations.ml_test_btn') }}</button>
          </div>
          <div v-if="dirty" class="help">{{ t('integrations.ml_test_save_first') }}</div>
        </div>
        <div v-if="testResult.ok" class="alert info-a" role="status"><Icon name="info" size="s16" /><span>{{ testResult.ok }}</span></div>
        <div v-if="testResult.error" class="alert err-a" role="alert"><Icon name="alert" size="s16" /><span>{{ testResult.error }}</span></div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.ml-last{margin-top:8px}
.ml-first{margin-top:14px}
.ml-two{display:grid;grid-template-columns:minmax(0,1fr) 180px;gap:14px}
.ml-bar{display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin:14px 0 4px}
.field .row .btn{flex:none}
@media (max-width:767px){.ml-two{grid-template-columns:1fr}}
</style>
