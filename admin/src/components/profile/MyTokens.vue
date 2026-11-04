<script setup>
// «Мои токены для ИИ-ассистентов» in «Мой профиль»: the keys of the person at the screen (name, what the key may do, how long it lives), the key shown ONCE after it is made
// (with «Скопировать» and the line to connect Claude Code), the list with the last use, «Отозвать». The server decides what is allowed: a key never has more rights than the person.
// While an administrator keeps MCP switched off, the server answers 403 and the section says so instead of the list.
// «Заявки» cannot share a key with «Менять черновики» / «Публиковать» (the server says `excludes` for each scope and refuses such a key with 422): the incompatible boxes are switched off and say why.
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '@/api/index.js'
import Icon from '@/components/ui/Icon.vue'
import { useToast } from '@/components/ui/useToast.js'
import { useConfirm } from '@/components/ui/useConfirm.js'
import { fmtD, fmtDT } from '@/utils/format.js'
import { t } from '@/i18n/index.js'

const toast = useToast()
const confirm = useConfirm()
const phase = ref('load') // load | off | ready | error
const message = ref('')
const info = ref({ endpoint: '', scopes: [], expires: [], tokens: [] })
const form = reactive({ open: false, name: '', scopes: ['read'], expires: 90, submitted: false, error: '', server: {} })
const made = ref(null) // { plain, name }: shown once
const onceNote = t('core.mt_made_once').split('{b}') // the bold part stands between two {b}
const busy = ref('')

async function load() {
  try {
    info.value = await api.mcp.mine()
    phase.value = 'ready'
  } catch (e) {
    message.value = e.message || t('core.mt_load_failed')
    phase.value = e.status === 403 ? 'off' : 'error'
  }
}
onMounted(load)

const scopes = computed(() => info.value.scopes)
const scopeLabel = computed(() => Object.fromEntries(scopes.value.map((s) => [s.key, s.label])))
const termLabel = (d) => (d === null ? t('core.mt_term_none') : d === 30 ? t('core.mt_term_30') : d === 90 ? t('core.mt_term_90') : d === 365 ? t('core.mt_term_year') : t('core.mt_term_days', { days: d }))
const stateText = { active: t('core.mt_state_active'), expired: t('core.mt_state_expired'), revoked: t('core.mt_state_revoked') }
const stateChip = { active: 'pub', expired: 'warn', revoked: 'fail' }
const errors = computed(() => ({
  name: form.name.trim() ? '' : t('core.mt_name_required'),
  scopes: form.scopes.length ? '' : t('core.mt_scope_required'),
}))
const shown = (k) => (form.submitted ? errors.value[k] || form.server[k] || '' : '')

// the labels of the chosen scopes that forbid this one (none: it can be chosen)
const blockedBy = (key) => {
  const s = scopes.value.find((x) => x.key === key)
  return form.scopes.filter((k) => ((s && s.excludes) || []).includes(k)).map((k) => scopeLabel.value[k] || k)
}
const scopeHint = (s) => {
  if (!s.available) return t('core.mt_no_rights')
  const by = blockedBy(s.key)
  return by.length ? t('core.mt_excluded', { names: by.map((name) => t('core.quoted', { text: name })).join(', ') }) : s.hint
}
const hasExclusions = computed(() => scopes.value.some((s) => s.excludes && s.excludes.length))

function openForm() {
  Object.assign(form, { open: true, name: '', scopes: ['read'], expires: 90, submitted: false, error: '', server: {} })
  made.value = null
}
function toggle(key, on) {
  const set = new Set(form.scopes)
  if (on) {
    set.add(key)
    if (key === 'publish') set.add('write')
    if (key === 'write' || key === 'publish') set.add('read')
  } else {
    set.delete(key)
    if (key === 'read') { set.delete('write'); set.delete('publish') }
    if (key === 'write') set.delete('publish')
  }
  form.scopes = [...set]
}

async function create() {
  form.submitted = true
  form.error = ''
  form.server = {}
  if (errors.value.name || errors.value.scopes) return
  busy.value = 'create'
  try {
    const r = await api.mcp.create({ name: form.name.trim(), scopes: form.scopes, expires: form.expires })
    made.value = { plain: r.plain, name: r.token.name }
    form.open = false
    info.value = await api.mcp.mine()
  } catch (e) {
    const d = (e.details && typeof e.details === 'object') ? e.details : {}
    form.server = { name: d.name || '', scopes: d.scopes || '', expires: d.expires || '' }
    if (!form.server.name && !form.server.scopes && !form.server.expires) form.error = e.message || t('core.mt_create_failed')
  } finally {
    busy.value = ''
  }
}

