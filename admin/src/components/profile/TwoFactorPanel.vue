<script setup>
// «Двухэтапный вход»: the second sign-in factor (a code from an authenticator app) of the person at the screen. Lives in «Мой профиль» and, alone, on the page a
// person sees when his role demands the factor (`forced`: no way to switch it off, `done` is emitted when it is on and the recovery codes were saved).
// Phases: off → setup (QR + key, confirm with the first code) → codes (10 recovery codes, shown once) → on (new codes / switch off, both ask for the password).
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '@/api/index.js'
import { boot } from '@/api/http.js'
import { app } from '@/stores/app.js'
import Icon from '@/components/ui/Icon.vue'
import TwoFactorQr from './TwoFactorQr.vue'
import { useToast } from '@/components/ui/useToast.js'
import { t } from '@/i18n/index.js'

const props = defineProps({ forced: { type: Boolean, default: false } })
const emit = defineEmits(['done', 'changed'])
const toast = useToast()

const phase = ref('load') // load | off | setup | codes | on
const status = ref(null)
const setup = reactive({ secret: '', uri: '' })
const code = ref('')
const codes = ref([])
const saved = ref(false)
const ask = ref('') // '' | 'codes' | 'off': which action waits for the password
const password = ref('')
const busy = ref('')
const error = ref('')

const required = computed(() => props.forced || !!(status.value && status.value.required))
const keyText = computed(() => (setup.secret.match(/.{1,4}/g) || []).join(' '))
const siteName = computed(() => (app.siteInfo && app.siteInfo.name) || (boot().site && boot().site.name) || 'ITK CMS')

async function load() {
  error.value = ''
  try {
    status.value = await api.twofactor.status()
    phase.value = status.value.enabled ? 'on' : 'off'
  } catch (e) {
    error.value = e.message || t('core.tf_status_failed')
    phase.value = 'off'
  }
}
onMounted(load)

async function begin() {
  busy.value = 'setup'
  error.value = ''
  try {
    Object.assign(setup, await api.twofactor.setup())
    code.value = ''
    phase.value = 'setup'
  } catch (e) {
    error.value = e.message || t('core.tf_begin_failed')
  } finally {
    busy.value = ''
  }
}
async function cancelSetup() {
  try { await api.twofactor.disable('') } catch (e) { /* a pending secret that stays is harmless: the next start replaces it */ }
  Object.assign(setup, { secret: '', uri: '' })
  error.value = ''
  phase.value = 'off'
}
async function confirm() {
  if (!code.value.trim()) { error.value = t('core.tf_enter_code'); return }
  busy.value = 'enable'
  error.value = ''
  try {
    const r = await api.twofactor.enable(code.value.trim())
    codes.value = r.recoveryCodes
    saved.value = false
    Object.assign(setup, { secret: '', uri: '' })
    phase.value = 'codes'
    status.value = await api.twofactor.status()
    emit('changed')
  } catch (e) {
    error.value = e.message || t('core.tf_confirm_failed')
  } finally {
    busy.value = ''
  }
}
function finishCodes() {
  codes.value = []
  phase.value = 'on'
  emit('done')
}

function askFor(what) { ask.value = what; password.value = ''; error.value = '' }
async function submitPassword() {
  if (!password.value) { error.value = t('core.tf_enter_password'); return }
  busy.value = ask.value
  error.value = ''
  try {
    if (ask.value === 'codes') {
      codes.value = (await api.twofactor.recoveryCodes(password.value)).recoveryCodes
      saved.value = false
      phase.value = 'codes'
    } else {
      await api.twofactor.disable(password.value)
      toast.show(t('core.tf_disabled'))
      phase.value = 'off'
    }
    ask.value = ''
    password.value = ''
    status.value = await api.twofactor.status()
    emit('changed')
  } catch (e) {
    error.value = e.message || t('core.tf_failed')
  } finally {
    busy.value = ''
  }
}

