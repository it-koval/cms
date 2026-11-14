<script setup>
// Route /health — «Разработчику → Состояние сайта». Everything comes from GET health (api.health.report); the person needs the right `dev`
// («Смотреть» to look, «Править» to repeat or drop a failed task, to send a request again and to clear the page cache). Blocks: the queue and its failed tasks, requests that
// were not delivered, the schedule, disk space, versions and updates, the page cache, and the checks of the core and of the extensions.
import { computed, onMounted, ref } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import { api } from '@/api/index.js'
import { can } from '@/stores/app.js'
import { useToast } from '@/components/ui/useToast.js'
import { useConfirm } from '@/components/ui/useConfirm.js'
import { fmtDT } from '@/utils/format.js'
import { checkChip } from '@/components/settings/healthChecks.js'
import { formatNumber, t } from '@/i18n/index.js'

const toast = useToast()
const confirm = useConfirm()

const data = ref(null)
const loading = ref(true)
const error = ref('')
const busy = ref('') // 'retry:7' · 'delete:7' · 'resend:12' · 'updates' · 'cache'
const canAct = computed(() => can('dev', 'edit'))

async function load({ silent = false } = {}) {
  if (!silent) loading.value = true
  error.value = ''
  try {
    data.value = await api.health.report()
  } catch (e) {
    error.value = e.message || t('settings.health_load_failed')
  } finally {
    loading.value = false
  }
}
onMounted(load)

const STATUS = {
  ok: { chip: 'pub', icon: 'check', alert: 'info-a', title: t('settings.health_ok_title'), text: t('settings.health_ok_text') },
  warn: { chip: 'warn', icon: 'warn', alert: 'warn-a', title: t('settings.health_warn_title'), text: t('settings.health_warn_text') },
  fail: { chip: 'fail', icon: 'alert', alert: 'err-a', title: t('settings.health_fail_title'), text: t('settings.health_fail_text') },
}
const status = computed(() => STATUS[data.value?.status] || STATUS.ok)

const failed = computed(() => data.value?.messenger.failed)
const waitingTotal = computed(() => (data.value?.messenger.queues || []).reduce((n, q) => n + (q.waiting || 0), 0))

function fmtBytes(n) {
  if (n == null) return '—'
  const gb = n / 1024 ** 3
  if (gb >= 1) return t('settings.health_gb', { value: formatNumber(Math.round(gb * 10) / 10) })
  return t('settings.health_mb', { value: formatNumber(Math.round(n / 1024 ** 2)) })
}
// the sentence that holds a link: the text is cut at {link}, the link stands between the halves
const requestsNote = t('settings.health_req_note').split('{link}')
const meterClass = (d) => (d.status === 'fail' ? 'fail' : d.status === 'warn' ? 'warn' : 'ok')

async function act(key, fn, done) {
  if (busy.value) return
  busy.value = key
  try {
    await fn()
    if (done) toast.show(done)
    await load({ silent: true })
  } catch (e) {
    toast.error(e.message || t('settings.health_action_failed'))
    await load({ silent: true })
  } finally {
    busy.value = ''
  }
}
const retry = (m) => act('retry:' + m.id, () => api.health.retry(m.id), t('settings.health_retried'))
async function drop(m) {
  const ok = await confirm({
    title: t('settings.health_drop_title'),
    message: t('settings.health_drop_message', { label: m.label }),
    confirmLabel: t('settings.delete'), cancelLabel: t('settings.health_drop_cancel'), danger: true,
  })
  if (ok) await act('delete:' + m.id, () => api.health.remove(m.id), t('settings.health_dropped'))
}
async function resend(r) {
  if (busy.value) return
  busy.value = 'resend:' + r.id
  try {
    const res = await api.health.resendRequest(r.id)
    if (res.delivery === 'sent') toast.show(t('settings.health_resent', { id: r.id }))
    else if (res.delivery === 'pending') toast.error(t('settings.health_resend_pending'))
    else toast.error(res.error || t('settings.health_resend_failed'))
  } catch (e) {
    toast.error(e.message || t('settings.health_resend_failed'))
  } finally {
    busy.value = ''
    await load({ silent: true })
  }
}
const clearCache = () => act('cache', () => api.health.clearPageCache(), t('settings.health_cache_cleared'))
async function checkUpdates() {
  if (busy.value) return
  busy.value = 'updates'
  try {
    const res = await api.health.refreshUpdates()
    data.value = { ...data.value, updates: res.updates }
  } catch (e) {
    toast.error(e.message || t('settings.health_updates_failed'))
  } finally {
    busy.value = ''
  }
}
</script>