async function revoke(token) {
  if (!(await confirm({
    title: t('core.mt_revoke_title', { name: token.name }),
    message: t('core.mt_revoke_message'),
    confirmLabel: t('core.mt_revoke'), cancelLabel: t('core.mt_keep'), danger: true,
  }))) return
  busy.value = token.id
  try {
    await api.mcp.revokeMine(token.id)
    info.value = await api.mcp.mine()
    toast.show(t('core.mt_revoked'))
  } catch (e) {
    toast.error(e.message || t('core.mt_revoke_failed'))
  } finally {
    busy.value = ''
  }
}

async function copy(text, done) {
  try { await navigator.clipboard.writeText(text); toast.show(done) } catch (e) { toast.error(t('core.tf_copy_failed')) }
}
const command = computed(() => (made.value ? `claude mcp add --transport http mysite ${info.value.endpoint} --header "Authorization: Bearer ${made.value.plain}"` : ''))
</script>

<template>
  <section class="mt" aria-labelledby="mt-h">
    <div class="mt-head">
      <div class="mt-t">
        <b id="mt-h">{{ t('core.mt_title') }}</b>
        <span>{{ t('core.mt_intro') }}</span>
      </div>
      <button v-if="phase === 'ready' && !form.open" type="button" class="btn sm" @click="openForm">{{ t('core.mt_create') }}</button>
    </div>

    <div v-if="phase === 'load'" class="muted mt-note">{{ t('core.tf_loading') }}</div>
    <div v-else-if="phase === 'off'" class="alert info-a" role="status"><Icon name="info" size="s16" /><span>{{ message }}</span></div>
    <div v-else-if="phase === 'error'" class="alert err-a" role="alert"><Icon name="alert" size="s16" /><span>{{ message }}</span><button type="button" class="btn sm" @click="load">{{ t('core.retry') }}</button></div>

    <template v-else>
      <!-- the key, once -->
      <div v-if="made" class="mt-made" role="status">
        <div class="alert info-a"><Icon name="info" size="s16" /><span>{{ t('core.mt_made_head', { name: made.name }) }} {{ onceNote[0] }}<b>{{ onceNote[1] }}</b>{{ onceNote[2] }}</span></div>
        <div class="mt-box">
          <label class="sr" for="mt-plain">{{ t('core.mt_key') }}</label>
          <input id="mt-plain" type="text" readonly :value="made.plain" autocomplete="off" spellcheck="false" @focus="$event.target.select()">
          <button type="button" class="btn sm primary" @click="copy(made.plain, t('core.tf_key_copied'))"><Icon name="copy" size="s14" />{{ t('core.tf_copy') }}</button>
        </div>
        <p class="muted mt-note">{{ t('core.mt_for_claude') }}</p>
        <pre class="mt-code" tabindex="0"><code>{{ command }}</code></pre>
        <div class="mt-btns">
          <button type="button" class="btn sm" @click="copy(command, t('core.mt_command_copied'))"><Icon name="copy" size="s14" />{{ t('core.mt_copy_command') }}</button>
          <button type="button" class="btn sm" @click="made = null">{{ t('core.mt_saved_key') }}</button>
        </div>
      </div>

      <!-- new key -->
      <form v-if="form.open" class="mt-form" novalidate @submit.prevent="create">
        <div :class="['field', { 'is-error': !!shown('name') }]">
          <div class="lbl"><label for="mt-name">{{ t('core.mt_name') }}</label></div>
          <input id="mt-name" v-model="form.name" type="text" maxlength="120" autocomplete="off" :placeholder="t('core.mt_name_example')" :aria-invalid="shown('name') ? 'true' : undefined">
          <div v-if="shown('name')" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ shown('name') }}</span></div>
        </div>
        <fieldset :class="['mt-scopes', { 'is-error': !!shown('scopes') }]">
          <legend>{{ t('core.mt_scopes') }}</legend>
          <label v-for="s in scopes" :key="s.key" :class="{ off: !s.available || blockedBy(s.key).length }">
            <input type="checkbox" :checked="form.scopes.includes(s.key)" :disabled="!s.available || blockedBy(s.key).length > 0" @change="toggle(s.key, $event.target.checked)">
            <span><b>{{ s.label }}</b><small>{{ scopeHint(s) }}</small></span>
          </label>
          <p v-if="hasExclusions" class="muted mt-note">{{ t('core.mt_exclusions') }}</p>
          <div v-if="form.scopes.includes('requests')" class="alert warn-a mt-warn"><Icon name="alert" size="s16" /><span>{{ (scopes.find((s) => s.key === 'requests') || {}).warning }}</span></div>
          <div v-if="shown('scopes')" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ shown('scopes') }}</span></div>
        </fieldset>
        <div class="field">
          <div class="lbl"><label for="mt-exp">{{ t('core.mt_term') }}</label></div>
          <select id="mt-exp" v-model="form.expires">
            <option v-for="d in info.expires" :key="String(d)" :value="d">{{ termLabel(d) }}</option>
          </select>
          <div class="help">{{ t('core.mt_term_help') }}</div>
        </div>
        <div v-if="form.error" class="alert err-a" role="alert"><Icon name="alert" size="s16" /><span>{{ form.error }}</span></div>
        <div class="mt-btns">
          <button type="submit" :class="['btn', 'primary', 'sm', { loading: busy === 'create' }]">{{ t('core.mt_create') }}</button>
          <button type="button" class="btn sm" @click="form.open = false">{{ t('core.tf_cancel') }}</button>
        </div>
      </form>

      <!-- the list -->
      <ul v-if="info.tokens.length" class="mt-list" :aria-label="t('core.mt_list')">
        <li v-for="tok in info.tokens" :key="tok.id">
          <div class="mt-i">
            <b>{{ tok.name }}</b> <span class="muted num">…{{ tok.hint }}</span> <span :class="['chip', stateChip[tok.state]]">{{ stateText[tok.state] }}</span>
            <span class="muted">{{ tok.scopes.map((s) => scopeLabel[s] || s).join(', ') }} · {{ tok.lastUsedAt ? t('core.mt_last_used', { when: fmtDT(tok.lastUsedAt) }) : t('core.mt_never_used') }} · {{ tok.expiresAt ? t('core.mt_until', { date: fmtD(tok.expiresAt) }) : t('core.mt_no_term') }}</span>
          </div>
          <button v-if="tok.state === 'active'" type="button" :class="['btn sm', { loading: busy === tok.id }]" @click="revoke(tok)">{{ t('core.mt_revoke') }}</button>
        </li>
      </ul>
      <p v-else-if="!form.open && !made" class="muted mt-note">{{ t('core.mt_none') }}</p>
    </template>
  </section>
