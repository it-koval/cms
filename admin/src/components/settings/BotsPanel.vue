<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '@/api/index.js'
import { can } from '@/stores/app.js'
import Icon from '@/components/ui/Icon.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import ReadOnlyNote from '@/components/ui/ReadOnlyNote.vue'
import { useToast } from '@/components/ui/useToast.js'
import { useConfirm } from '@/components/ui/useConfirm.js'
import { t } from '@/i18n/index.js'

const toast = useToast()
const confirm = useConfirm()
const emit = defineEmits(['saved'])
const canEdit = computed(() => can('set:bots', 'edit'))
const channels = ['telegram', 'max']
const labels = { telegram: 'Telegram', max: 'MAX' }
const loading = ref(true)
const loadError = ref('')
const saving = ref(false)
const state = ref(null)
const tokens = reactive({ telegram: '', max: '' })
const clearing = reactive({ telegram: false, max: false })
const errors = reactive({})
const dirty = computed(() => Object.fromEntries(channels.map((key) => [key, tokens[key] !== '' || clearing[key]])))

function fill(next) {
  state.value = next
  for (const key of channels) { tokens[key] = ''; clearing[key] = false }
  Object.keys(errors).forEach((key) => delete errors[key])
}
async function load() {
  loading.value = true
  try {
    fill(await api.bots.get())
    loadError.value = ''
  } catch (error) {
    loadError.value = error.message || t('settings.bots_load_failed')
  } finally {
    loading.value = false
  }
}
onMounted(load)

function source(key) {
  if (state.value[key].source === 'admin') return t('settings.bots_source_admin')
  if (state.value[key].source === 'server') return t('settings.bots_source_server')
  return t('settings.bots_source_none')
}
function placeholder(key) {
  if (clearing[key]) return t('settings.bots_token_none')
  if (state.value[key].source === 'admin') return t('settings.bots_token_admin')
  if (state.value[key].source === 'server') return t('settings.bots_token_server')
  return t('settings.bots_token_none')
}
function typeToken(key, value) {
  tokens[key] = value
  if (value) clearing[key] = false
  delete errors[key]
}
async function clearToken(key) {
  if (!canEdit.value || saving.value) return
  if (!(await confirm({
    title: t('settings.bots_clear_title', { bot: labels[key] }),
    message: t('settings.bots_clear_message'),
    confirmLabel: t('settings.bots_clear'), cancelLabel: t('settings.cancel'), danger: true,
  }))) return
  tokens[key] = ''
  clearing[key] = true
  delete errors[key]
}
async function save(key) {
  if (!canEdit.value || saving.value || !dirty.value[key]) return
  const patch = { [key]: clearing[key] ? { clear: true } : { token: tokens[key] } }
  saving.value = true
  try {
    const next = await api.bots.save(patch)
    state.value = next
    discard(key)
    emit('saved', next)
    toast.show(t('settings.bots_saved'))
  } catch (error) {
    Object.keys(errors).forEach((key) => delete errors[key])
    Object.assign(errors, error.details && typeof error.details === 'object' ? error.details : {})
    toast.error(error.message || t('settings.bots_save_failed'))
  } finally {
    saving.value = false
  }
}
function discard(key) {
  tokens[key] = ''
  clearing[key] = false
  delete errors[key]
}
</script>

<template>
  <div class="bots">
    <ReadOnlyNote v-if="!loading && !loadError && !canEdit" :text="t('settings.bots_readonly')" />
    <div v-if="loading" class="sheet set-sec"><Skeleton :rows="4" /></div>
    <div v-else-if="loadError" class="alert err-a" role="alert"><Icon name="alert" size="s16" /><span>{{ loadError }}</span><button type="button" class="btn sm" @click="load">{{ t('settings.retry') }}</button></div>
    <template v-else>
      <section v-for="key in channels" :key="key" class="sheet set-sec" :aria-labelledby="'bots-' + key + '-heading'">
        <h2 :id="'bots-' + key + '-heading'">{{ labels[key] }}</h2>
        <p class="d">{{ t('settings.bots_intro', { bot: labels[key] }) }}</p>
        <p v-if="key === 'telegram'" class="help bots-hint">{{ t('settings.bots_telegram_hint') }} <a href="https://t.me/BotFather" target="_blank" rel="noopener noreferrer">@BotFather</a>.</p>
        <p v-else class="help bots-hint">{{ t('settings.bots_max_hint') }} <a href="https://business.max.ru/" target="_blank" rel="noopener noreferrer">business.max.ru</a>, <a href="https://dev.max.ru/" target="_blank" rel="noopener noreferrer">dev.max.ru</a>.</p>
        <div :class="['alert', state[key].ready ? 'info-a' : 'warn-a']" role="status">
          <Icon :name="state[key].ready ? 'info' : 'alert'" size="s16" />
          <span>{{ state[key].ready ? t('settings.bots_ready') : t('settings.bots_not_ready') }} {{ source(key) }}</span>
        </div>
        <div v-if="state[key].problem" class="alert warn-a bots-problem" role="status"><Icon name="alert" size="s16" /><span>{{ state[key].problem }}</span></div>
        <div :class="{ 'ro-zone': !canEdit }" :inert="!canEdit || undefined">
          <div :class="['field', { 'is-changed': tokens[key] !== '' || clearing[key], 'is-error': errors[key] }]">
            <div class="lbl">
              <label :for="'bots-' + key + '-token'">{{ t('settings.bots_token', { bot: labels[key] }) }}</label>
              <span class="mark"><span class="pmark" aria-hidden="true"><i /></span>{{ t('settings.changed') }}</span>
            </div>
            <div class="row bots-token">
              <input :id="'bots-' + key + '-token'" :value="tokens[key]" type="password" autocomplete="new-password" spellcheck="false" :disabled="saving" :placeholder="placeholder(key)" :aria-invalid="errors[key] ? 'true' : undefined" :aria-describedby="'bots-' + key + '-help'" @input="typeToken(key, $event.target.value)">
              <button v-if="canEdit && state[key].source === 'admin' && !clearing[key]" type="button" class="btn sm" :disabled="saving" @click="clearToken(key)">{{ t('settings.bots_clear') }}</button>
            </div>
            <div :id="'bots-' + key + '-help'" class="help">{{ t('settings.bots_token_help') }}<template v-if="clearing[key]"> {{ t('settings.bots_clear_pending') }}</template></div>
            <div v-if="errors[key]" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ errors[key] }}</span></div>
          </div>
        </div>
        <div v-if="canEdit" class="bots-bar">
          <button type="button" :class="['btn', 'primary', { loading: saving && dirty[key] }]" :disabled="!dirty[key] || saving" @click="save(key)"><Icon name="save" size="s16" />{{ t('settings.save') }}</button>
          <button v-if="dirty[key]" type="button" class="btn" :disabled="saving" @click="discard(key)">{{ t('settings.bots_undo') }}</button>
          <span v-if="dirty[key]" class="muted">{{ t('settings.bots_unsaved') }}</span>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.bots-bar{display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin:14px 0}
.bots-token .btn{flex:none}
.bots-problem{margin-top:8px}
.bots-hint{margin:6px 0 14px}
@media (max-width:767px){.bots-token{flex-wrap:wrap}.bots-token input{flex-basis:100%}}
</style>