<template>
  <div class="page">
    <div class="page-h">
      <div>
        <h1>{{ t('settings.health_title') }}</h1>
        <p class="sub">{{ t('settings.health_sub') }}</p>
      </div>
      <div class="actions">
        <span v-if="data" class="muted hl-time">{{ t('settings.health_generated', { time: fmtDT(data.generatedAt) }) }}</span>
        <button type="button" :class="['btn', { loading }]" @click="load()"><Icon name="refresh" size="s14" />{{ t('settings.health_refresh') }}</button>
      </div>
    </div>

    <div v-if="loading && !data" class="sheet hl-pad" aria-busy="true"><Skeleton :rows="8" /></div>
    <div v-else-if="error && !data" class="alert err-a" role="alert"><Icon name="alert" size="s16" /><span>{{ error }}</span><button type="button" class="btn sm" @click="load()">{{ t('settings.retry') }}</button></div>

    <template v-else-if="data">
      <div :class="['alert hl-top', status.alert]" role="status">
        <Icon :name="status.icon" size="s16" />
        <span><b>{{ status.title }}.</b> {{ status.text }}</span>
      </div>

      <section class="sheet hl-block" aria-labelledby="hl-queue">
        <header class="hl-h">
          <h2 id="hl-queue">{{ t('settings.health_queue_title') }}</h2>
          <span v-if="failed.available" :class="['chip', failed.total ? 'fail' : 'pub']">{{ failed.total ? t('settings.health_failed_count', { total: failed.total }) : t('settings.health_none_failed') }}</span>
        </header>
        <p class="hl-note">{{ t('settings.health_queue_note') }}</p>
        <ul v-if="data.messenger.queues.length" class="hl-queues">
          <li v-for="q in data.messenger.queues" :key="q.name">
            <code>{{ q.name }}</code>
            <span v-if="q.error" class="hl-qerr">{{ q.error }}</span>
            <span v-else class="num">{{ q.waiting ? t('settings.health_waiting', { count: q.waiting }) : t('settings.health_nothing_waiting') }}</span>
          </li>
        </ul>
        <p v-if="waitingTotal > 20" class="hl-note hl-warn"><Icon name="warn" size="s14" />{{ t('settings.health_queue_many') }}</p>

        <div v-if="failed.error" class="alert err-a hl-in" role="alert"><Icon name="alert" size="s16" /><span>{{ failed.error }}</span></div>
        <div v-else-if="!failed.available" class="hl-note">{{ t('settings.health_failed_storage') }}</div>
        <table v-else-if="failed.items.length" class="t t-stack" :aria-label="t('settings.health_failed_table')">
          <thead><tr><th>{{ t('settings.health_col_task') }}</th><th>{{ t('settings.health_col_what') }}</th><th>{{ t('settings.health_col_tries') }}</th><th>{{ t('settings.health_col_when') }}</th><th v-if="canAct" /></tr></thead>
          <tbody>
            <tr v-for="m in failed.items" :key="m.id">
              <td class="ts-main"><b>{{ m.label }}</b></td>
              <td class="hl-err ts-line" :title="m.errorClass">{{ m.error || t('settings.health_no_reason') }}</td>
              <td class="nw num ts-sub" :data-label="t('settings.health_col_tries')">{{ m.retries }}</td>
              <td :data-label="t('settings.health_col_when')" class="nw num ts-sub">{{ m.failedAt ? fmtDT(m.failedAt) : '—' }}</td>
              <td v-if="canAct" class="nw hl-act ts-acts">
                <button type="button" :class="['btn sm', { loading: busy === 'retry:' + m.id }]" :disabled="!!busy" @click="retry(m)"><Icon name="refresh" size="s14" />{{ t('settings.retry') }}</button>
                <button type="button" :class="['btn sm danger', { loading: busy === 'delete:' + m.id }]" :disabled="!!busy" @click="drop(m)"><Icon name="trash" size="s14" />{{ t('settings.delete') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
        <EmptyState v-else icon="check" :title="t('settings.health_failed_empty_title')" :text="t('settings.health_failed_empty_text')" />
        <p v-if="failed.available && failed.total > failed.items.length" class="hl-note">{{ t('settings.health_failed_shown', { shown: failed.items.length, total: failed.total }) }}</p>
      </section>

      <section class="sheet hl-block" aria-labelledby="hl-req">
        <header class="hl-h">
          <h2 id="hl-req">{{ t('settings.health_req_title') }}</h2>
          <span :class="['chip', data.requests.failed ? 'fail' : 'pub']">{{ data.requests.failed ? t('settings.health_req_failed_count', { total: data.requests.failed }) : t('settings.health_req_all_ok') }}</span>
        </header>
        <table v-if="data.requests.items.length" class="t t-stack" :aria-label="t('settings.health_req_table')">
          <thead><tr><th>{{ t('settings.health_col_no') }}</th><th>{{ t('settings.health_col_form') }}</th><th>{{ t('settings.health_col_when') }}</th><th>{{ t('settings.health_col_error') }}</th><th v-if="canAct" /></tr></thead>
          <tbody>
            <tr v-for="r in data.requests.items" :key="r.id">
              <td class="nw num ts-top"><span class="ts-pre">{{ t('settings.health_col_no') + ' ' }}</span>{{ r.id }}</td>
              <td class="ts-main">{{ r.form }}</td>
              <td class="nw num ts-top">{{ fmtDT(r.dt) }}</td>
              <td class="hl-err ts-line">{{ r.error || t('settings.health_no_reason') }}</td>
              <td v-if="canAct" class="nw hl-act ts-acts">
                <button type="button" :class="['btn sm', { loading: busy === 'resend:' + r.id }]" :disabled="!!busy" @click="resend(r)"><Icon name="send" size="s14" />{{ t('settings.health_resend') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
        <EmptyState v-else icon="check" :title="t('settings.health_req_empty_title')" :text="t('settings.health_req_empty_text')" />
        <p v-if="data.requests.items.length" class="hl-note">
          {{ requestsNote[0] }}<router-link to="/requests?status=failed">{{ t('settings.health_req_link') }}</router-link>{{ requestsNote[1] }}
        </p>
      </section>

      <section class="sheet hl-block" aria-labelledby="hl-sched">
        <header class="hl-h"><h2 id="hl-sched">{{ t('settings.health_sched_title') }}</h2></header>
        <table v-if="data.schedules.items.length" class="t t-stack" :aria-label="t('settings.health_sched_table')">
          <thead><tr><th>{{ t('settings.health_col_schedule') }}</th><th>{{ t('settings.health_col_often') }}</th><th>{{ t('settings.health_col_next') }}</th></tr></thead>
          <tbody>
            <tr v-for="(s, i) in data.schedules.items" :key="i">
              <td class="ts-main"><b>{{ s.schedule }}</b></td>
              <td class="ts-sub">{{ s.trigger }}</td>
              <td :data-label="t('settings.health_col_next')" class="nw num ts-sub">{{ s.next ? fmtDT(s.next) : '—' }}</td>
            </tr>
          </tbody>
        </table>
        <EmptyState v-else icon="clock" :title="t('settings.health_sched_empty_title')" :text="t('settings.health_sched_empty_text')" />
      </section>

      <section class="sheet hl-block" aria-labelledby="hl-disk">
        <header class="hl-h"><h2 id="hl-disk">{{ t('settings.health_disk_title') }}</h2></header>
        <ul class="hl-disks">
          <li v-for="d in data.disk" :key="d.key">
            <div class="hl-disk-h">
              <b>{{ d.label }}</b>
              <span v-if="d.total != null" class="num muted">{{ t('settings.health_disk_free', { free: fmtBytes(d.free), total: fmtBytes(d.total) }) }}</span>
              <span v-else class="muted">{{ t('settings.health_disk_unknown') }}</span>
            </div>
            <div v-if="d.total != null" class="hl-meter" role="img" :aria-label="t('settings.health_disk_used', { percent: d.usedPct })"><span :class="meterClass(d)" :style="{ width: d.usedPct + '%' }" /></div>
            <p v-if="d.status !== 'ok' && d.total != null" class="hl-note hl-warn"><Icon name="warn" size="s14" />{{ t('settings.health_disk_low') }}</p>
          </li>
        </ul>
      </section>

      <section class="sheet hl-block" aria-labelledby="hl-ver">
        <header class="hl-h"><h2 id="hl-ver">{{ t('settings.health_versions_title') }}</h2></header>
        <dl class="hl-ver">
          <div><dt>PHP</dt><dd class="num">{{ data.versions.php }}</dd></div>
          <div><dt>Symfony</dt><dd class="num">{{ data.versions.symfony }}</dd></div>
          <div><dt>ITK CMS</dt><dd class="num">{{ data.versions.itk || t('settings.health_unknown') }}<span v-if="data.versions.itkReference" class="muted"> · {{ data.versions.itkReference }}</span></dd></div>
        </dl>
        <div class="hl-upd">
          <Icon :name="data.updates.hasUpdate ? 'upload' : 'info'" size="s16" />
          <span><b>{{ t('settings.health_updates_label') }}</b> {{ data.updates.message }}<template v-if="data.updates.checkedAt"> <span class="muted">{{ t('settings.health_checked_at', { time: fmtDT(data.updates.checkedAt) }) }}</span></template></span>
          <button v-if="data.updates.enabled && canAct" type="button" :class="['btn sm', { loading: busy === 'updates' }]" :disabled="!!busy" @click="checkUpdates">{{ t('settings.health_check_now') }}</button>
        </div>
      </section>

      <section class="sheet hl-block" aria-labelledby="hl-cache">
        <header class="hl-h"><h2 id="hl-cache">{{ t('settings.health_cache_title') }}</h2></header>
        <p class="hl-note">{{ t('settings.health_cache_note') }}</p>
        <div v-if="canAct" class="hl-in">
          <button type="button" :class="['btn sm', { loading: busy === 'cache' }]" :disabled="!!busy" @click="clearCache"><Icon name="refresh" size="s14" />{{ t('settings.health_cache_clear') }}</button>
        </div>
      </section>

      <section class="sheet hl-block" aria-labelledby="hl-checks">
        <header class="hl-h"><h2 id="hl-checks">{{ t('settings.health_checks_title') }}</h2></header>
        <ul v-if="data.checks.length" class="hl-checks">
          <li v-for="c in data.checks" :key="c.name">
            <span :class="['chip', checkChip(c.status, t)[0]]">{{ checkChip(c.status, t)[1] }}</span>
            <b>{{ c.name }}</b>
            <span class="muted">{{ c.message }}</span>
          </li>
        </ul>
        <EmptyState v-else icon="check" :title="t('settings.health_checks_empty_title')" :text="t('settings.health_checks_empty_text')" />
      </section>
    </template>
  </div>
</template>

<style scoped>
.hl-time { font-size: var(--t-sm); }
.hl-pad { padding: 14px; }
.hl-top { margin-bottom: 16px; align-items: center; }
.hl-block { margin-bottom: 16px; padding-bottom: 6px; }
.hl-h { display: flex; align-items: center; gap: 10px; padding: 14px 16px 4px; }
.hl-h h2 { font-size: var(--t-md); font-weight: 650; }
.hl-note { margin: 4px 16px 10px; color: var(--ink-2); font-size: var(--t-sm); max-width: 72ch; }
.hl-warn { display: flex; align-items: center; gap: 6px; color: var(--amber); }
.hl-in { margin: 4px 16px 12px; }
.hl-queues { list-style: none; margin: 4px 16px 10px; padding: 0; display: flex; flex-wrap: wrap; gap: 8px; }
.hl-qerr { color: var(--red); }
.hl-queues li { display: inline-flex; align-items: center; gap: 8px; padding: 4px 10px; border: 1px solid var(--hair); border-radius: var(--r); font-size: var(--t-sm); }
.hl-err { max-width: 46ch; overflow-wrap: anywhere; color: var(--ink-2); }
.hl-act { text-align: right; }
.hl-act .btn + .btn { margin-left: 6px; }
.hl-disks { list-style: none; margin: 0; padding: 4px 16px 12px; display: grid; gap: 14px; }
.hl-disk-h { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 6px; }
.hl-meter { height: 8px; border-radius: 4px; background: var(--hair-2); overflow: hidden; }
.hl-meter span { display: block; height: 100%; border-radius: 4px; background: var(--green); }
.hl-meter span.warn { background: var(--amber); }
.hl-meter span.fail { background: var(--red); }
.hl-ver { display: flex; flex-wrap: wrap; gap: 8px 32px; margin: 4px 16px 12px; }
.hl-ver dt { color: var(--ink-2); font-size: var(--t-sm); }
.hl-ver dd { margin: 0; font-weight: 620; }
.hl-upd { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 0 16px 12px; padding: 10px 12px; border-radius: var(--r); background: var(--sheet-2); font-size: var(--t-sm); }
.hl-upd .btn { margin-left: auto; }
.hl-checks { list-style: none; margin: 4px 0 8px; padding: 0; }
.hl-checks li { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 9px 16px; border-top: 1px solid var(--hair); }
.hl-checks li:first-child { border-top: 0; }
.hl-checks .chip { min-width: 78px; justify-content: center; }
</style>
