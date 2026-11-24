<script setup>
// Drawer with one request: contact data, work status, delivery to every messenger (Telegram, MAX; each with its own «Отправить ещё раз»), internal comment.
// Loads the request by id itself; tells the parent (emit 'changed') after anything that changes the list or the rail badge.
// RIGHTS: without «Править» in Заявки the status, the note and «Отправить ещё раз» are only shown.
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router'
import Drawer from '@/components/ui/Drawer.vue'
import Icon from '@/components/ui/Icon.vue'
import StatusChip from '@/components/ui/StatusChip.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import WorkChip from './WorkChip.vue'
import RequestDetails from './RequestDetails.vue'
import { byKey, schema } from '@/schema/registry.js'
import { requestNote } from './requestNote.js'
import { useConfirm } from '@/components/ui/useConfirm.js'
import { api } from '@/api/index.js'
import { can } from '@/stores/app.js'
import { useToast } from '@/components/ui/useToast.js'
import { fmtDT, fmtTime } from '@/utils/format.js'
import { isMulti } from '@/utils/locales.js'
import LangTag from '@/components/ui/LangTag.vue'
import { WORK_STATUSES, channelName, channelsOf, chipOf, commentOf, deliveryFailed, resendOutcome, sendableOf, workLabel, workOf } from '@/composables/requestMeta.js'
import { t } from '@/i18n/index.js'

const props = defineProps({ requestId: { type: Number, default: null } })
const emit = defineEmits(['close', 'changed'])
const toast = useToast()
const confirm = useConfirm()

const req = ref(null)
const loading = ref(false)
const busy = ref(false)
const sending = ref('') // '' | 'all' | a channel being sent again
const note = shallowRef(null)
let generation = 0

const canEdit = computed(() => can('requests', 'edit'))
const open = computed(() => props.requestId != null)
const work = computed(() => (req.value ? workOf(req.value) : 'new'))
const failed = computed(() => !!req.value && deliveryFailed(req.value))
const channels = computed(() => (req.value ? channelsOf(req.value) : []))
const sendable = computed(() => (req.value ? sendableOf(req.value) : []))
const form = computed(() => req.value && byKey(schema.forms, req.value.form))

async function load(id) {
  const current = ++generation
  req.value = null
  note.value = null
  busy.value = false
  sending.value = ''
  loading.value = id != null
  if (id == null) return
  try {
    const data = await api.requests.get(id)
    if (current !== generation) return
    req.value = data
    note.value = requestNote(commentOf(data), (comment) => api.requests.update(id, { comment }))
  } catch (e) {
    if (current !== generation) return
    toast.error(e.message || t('ui.rd_open_failed'))
    emit('close')
  } finally {
    if (current === generation) loading.value = false
  }
}
watch(() => props.requestId, load, { immediate: true })
onBeforeUnmount(() => { generation++ })

async function pick(status) {
  if (!req.value || busy.value || sending.value || status === work.value) return
  const current = generation
  const id = req.value.id
  busy.value = true
  try {
    const updated = await api.requests.update(id, { work: status })
    emit('changed')
    if (current !== generation) return
    req.value = updated
    toast.show(t('ui.rd_status_set', { id, status: workLabel(status) }))
  } catch (e) {
    if (current === generation) toast.error(e.message || t('ui.rd_status_failed'))
  } finally {
    if (current === generation) busy.value = false
  }
}

async function resend(channel) {
  if (!req.value || busy.value || sending.value) return
  const current = generation
  const id = req.value.id
  sending.value = channel || 'all'
  try {
    const updated = await api.requests.resend(id, channel ? { channel } : undefined)
    emit('changed')
    if (current !== generation) return
    req.value = updated
    const out = resendOutcome(updated, channel)
    if (out.state === 'queued') toast.show(t('ui.rd_queued'))
    else if (out.state === 'pending') toast.error(t('ui.rd_not_sent'))
    else if (out.state === 'failed') toast.error(out.error || t('ui.rd_resend_failed'))
    else toast.show(t('ui.rd_delivered', { id }))
  } catch (e) {
    if (current === generation) toast.error(e.message || t('ui.rd_resend_failed'))
  } finally {
    if (current === generation) sending.value = ''
  }
}

async function saveComment() {
  const session = note.value
  if (!session || !session.dirty || session.saving) return
  try {
    await session.save()
    if (note.value === session) toast.show(t('ui.rd_note_saved'))
  } catch (e) {
    if (note.value === session) toast.error(e.message || t('ui.rd_note_failed'))
  }
}

// Closing, Back and navigating to another request all go through the same guard.
async function mayLeave() {
  const session = note.value
  if (!session || (!session.dirty && !session.saving)) return true
  try {
    await session.save()
    return true
  } catch (e) {
    return confirm({
      title: t('settings.leave_title'), message: t('collections.leave_unsaved_text', { reason: e.message || t('ui.rd_note_failed') }),
      confirmLabel: t('settings.leave_confirm'), cancelLabel: t('settings.stay'), danger: true,
    })
  }
}
onBeforeRouteLeave(mayLeave)
onBeforeRouteUpdate((to, from) => to.params.id === from.params.id || mayLeave())
</script>

