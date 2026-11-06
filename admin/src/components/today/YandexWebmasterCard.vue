<script setup>
// «Яндекс Вебмастер» on «Сегодня»: ИКС, pages in search, site problems and the top queries of the week. Loaded on its own (api.webmaster.status), so a slow Yandex never holds the rest of the screen;
// a role without the right (403) gets no card at all. What each state draws: yandexWebmaster.js.
import { computed, onMounted, ref } from 'vue'
import { api } from '@/api/index.js'
import Icon from '@/components/ui/Icon.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import { useConfirm } from '@/components/ui/useConfirm.js'
import { useToast } from '@/components/ui/useToast.js'
import { fmtD, fmtUpdated } from '@/utils/format.js'
import { t } from '@/i18n/index.js'
import { HOW_TO_TOKEN, bodyKind, failReasonText, isConnected, isExpired, num, periodText, problemGroups, renewsSoon } from './yandexWebmaster.js'

const confirm = useConfirm()
const toast = useToast()

const status = ref(null)
const hidden = ref(false)
const loadError = ref('')
const busy = ref('')
const token = ref('')
const formError = ref('')

function take(body) {
  status.value = body
  loadError.value = ''
}

async function load() {
  try {
    take(await api.webmaster.status())
  } catch (e) {
    if (e.status === 403) hidden.value = true
    else loadError.value = e.message || t('ui.ym_load_failed')
  }
}
onMounted(load)

const kind = computed(() => (status.value ? bodyKind(status.value) : ''))
const connected = computed(() => !!status.value && isConnected(status.value))
const canManage = computed(() => !!status.value && status.value.canManage)
const renewing = computed(() => !!status.value && renewsSoon(status.value))
const showForm = computed(() => canManage.value && (kind.value === 'connect' || kind.value === 'renew' || renewing.value))
const expired = computed(() => !!status.value && isExpired(status.value))
const problems = computed(() => (status.value && status.value.summary ? problemGroups(status.value.summary.problems) : []))
const queries = computed(() => (status.value && status.value.queries ? status.value.queries : null))

async function connect() {
  if (busy.value) return
  if (!token.value.trim()) {
    formError.value = t('ui.ym_token_empty')
    return
  }
  busy.value = 'connect'
  formError.value = ''
  try {
    take(await api.webmaster.connect(token.value.trim()))
    token.value = ''
    toast.show(t('ui.ym_connected_toast'))
  } catch (e) {
    formError.value = e.message
  } finally {
    busy.value = ''
  }
}

async function refresh() {
  if (busy.value) return
  busy.value = 'refresh'
  try {
    take(await api.webmaster.refresh())
  } catch (e) {
    toast.error(e.message)
  } finally {
    busy.value = ''
  }
}

async function disconnect() {
  if (busy.value) return
  const ok = await confirm({ title: t('ui.ym_disconnect_title'), message: t('ui.ym_disconnect_message'), confirmLabel: t('ui.ym_disconnect'), danger: true })
  if (!ok) return
  busy.value = 'disconnect'
  try {
    take(await api.webmaster.disconnect())
    formError.value = ''
    toast.show(t('ui.ym_disconnected_toast'))
  } catch (e) {
    toast.error(e.message)
  } finally {
    busy.value = ''
  }
}
</script>

