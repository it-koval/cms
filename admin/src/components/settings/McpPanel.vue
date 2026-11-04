<script setup>
// Настройки → ИИ-ассистенты (area set:mcp, administrators by default): the switch for the whole site (off by default), how to connect Claude and other assistants, and the keys of every person.
// Everything is saved at once. The keys themselves are made by each person in «Мой профиль»; here an administrator can only stop them («Отозвать»).
// While the switch is off /mcp does not exist and no key works.
import { computed, onMounted, ref } from 'vue'
import { api } from '@/api/index.js'
import { can } from '@/stores/app.js'
import Icon from '@/components/ui/Icon.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ReadOnlyNote from '@/components/ui/ReadOnlyNote.vue'
import { useToast } from '@/components/ui/useToast.js'
import { useConfirm } from '@/components/ui/useConfirm.js'
import { fmtD, fmtDT } from '@/utils/format.js'
import { t } from '@/i18n/index.js'

const toast = useToast()
const confirm = useConfirm()
const canEdit = computed(() => can('set:mcp', 'edit'))

const loading = ref(true)
const loadError = ref('')
const state = ref(null)
const busy = ref('')

async function load() {
  loading.value = true
  try {
    state.value = await api.mcp.get()
    loadError.value = ''
  } catch (e) {
    loadError.value = e.message || t('integrations.mcp_load_failed')
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function turn(on) {
  if (busy.value) return
  if (on && !(await confirm({
    title: t('integrations.mcp_on_title'),
    message: t('integrations.mcp_on_message'),
    confirmLabel: t('integrations.mcp_on_confirm'), cancelLabel: t('integrations.mcp_on_cancel'),
  }))) return
  if (!on && !(await confirm({
    title: t('integrations.mcp_off_title'),
    message: t('integrations.mcp_off_message'),
    confirmLabel: t('integrations.mcp_off_confirm'), cancelLabel: t('integrations.btn_keep'), danger: true,
  }))) return
  busy.value = 'switch'
  try {
    state.value = await api.mcp.setEnabled(on)
    toast.show(on ? t('integrations.mcp_enabled_toast') : t('integrations.mcp_disabled_toast'))
  } catch (e) {
    toast.error(e.message || t('integrations.mcp_switch_failed'))
  } finally {
    busy.value = ''
  }
}

async function revoke(token) {
  if (!(await confirm({
    title: t('integrations.mcp_revoke_title', { name: token.name }),
    message: t('integrations.mcp_revoke_message', { owner: token.owner.name || token.owner.email }),
    confirmLabel: t('integrations.mcp_revoke_btn'), cancelLabel: t('integrations.btn_keep'), danger: true,
  }))) return
  busy.value = token.id
  try {
    state.value = await api.mcp.revoke(token.id)
    toast.show(t('integrations.mcp_revoked'))
  } catch (e) {
    toast.error(e.message || t('integrations.mcp_revoke_failed'))
  } finally {
    busy.value = ''
  }
}

const scopeLabel = computed(() => Object.fromEntries((state.value ? state.value.scopes : []).map((s) => [s.key, s.label])))
const stateText = { active: t('integrations.mcp_state_active'), expired: t('integrations.mcp_state_expired'), revoked: t('integrations.mcp_state_revoked') }
const stateChip = { active: 'pub', expired: 'warn', revoked: 'fail' }

const url = computed(() => (state.value ? state.value.endpoint : ''))
const KEY = t('integrations.mcp_key_placeholder')
const connectIntro = t('integrations.mcp_connect_intro', { key: '\u0000' }).split('\u0000') // the key sits in a <code>: the sentence is one text, cut at the place
const examples = computed(() => [
  {
    id: 'code', title: 'Claude Code',
    note: t('integrations.mcp_ex_code_note'),
    text: `claude mcp add --transport http mysite ${url.value} --header "Authorization: Bearer ${KEY}"`,
  },
  {
    id: 'desktop', title: 'Claude Desktop',
    note: t('integrations.mcp_ex_desktop_note'),
    text: JSON.stringify({ mcpServers: { mysite: { command: 'npx', args: ['-y', 'mcp-remote', url.value, '--header', 'Authorization:${AUTH_HEADER}'], env: { AUTH_HEADER: `Bearer ${KEY}` } } } }, null, 2),
  },
  {
    id: 'cursor', title: 'Cursor',
    note: t('integrations.mcp_ex_cursor_note'),
    text: JSON.stringify({ mcpServers: { mysite: { url: url.value, headers: { Authorization: 'Bearer ${env:ITK_MCP_TOKEN}' } } } }, null, 2),
  },
  {
    id: 'vscode', title: 'VS Code',
    note: t('integrations.mcp_ex_vscode_note'),
    text: JSON.stringify({ servers: { mysite: { type: 'http', url: url.value, headers: { Authorization: 'Bearer ${input:itk-key}' } } }, inputs: [{ type: 'promptString', id: 'itk-key', description: t('integrations.mcp_ex_vscode_input'), password: true }] }, null, 2),
  },
  {
    id: 'claudeai', title: t('integrations.mcp_ex_claudeai_title'),
    note: t('integrations.mcp_ex_claudeai_note'),
    text: `Authorization: Bearer ${KEY}`,
  },
  {
    id: 'stdio', title: t('integrations.mcp_ex_stdio_title'),
    note: t('integrations.mcp_ex_stdio_note'),
    text: JSON.stringify({ mcpServers: { mysite: { command: 'docker', args: ['compose', '--project-directory', '/path/to/site', 'exec', '-T', '-e', 'ITK_MCP_TOKEN', 'php', 'bin/console', 'itk:mcp'], env: { ITK_MCP_TOKEN: KEY } } } }, null, 2),
  },
])

async function copy(text, done) {
  try { await navigator.clipboard.writeText(text); toast.show(done) } catch (e) { toast.error(t('integrations.mcp_copy_failed')) }
}

const groups = computed(() => {
  const tools = state.value ? state.value.tools : []
  return [
    { key: 'read', title: t('integrations.mcp_group_read'), names: tools.filter((x) => x.scope === 'read').map((x) => x.name) },
    { key: 'write', title: t('integrations.mcp_group_write'), names: tools.filter((x) => x.scope === 'write').map((x) => x.name) },
    { key: 'publish', title: t('integrations.mcp_group_publish'), names: tools.filter((x) => x.scope === 'publish').map((x) => x.name) },
    { key: 'requests', title: t('integrations.mcp_group_requests'), names: tools.filter((x) => x.scope === 'requests').map((x) => x.name) },
  ].filter((g) => g.names.length)
})
const active = computed(() => (state.value ? state.value.tokens.filter((x) => x.state === 'active').length : 0))
</script>

<template>
  <div class="mcp">
    <ReadOnlyNote v-if="!loading && !loadError && !canEdit" :text="t('integrations.mcp_readonly')" />
    <div v-if="loading" class="sheet set-sec"><Skeleton :rows="5" /></div>
    <div v-else-if="loadError" class="alert err-a"><Icon name="alert" size="s16" /><span>{{ loadError }}</span><button type="button" class="btn sm" @click="load">{{ t('integrations.btn_retry') }}</button></div>
    <template v-else>
      <section class="sheet set-sec" aria-labelledby="mcp-h">
        <h2 id="mcp-h">{{ t('integrations.mcp_title') }} <span :class="['chip', state.enabled ? 'pub' : '']">{{ state.enabled ? t('integrations.mcp_on') : t('integrations.mcp_off') }}</span></h2>
        <p class="d">{{ t('integrations.mcp_intro') }}</p>
        <div :class="['mcp-row', { 'ro-zone': !canEdit }]" :inert="!canEdit || undefined">
          <button v-if="!state.enabled" type="button" :class="['btn', 'primary', { loading: busy === 'switch' }]" @click="turn(true)">{{ t('integrations.mcp_btn_on') }}</button>
          <button v-else type="button" :class="['btn', { loading: busy === 'switch' }]" @click="turn(false)">{{ t('integrations.mcp_btn_off') }}</button>
          <span class="muted">{{ state.enabled ? t('integrations.mcp_active_keys', { count: active }) : t('integrations.mcp_off_note') }}</span>
        </div>
        <div class="alert warn-a mcp-warn"><Icon name="alert" size="s16" /><span>{{ t('integrations.mcp_warn') }}</span></div>
      </section>

      <section class="sheet set-sec" aria-labelledby="mcp-c">
        <h2 id="mcp-c">{{ t('integrations.mcp_connect_title') }}</h2>
        <p class="d">{{ connectIntro[0] }}<code>{{ KEY }}</code>{{ connectIntro[1] }}</p>
        <div class="mcp-url">
          <label class="sr" for="mcp-url">{{ t('integrations.mcp_url_label') }}</label>
          <input id="mcp-url" type="text" readonly :value="url" spellcheck="false" @focus="$event.target.select()">
          <button type="button" class="btn sm" @click="copy(url, t('integrations.mcp_url_copied'))"><Icon name="copy" size="s14" />{{ t('integrations.btn_copy') }}</button>
        </div>
        <div v-for="ex in examples" :key="ex.id" class="mcp-ex">
          <div class="mcp-ex-h"><b>{{ ex.title }}</b><button type="button" class="btn sm" @click="copy(ex.text, t('integrations.mcp_copied'))"><Icon name="copy" size="s14" />{{ t('integrations.btn_copy') }}</button></div>
          <p class="muted">{{ ex.note }}</p>
          <pre class="mcp-code" tabindex="0"><code>{{ ex.text }}</code></pre>
        </div>
        <p v-if="state.toolsError" class="mcp-tools-error" role="alert">{{ t('integrations.mcp_tools_broken', { error: state.toolsError }) }}</p>
        <details v-if="groups.length" class="mcp-tools">
          <summary>{{ t('integrations.mcp_tools_summary', { count: state.tools.length }) }}</summary>
          <dl>
            <template v-for="g in groups" :key="g.key"><dt>{{ g.title }}</dt><dd>{{ g.names.join(', ') }}</dd></template>
          </dl>
        </details>
      </section>

      <section class="sheet set-sec" aria-labelledby="mcp-k">
        <h2 id="mcp-k">{{ t('integrations.mcp_keys_title') }}</h2>
        <p class="d">{{ t('integrations.mcp_keys_intro') }}</p>
        <div v-if="!state.tokens.length" class="mcp-empty"><EmptyState icon="key" :title="t('integrations.mcp_empty_title')" :text="t('integrations.mcp_empty_text')" /></div>
        <div v-else class="mcp-tbl">
          <table class="t t-stack" :aria-label="t('integrations.mcp_table_aria')">
            <thead><tr><th>{{ t('integrations.mcp_col_key') }}</th><th>{{ t('integrations.mcp_col_owner') }}</th><th>{{ t('integrations.mcp_col_scopes') }}</th><th>{{ t('integrations.mcp_col_last') }}</th><th>{{ t('integrations.mcp_col_expires') }}</th><th /></tr></thead>
            <tbody>
              <tr v-for="tok in state.tokens" :key="tok.id">
                <td class="ts-main"><b>{{ tok.name }}</b> <span class="muted num">…{{ tok.hint }}</span><br><span :class="['chip', stateChip[tok.state]]">{{ stateText[tok.state] }}</span></td>
                <td class="ts-sub" :data-label="t('integrations.mcp_col_owner')">{{ tok.owner.name || tok.owner.email }}<template v-if="tok.owner.blocked"> <span class="chip fail">{{ t('integrations.mcp_blocked') }}</span></template></td>
                <td class="ts-line" :data-label="t('integrations.mcp_col_scopes')">{{ tok.scopes.map((s) => scopeLabel[s] || s).join(', ') }}</td>
                <td class="nw num ts-sub" :data-label="t('integrations.mcp_col_last')">{{ tok.lastUsedAt ? fmtDT(tok.lastUsedAt) : t('integrations.mcp_never_used') }}</td>
                <td class="nw num ts-sub" :data-label="t('integrations.mcp_col_expires')">{{ tok.expiresAt ? fmtD(tok.expiresAt) : t('integrations.mcp_no_expiry') }}</td>
                <td class="nw ts-acts"><button v-if="canEdit && tok.state === 'active'" type="button" :class="['btn sm', { loading: busy === tok.id }]" @click="revoke(tok)">{{ t('integrations.mcp_revoke_btn') }}</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.mcp-row{display:flex;align-items:center;flex-wrap:wrap;gap:12px;margin:6px 0 12px}
.mcp-warn{margin:0 0 14px}
.mcp-url{display:flex;align-items:center;gap:10px;margin:4px 0 14px}
.mcp-url input{flex:1;min-width:0;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:var(--t-sm)}
.mcp-ex{padding:10px 0 4px;border-top:1px solid var(--hair-2)}
.mcp-ex-h{display:flex;align-items:center;justify-content:space-between;gap:10px}
.mcp-ex p{margin:4px 0 8px;font-size:var(--t-sm);max-width:70ch}
.mcp-code{margin:0 0 10px;padding:10px 12px;background:var(--hair-2);border-radius:var(--r);overflow:auto;font:var(--t-xs)/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;white-space:pre}
.mcp-tools-error{margin:6px 0 10px;font-size:var(--t-sm);color:var(--red);word-break:break-word}
.mcp-tools{margin:6px 0 10px;font-size:var(--t-sm)}
.mcp-tools summary{cursor:pointer;font-weight:600;color:var(--brand-text)}
.mcp-tools dl{display:grid;grid-template-columns:max-content minmax(0,1fr);gap:6px 14px;margin:8px 0 0}
.mcp-tools dt{font-weight:600}
.mcp-tools dd{margin:0;color:var(--ink-2);word-break:break-word}
.mcp-tbl{overflow:auto;margin:0 -22px;padding:0 22px 8px}
.mcp-empty{margin:0 -22px}
</style>