<template>
  <Drawer :open="open" :title="req ? t('ui.rd_title', { id: req.id }) : t('ui.rd_title_plain')" :width="480" @update:open="(v) => !v && emit('close')">
    <Skeleton v-if="loading && !req" :rows="7" />
    <template v-else-if="req">
      <div class="rd-head">
        <WorkChip :status="work" />
        <span class="muted num rd-when">{{ fmtDT(req.dt) }}</span>
        <LangTag v-if="isMulti() && req.locale" class="rd-lg" :code="req.locale" />
      </div>

      <section class="rd-sec">
        <div class="rd-h">
          {{ t('ui.rd_delivery') }}
          <span v-if="req.queuedChannels?.length" class="chip" role="status">{{ t('ui.rd_queued') }}</span>
          <StatusChip v-else :status="failed ? 'failed' : req.delivery === 'pending' ? 'pending' : 'delivered'" />
        </div>
        <div v-if="failed && !req.queuedChannels?.length" class="alert err-a"><Icon name="alert" size="s16" /><span><b>{{ t('ui.rd_failed_lead') }}</b> {{ t('ui.rd_failed_text') }}</span></div>
        <ul v-if="channels.length" class="rd-ch">
          <li v-for="c in channels" :key="c.channel">
            <div class="rd-ch-h">
              <b>{{ channelName(c.channel) }}</b>
              <span v-if="req.queuedChannels?.includes(c.channel)" class="chip" role="status">{{ t('ui.rd_queued') }}</span>
              <StatusChip v-else :status="chipOf(c.state)" />
              <button v-if="c.state === 'failed' && canEdit && !req.queuedChannels?.includes(c.channel)" type="button" :class="['btn', 'sm', 'rd-ch-b', { loading: sending === c.channel }]" :disabled="busy || !!sending" @click="resend(c.channel)"><Icon name="refresh" size="s14" />{{ t('ui.rd_resend_channel', { channel: channelName(c.channel) }) }}</button>
            </div>
          </li>
        </ul>
        <div v-if="sendable.length && canEdit" class="rd-send">
          <button v-for="c in sendable.filter((c) => !req.queuedChannels?.includes(c))" :key="c" type="button" :class="['btn', 'sm', { loading: sending === c }]" :disabled="busy || !!sending" @click="resend(c)"><Icon name="send" size="s14" />{{ t('ui.rd_send_channel', { channel: channelName(c) }) }}</button>
        </div>
        <ul class="log">
          <li v-for="(l, i) in req.log" :key="i" :class="{ f: l[0] === 'fail' }"><span class="d" /><span>{{ l[1] }}<small class="num">{{ fmtTime(l[2]) }}</small></span></li>
        </ul>
        <button v-if="failed && canEdit && !channels.length && !req.queuedChannels?.length" type="button" :class="['btn', 'primary', 'rd-resend', { loading: sending === 'all' }]" :disabled="busy || !!sending" @click="resend()"><Icon name="refresh" size="s16" />{{ t('ui.rd_resend') }}</button>
      </section>

      <RequestDetails :request="req" :form="form" />

      <section class="rd-sec">
        <div class="rd-h" id="rd-work">{{ t('ui.rd_status') }}</div>
        <div class="seg2 txt" role="group" aria-labelledby="rd-work">
          <button v-for="s in WORK_STATUSES" :key="s[0]" type="button" :aria-pressed="work === s[0]" :disabled="busy || !!sending || !canEdit" @click="pick(s[0])">{{ s[1] }}</button>
        </div>
        <p class="muted rd-hint">{{ t(canEdit ? 'ui.rd_status_hint_edit' : 'ui.rd_status_hint_view') }}</p>
      </section>

      <section class="rd-sec">
        <label class="rd-h" for="rd-note">{{ t('ui.rd_note') }}</label>
        <textarea id="rd-note" v-model="note.text" rows="3" maxlength="500" :readonly="!canEdit" :placeholder="canEdit ? t('ui.rd_note_placeholder') : t('ui.rd_note_none')" />
        <div class="rd-note-f">
          <span class="muted rd-hint">{{ t('ui.rd_note_hint') }}</span>
          <button v-if="canEdit" type="button" class="btn sm" :disabled="!note.dirty || note.saving" @click="saveComment()"><Icon name="save" size="s14" />{{ t('ui.save') }}</button>
        </div>
      </section>
    </template>
    <template #footer>
      <button type="button" class="btn" @click="emit('close')">{{ t('ui.close') }}</button>
    </template>
  </Drawer>
</template>

<style scoped>
.rd-head{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.rd-when{font-size:var(--t-sm)}
.rd-head .rd-lg{margin-left:0}
.rd-sec{display:flex;flex-direction:column;gap:8px}
.rd-h{font-weight:620;display:flex;align-items:center;gap:10px}
.rd-hint{font-size:var(--t-sm);line-height:1.4}
.rd-resend{align-self:flex-start}
.rd-send{display:flex;flex-wrap:wrap;gap:8px}
.rd-ch{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px}
.rd-ch-h{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.rd-ch-b{margin-left:auto}
.rd-note-f{display:flex;align-items:center;gap:12px;justify-content:space-between}
</style>