<template>
  <section v-if="!hidden" class="ym" aria-labelledby="t-ym" :aria-busy="!status && !loadError">
    <h2 id="t-ym">{{ t('ui.ym_title') }}</h2>
    <div class="sheet ym-card">
      <div v-if="loadError" class="alert warn-a" role="alert">
        <Icon name="warn" size="s16" /><div class="grow">{{ loadError }}</div>
        <button type="button" class="btn sm" @click="load">{{ t('ui.retry') }}</button>
      </div>
      <Skeleton v-else-if="!status" :rows="3" />

      <template v-else>
        <div v-if="connected" class="ym-bar">
          <span class="meta">{{ status.updatedAt ? t('ui.ym_updated', { when: fmtUpdated(status.updatedAt) }) : t('ui.ym_not_updated') }}</span>
          <span class="ym-acts">
            <button type="button" class="btn sm" :disabled="!!busy" @click="refresh"><Icon name="refresh" size="s14" />{{ busy === 'refresh' ? t('ui.ym_refreshing') : t('ui.ym_refresh') }}</button>
            <a v-if="status.webmasterUrl" class="btn sm" :href="status.webmasterUrl" target="_blank" rel="noopener noreferrer"><Icon name="external" size="s14" />{{ t('ui.ym_open') }}</a>
            <button v-if="canManage" type="button" class="btn sm danger" :disabled="!!busy" @click="disconnect">{{ t('ui.ym_disconnect') }}</button>
          </span>
        </div>

        <div v-if="status.error" class="alert warn-a" role="status"><Icon name="warn" size="s16" /><div>{{ status.error }}</div></div>

        <div v-if="renewing" class="alert warn-a" role="status">
          <Icon name="clock" size="s16" />
          <div>{{ expired ? t('ui.ym_token_expired', { date: fmtD(status.tokenExpiresAt) }) : t('ui.ym_token_expiring', { date: fmtD(status.tokenExpiresAt) }) }}<template v-if="!canManage"> {{ t('ui.ym_ask_admin_renew') }}</template></div>
        </div>

        <!-- not connected / the token is not accepted -->
        <p v-if="kind === 'connect'" class="ym-text">{{ t('ui.ym_intro') }}</p>
        <p v-else-if="kind === 'ask_admin'" class="ym-text">{{ t('ui.ym_intro') }} {{ t('ui.ym_ask_admin') }}</p>
        <div v-else-if="kind === 'renew'" class="alert err-a" role="status"><Icon name="alert" size="s16" /><div>{{ t('ui.ym_token_invalid') }}</div></div>
        <p v-else-if="kind === 'ask_admin_renew'" class="ym-text"><Icon name="alert" size="s16" class="ym-ic fail" />{{ t('ui.ym_token_invalid') }} {{ t('ui.ym_ask_admin_renew') }}</p>

        <form v-if="showForm" class="ym-form" @submit.prevent="connect">
          <div :class="['field', { 'is-error': formError }]">
            <label for="ym-token">{{ t('ui.ym_token_label') }}</label>
            <div class="row">
              <input id="ym-token" v-model="token" type="password" autocomplete="off" spellcheck="false" :aria-invalid="!!formError" :aria-describedby="formError ? 'ym-token-err' : 'ym-token-help'">
              <button type="submit" class="btn primary" :disabled="!!busy">{{ busy === 'connect' ? t('ui.ym_connecting') : (kind === 'connect' ? t('ui.ym_connect') : t('ui.ym_connect_new')) }}</button>
            </div>
            <p v-if="formError" id="ym-token-err" class="ym-err" role="alert">{{ formError }}</p>
            <p v-else id="ym-token-help" class="help">
              <template v-if="status.tokenLink"><a :href="status.tokenLink" target="_blank" rel="noopener noreferrer">{{ t('ui.ym_get_token') }}</a> {{ t('ui.ym_token_help') }}</template>
              <template v-else>{{ t('ui.ym_no_client') }} <a :href="HOW_TO_TOKEN" target="_blank" rel="noopener noreferrer">{{ t('ui.ym_how_to') }}</a></template>
            </p>
          </div>
        </form>

        <!-- technical states -->
        <p v-if="kind === 'no_address'" class="ym-text">{{ t('ui.ym_no_address') }} <router-link to="/settings/general">{{ t('ui.ym_open_settings') }}</router-link></p>
        <p v-else-if="kind === 'no_http_client'" class="ym-text">{{ t('ui.ym_no_http_client') }}</p>

        <p v-else-if="kind === 'verifying'" class="ym-text"><Icon name="clock" size="s16" class="ym-ic" />{{ t('ui.ym_verifying') }}</p>
        <div v-else-if="kind === 'verification_failed'" class="alert err-a" role="status">
          <Icon name="alert" size="s16" />
          <div><b>{{ t('ui.ym_verification_failed') }}</b><br>{{ failReasonText(status.verification && status.verification.failReason) }}</div>
        </div>

        <!-- the numbers -->
        <template v-else-if="kind === 'ready'">
          <template v-if="status.summary">
            <dl class="ym-stats">
              <div><dt>{{ t('ui.ym_sqi') }}</dt><dd class="num">{{ num(status.summary.sqi) }}</dd></div>
              <div><dt>{{ t('ui.ym_searchable') }}</dt><dd class="num">{{ num(status.summary.searchable) }}</dd></div>
              <div><dt>{{ t('ui.ym_excluded') }}</dt><dd class="num">{{ num(status.summary.excluded) }}</dd></div>
            </dl>
            <div class="ym-block">
              <h3>{{ t('ui.ym_problems') }}</h3>
              <ul v-if="problems.length" class="ym-problems">
                <li v-for="g in problems" :key="g.code"><span :class="['chip', g.tone, 'num']">{{ num(g.count) }}</span>{{ g.label }}</li>
              </ul>
              <p v-else class="ym-ok"><Icon name="check" size="s16" />{{ t('ui.ym_no_problems') }}</p>
            </div>
          </template>
          <p v-else class="ym-text"><Icon name="clock" size="s16" class="ym-ic" />{{ t('ui.ym_collecting') }}</p>

          <div class="ym-block">
            <h3>{{ t('ui.ym_queries') }}<span v-if="queries" class="meta">{{ periodText(queries) }}</span></h3>
            <table v-if="queries && queries.rows.length" class="t ym-q" :aria-label="t('ui.ym_queries')">
              <thead><tr><th>{{ t('ui.ym_col_query') }}</th><th class="r">{{ t('ui.ym_col_shows') }}</th><th class="r">{{ t('ui.ym_col_clicks') }}</th></tr></thead>
              <tbody><tr v-for="q in queries.rows" :key="q.text"><td class="q">{{ q.text }}</td><td class="r num">{{ num(q.shows) }}</td><td class="r num">{{ num(q.clicks) }}</td></tr></tbody>
            </table>
            <p v-else class="ym-text">{{ t('ui.ym_collecting') }}</p>
          </div>
        </template>
      </template>
    </div>
  </section>
