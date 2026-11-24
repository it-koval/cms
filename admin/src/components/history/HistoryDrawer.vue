<script setup>
// «История» of a page, a record, a group of settings or a menu: a drawer with who changed what and when, one entry opened (what it changed, and what
// returning to it would change now), and «Вернуть эту версию». The return makes the DRAFT that version and is itself written to the history; nothing goes live by itself.
//   <HistoryDrawer v-model:open="o" kind="page" :resource-id="id" :locale="loc" :can-restore="canEdit" @restored="reload" />
// RIGHTS: the server decides; a person without «Править» only reads (canRestore=false hides the button). The page or record must be saved before opening (the parent flushes its autosave).
import { computed, ref, watch } from 'vue'
import Drawer from '@/components/ui/Drawer.vue'
import Icon from '@/components/ui/Icon.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ChangeRows from './ChangeRows.vue'
import { api } from '@/api/index.js'
import { useConfirm } from '@/components/ui/useConfirm.js'
import { useToast } from '@/components/ui/useToast.js'
import { fmtDT } from '@/utils/format.js'
import { t } from '@/i18n/index.js'
import { actionLabel, kindOf, shortSummary, whoLabel } from './historyWords.js'

const props = defineProps({
  open: { type: Boolean, default: false },
  kind: { type: String, required: true }, // page | item | settings | menu
  resourceId: { type: String, required: true },
  locale: { type: String, default: '' },
  canRestore: { type: Boolean, default: true },
})
const emit = defineEmits(['update:open', 'restored'])
const confirm = useConfirm()
const toast = useToast()

const subject = computed(() => kindOf(props.kind))
const items = ref([])
const total = ref(0)
const keep = ref(0)
const loading = ref(false)
const more = ref(false)
const error = ref('')
const opened = ref(null) // the entry on screen: { …entry, against, current }
const openBusy = ref(0) // id of the entry being opened
const restoring = ref(false)
let seq = 0

async function load({ append = false } = {}) {
  const my = ++seq
  if (append) more.value = true
  else { loading.value = true; error.value = '' }
  try {
    const r = await api.history.list(props.kind, props.resourceId, { locale: props.locale, limit: 30, offset: append ? items.value.length : 0 })
    if (my !== seq) return
    items.value = append ? [...items.value, ...r.items] : r.items
    total.value = r.total
    keep.value = r.keep
  } catch (e) {
    if (my !== seq) return
    error.value = e.message || t('ui.history_load_failed')
  } finally {
    if (my === seq) { loading.value = false; more.value = false }
  }
}
watch(() => props.open, (v) => {
  if (v) { opened.value = null; load() } else seq++
})

async function show(e) {
  if (openBusy.value) return
  openBusy.value = e.id
  try {
    opened.value = await api.history.entry(e.id)
  } catch (err) {
    toast.error(err.message || t('ui.history_open_failed'))
  } finally {
    openBusy.value = 0
  }
}

const lead = computed(() => {
  const o = opened.value
  if (!o) return ''
  return `${whoLabel(o)}, ${fmtDT(o.updatedAt)}`
})
const sameAsNow = computed(() => !!opened.value && (opened.value.current || opened.value.same))
const restorable = computed(() => !!opened.value && opened.value.action !== 'delete_locale' && opened.value.canRestore !== false)

async function restore() {
  const o = opened.value
  if (!o || !restorable.value || restoring.value) return
  const ok = await confirm({
    title: t('ui.history_restore_title'),
    message: t(`ui.history_restore_msg_${subject.value}`, { when: fmtDT(o.updatedAt) }),
    confirmLabel: t('ui.history_restore'), cancelLabel: t('ui.history_not_now'),
  })
  if (!ok) return
  restoring.value = true
  try {
    const r = await api.history.restore(o.id)
    toast.show(t('ui.history_restored'))
    emit('restored', r)
    opened.value = null
    await load()
  } catch (e) {
    toast.error(e.message || t('ui.history_restore_failed'))
  } finally {
    restoring.value = false
  }
}
</script>