</template>

<style scoped>
.mt{display:flex;flex-direction:column;gap:12px}
.mt-head{display:flex;align-items:center;gap:14px}
.mt-t{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.mt-t b{font-weight:600;font-size:var(--t-sm)}
.mt-t span,.mt-note{font-size:var(--t-sm);color:var(--ink-2);line-height:1.4;margin:0}
.mt-made{display:flex;flex-direction:column;gap:10px}
.mt-box{display:flex;align-items:center;gap:10px}
.mt-box input{flex:1;min-width:0;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:var(--t-sm)}
.mt-code{margin:0;padding:10px 12px;background:var(--hair-2);border-radius:var(--r);overflow:auto;font:var(--t-xs)/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;white-space:pre-wrap;word-break:break-all}
.mt-btns{display:flex;flex-wrap:wrap;gap:8px}
.mt-form{display:flex;flex-direction:column;gap:6px}
.mt-form .field{margin:0 -12px}
.mt-scopes{border:0;margin:0;padding:0;display:flex;flex-direction:column;gap:8px}
.mt-scopes legend{font-size:var(--t-sm);font-weight:600;padding:0;margin-bottom:6px}
.mt-scopes label{display:flex;align-items:flex-start;gap:9px;cursor:pointer}
.mt-scopes label.off{opacity:.55;cursor:default}
.mt-scopes input{margin-top:3px}
.mt-scopes span{display:flex;flex-direction:column;gap:1px}
.mt-scopes small{font-size:var(--t-xs);color:var(--ink-2);line-height:1.35}
.mt-warn{margin:2px 0 0}
.mt-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column}
.mt-list li{display:flex;align-items:center;gap:12px;padding:9px 0;border-top:1px solid var(--hair-2)}
.mt-i{flex:1;min-width:0;display:flex;flex-wrap:wrap;align-items:center;gap:4px 8px;font-size:var(--t-sm)}
.mt-i .muted{flex-basis:100%}
</style>