</template>

<style scoped>
.ym-card{padding:16px;display:flex;flex-direction:column;gap:14px}
.grow{flex:1}
.ym-bar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px 12px}
.ym-acts{display:flex;flex-wrap:wrap;gap:6px}
.ym-text{display:flex;gap:8px;align-items:flex-start;color:var(--ink-2);font-size:var(--t-sm);line-height:1.45;max-width:62ch;margin:0}
.ym-ic{flex:none;margin-top:1px}
.ym-ic.fail{color:var(--red)}
.ym-form .field{margin:0;padding:0}
.ym-form .field:hover{background:none}
.ym-form .row input{flex:1;min-width:0}
.ym-form .row .btn{flex:none}
.ym-err{color:var(--red);font-size:var(--t-sm);margin:0}
.ym-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:0}
.ym-stats>div{background:var(--sheet-2);border:1px solid var(--hair-2);border-radius:var(--r);padding:10px 12px;min-width:0}
.ym-stats dt{font-size:var(--t-xs);font-weight:620;color:var(--ink-2)}
.ym-stats dd{margin:2px 0 0;font-size:24px;font-weight:650;line-height:1.2}
.ym-block h3{font-size:var(--t-sm);font-weight:650;margin:0 0 6px;display:flex;align-items:baseline;gap:10px;flex-wrap:wrap}
.ym-problems{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:6px 16px;font-size:var(--t-sm)}
.ym-problems li{display:flex;align-items:center;gap:8px}
.ym-ok{display:flex;gap:8px;align-items:center;margin:0;font-size:var(--t-sm);color:var(--green)}
.ym-q{border:1px solid var(--hair-2);border-radius:var(--r)}
.ym-q th.r,.ym-q td.r{text-align:right;white-space:nowrap}
.ym-q td.q{overflow-wrap:anywhere}
@media (max-width:767px){
  .ym-card{padding:14px}
  .ym-stats dd{font-size:20px}
  .ym-form .row{flex-direction:column;align-items:stretch}
  .ym-q th,.ym-q td{padding-left:10px;padding-right:10px}
}
</style>