async function copy(text, done) {
  try { await navigator.clipboard.writeText(text); toast.show(done) } catch (e) { toast.error(t('core.tf_copy_failed')) }
}
function download() {
  const text = t('core.tf_codes_file', { site: siteName.value, codes: codes.value.join('\n') })
  const url = URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' }))
  const a = document.createElement('a')
  a.href = url
  a.download = t('core.tf_codes_filename')
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
</script>

<template>
  <section class="tf" :aria-label="t('core.tf_title')">
    <div v-if="phase === 'load'" class="tf-note muted">{{ t('core.tf_loading') }}</div>

    <!-- off -->
    <div v-else-if="phase === 'off'" class="tf-row">
      <div class="tf-t">
        <b>{{ t('core.tf_title') }}</b>
        <span v-if="required">{{ t('core.tf_off_required') }}</span>
        <span v-else>{{ t('core.tf_off_optional') }}</span>
      </div>
      <button type="button" :class="['btn', required ? 'primary' : 'sm', { loading: busy === 'setup' }]" @click="begin">{{ t('core.tf_enable') }}</button>
    </div>

    <!-- setup -->
    <div v-else-if="phase === 'setup'" class="tf-setup">
      <b class="tf-h">{{ t('core.tf_setting_up') }}</b>
      <ol class="tf-steps">
        <li>{{ t('core.tf_step_install') }}</li>
        <li>
          {{ t('core.tf_step_scan') }}
          <div class="tf-qr">
            <TwoFactorQr :text="setup.uri" :size="176" :label="t('core.tf_qr_label')" />
            <div class="tf-key">
              <span class="muted">{{ t('core.tf_manual') }}</span>
              <code>{{ keyText }}</code>
              <button type="button" class="btn sm" @click="copy(setup.secret, t('core.tf_key_copied'))"><Icon name="copy" size="s14" />{{ t('core.tf_copy_key') }}</button>
            </div>
          </div>
        </li>
        <li>{{ t('core.tf_step_enter') }}</li>
      </ol>
      <form class="tf-code" novalidate @submit.prevent="confirm">
        <div :class="['field', { 'is-error': !!error }]">
          <div class="lbl"><label for="tf-code">{{ t('core.tf_code_label') }}</label></div>
          <input id="tf-code" v-model="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="000000" :aria-invalid="error ? 'true' : undefined">
          <div v-if="error" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ error }}</span></div>
        </div>
        <div class="tf-btns">
          <button type="submit" :class="['btn', 'primary', { loading: busy === 'enable' }]">{{ t('core.tf_confirm') }}</button>
          <button type="button" class="btn" @click="cancelSetup">{{ t('core.tf_cancel') }}</button>
        </div>
      </form>
    </div>

    <!-- recovery codes, shown once -->
    <div v-else-if="phase === 'codes'" class="tf-codes">
      <div class="alert info-a"><Icon name="info" size="s16" /><span>{{ t('core.tf_codes_note') }} <b>{{ t('core.tf_codes_never') }}</b></span></div>
      <ul class="tf-list" :aria-label="t('core.tf_codes_list')">
        <li v-for="c in codes" :key="c"><code>{{ c }}</code></li>
      </ul>
      <div class="tf-btns">
        <button type="button" class="btn sm" @click="copy(codes.join('\n'), t('core.tf_codes_copied'))"><Icon name="copy" size="s14" />{{ t('core.tf_copy') }}</button>
        <button type="button" class="btn sm" @click="download">{{ t('core.tf_download') }}</button>
      </div>
      <label class="tf-saved"><input v-model="saved" type="checkbox"> {{ t('core.tf_saved_check') }}</label>
      <button type="button" class="btn primary" :disabled="!saved" @click="finishCodes">{{ t('core.tf_done') }}</button>
    </div>

    <!-- on -->
    <div v-else class="tf-on">
      <div class="tf-row">
        <div class="tf-t">
          <b>{{ t('core.tf_title') }} <span class="chip pub">{{ t('core.tf_on') }}</span></b>
          <span>{{ t(status && status.recoveryLeft <= 2 ? 'core.tf_recovery_low' : 'core.tf_recovery_left', { count: status ? status.recoveryLeft : 0 }) }}</span>
        </div>
      </div>
      <div v-if="ask" class="tf-ask">
        <form novalidate @submit.prevent="submitPassword">
          <div :class="['field', { 'is-error': !!error }]">
            <div class="lbl"><label for="tf-pass">{{ ask === 'off' ? t('core.tf_ask_off') : t('core.tf_ask_codes') }}</label></div>
            <input id="tf-pass" v-model="password" type="password" autocomplete="current-password" :aria-invalid="error ? 'true' : undefined">
            <div v-if="error" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ error }}</span></div>
          </div>
          <div class="tf-btns">
            <button type="submit" :class="['btn', ask === 'off' ? 'danger' : 'primary', { loading: busy === ask }]">{{ ask === 'off' ? t('core.tf_turn_off') : t('core.tf_make_codes') }}</button>
            <button type="button" class="btn" @click="ask = ''">{{ t('core.tf_cancel') }}</button>
          </div>
        </form>
      </div>
      <div v-else class="tf-btns">
        <button type="button" class="btn sm" @click="askFor('codes')">{{ t('core.tf_new_codes') }}</button>
        <button v-if="!required" type="button" class="btn sm danger" @click="askFor('off')">{{ t('core.tf_turn_off') }}</button>
      </div>
      <p v-if="required && !ask" class="muted tf-why">{{ t('core.tf_cannot_off') }}</p>
    </div>

    <div v-if="error && phase !== 'setup' && !ask" class="alert err-a tf-err" role="alert"><Icon name="alert" size="s16" /><span>{{ error }}</span></div>
  </section>
</template>

<style scoped>
.tf{display:flex;flex-direction:column;gap:12px}
.tf-note{font-size:var(--t-sm)}
.tf-row{display:flex;align-items:center;gap:14px}
.tf-t{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.tf-t b{font-weight:600;font-size:var(--t-sm);display:flex;align-items:center;gap:8px}
.tf-t span{font-size:var(--t-sm);color:var(--ink-2);line-height:1.4}
.tf-h{font-weight:620}
.tf-steps{margin:0;padding-left:20px;display:flex;flex-direction:column;gap:12px;font-size:var(--t-sm);color:var(--ink-2);line-height:1.45}
.tf-qr{display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start;margin-top:10px}
.tf-key{display:flex;flex-direction:column;gap:8px;align-items:flex-start;flex:1;min-width:190px}
.tf-key code,.tf-list code{font:600 var(--t-md, 15px)/1.3 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.04em;color:var(--ink);word-break:break-all}
.tf-key code{padding:8px 10px;background:var(--hair-2, #eef0f2);border-radius:var(--r)}
.tf-code .field{margin:0 -12px}
.tf-code input{max-width:11ch;font-variant-numeric:tabular-nums;letter-spacing:.12em}
.tf-btns{display:flex;flex-wrap:wrap;gap:8px}
.tf-list{list-style:none;margin:0;padding:12px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 16px;background:var(--hair-2, #eef0f2);border-radius:var(--r)}
.tf-codes{display:flex;flex-direction:column;gap:12px;align-items:flex-start}
.tf-codes .alert{align-self:stretch}
.tf-saved{display:flex;align-items:center;gap:8px;font-size:var(--t-sm)}
.tf-ask form{display:flex;flex-direction:column;gap:10px}
.tf-ask .field{margin:0 -12px}
.tf-why{font-size:var(--t-sm);margin:0}
.tf-err{margin:0}
</style>