<template>
  <Drawer :open="open" :title="opened ? actionLabel(opened) : t('ui.history_title')" :width="480" @update:open="emit('update:open', $event)">
    <template v-if="opened">
      <button type="button" class="btn ghost hd-back" @click="opened = null"><Icon name="chevL" size="s14" />{{ t('ui.history_back_all') }}</button>
      <p class="hd-lead">{{ lead }}</p>

      <section v-if="opened.action !== 'delete'">
        <h3 class="hd-h">{{ t('ui.history_what_changed') }}</h3>
        <ChangeRows v-if="opened.summary.length" :rows="opened.summary" />
        <p v-else class="muted hd-p">{{ t(opened.baseline ? 'ui.history_baseline' : 'ui.history_no_details') }}</p>
      </section>
      <p v-else class="hd-p">{{ t(kind === 'page' ? 'ui.history_deleted_page' : 'ui.history_deleted_item') }} {{ t(opened.inTrash ? 'ui.history_in_trash' : 'ui.history_restored_already') }}</p>

      <!-- only when restoring would change something; the current version needs no «what changes» block -->
      <section v-if="opened.action !== 'delete' && restorable && !sameAsNow">
        <h3 class="hd-h">{{ t('ui.history_what_changes') }}</h3>
        <p class="muted hd-p">{{ t('ui.history_against', { count: opened.against.length }) }}</p>
        <ChangeRows :rows="opened.against" />
      </section>
    </template>

    <template v-else>
      <div v-if="loading" aria-busy="true"><Skeleton :rows="6" /></div>
      <div v-else-if="error" class="alert err-a" role="alert"><Icon name="alert" size="s16" /><span>{{ error }}</span><button type="button" class="btn sm" @click="load()">{{ t('ui.retry') }}</button></div>
      <EmptyState v-else-if="!items.length" icon="clock" :title="t('ui.history_empty_title')" :text="t(`ui.history_empty_${subject}`)" />
      <template v-else>
        <p class="muted hd-p">{{ t('ui.history_keep', { count: keep }) }}</p>
        <ul class="hd-list">
          <li v-for="(e, i) in items" :key="e.id">
            <button type="button" :class="['hd-row', { busy: openBusy === e.id }]" :aria-label="`${actionLabel(e)}, ${whoLabel(e)}, ${fmtDT(e.updatedAt)}`" @click="show(e)">
              <span class="hd-dot" :data-a="e.action" aria-hidden="true" />
              <span class="hd-main">
                <b>{{ actionLabel(e) }}<span v-if="i === 0 && e.action !== 'delete_locale'" class="chip pub hd-now">{{ t('ui.history_now') }}</span></b>
                <span class="muted">{{ whoLabel(e) }} · {{ fmtDT(e.updatedAt) }}</span>
                <span v-if="shortSummary(e.summary)" class="hd-sum">{{ shortSummary(e.summary) }}</span>
              </span>
              <Icon name="chevR" size="s14" />
            </button>
          </li>
        </ul>
        <button v-if="items.length < total" type="button" :class="['btn', { loading: more }]" @click="load({ append: true })">{{ t('ui.show_more') }}</button>
      </template>
    </template>

    <template v-if="opened && opened.action !== 'delete'" #footer>
      <button type="button" class="btn" @click="opened = null">{{ t('ui.back') }}</button>
      <button v-if="canRestore && restorable && !sameAsNow" type="button" :class="['btn primary', { loading: restoring }]" @click="restore">{{ t('ui.history_restore') }}</button>
    </template>
  </Drawer>
</template>

<style scoped>
.hd-back { align-self: flex-start; margin: -6px 0 -6px -8px; }
.hd-lead { margin: 0; color: var(--ink-2); font-size: var(--t-sm); }
.hd-h { margin: 0 0 4px; font-size: var(--t-sm); font-weight: 650; }
.hd-p { margin: 0 0 8px; font-size: var(--t-sm); }
.hd-list { list-style: none; margin: 0; padding: 0; border-top: 1px solid var(--hair-2); }
.hd-list li { border-bottom: 1px solid var(--hair-2); }
.hd-row { display: grid; grid-template-columns: 10px 1fr 16px; gap: 12px; align-items: center; width: 100%; padding: 11px 4px; background: none; border: 0; text-align: left; cursor: pointer; font: inherit; color: inherit; border-radius: 4px; }
.hd-row:hover, .hd-row:focus-visible { background: var(--cy-50); }
.hd-row.busy { opacity: .6; }
.hd-main { display: flex; flex-direction: column; gap: 2px; min-width: 0; font-size: var(--t-sm); }
.hd-main b { font-weight: 600; }
.hd-sum { color: var(--ink-2); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.hd-now { margin-left: 8px; }
.hd-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--hair); }
.hd-dot[data-a="publish"] { background: var(--cy); }
.hd-dot[data-a="edit"], .hd-dot[data-a="create"] { background: var(--mag); }
.hd-dot[data-a="delete"] { background: var(--red); }
</style>
