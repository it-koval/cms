<script setup>
// Drawer with one request: contact data, work status, Telegram delivery (with «Отправить ещё раз»), internal comment.
// Loads the request by id itself; tells the parent (emit 'changed') after anything that changes the list or the rail badge.
// RIGHTS: without «Править» in Заявки the status, the note and «Отправить ещё раз» are only shown.
import { computed, ref, watch } from 'vue'
import Drawer from '@/components/ui/Drawer.vue'
import Icon from '@/components/ui/Icon.vue'
import StatusChip from '@/components/ui/StatusChip.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import WorkChip from './WorkChip.vue'
import { api } from '@/api/index.js'
import { can } from '@/stores/app.js'
import { useToast } from '@/components/ui/useToast.js'
import { fmtDT, fmtTime } from '@/utils/format.js'
import { isMulti, langName } from '@/utils/locales.js'
import LangTag from '@/components/ui/LangTag.vue'
import { WORK_STATUSES, commentOf, deliveryFailed, workLabel, workOf } from '@/composables/requestMeta.js'
import { t } from '@/i18n/index.js'

const props = defineProps({ requestId: { type: Number, default: null } })
const emit = defineEmits(['close', 'changed'])
const toast = useToast()

const req = ref(null)
const loading = ref(false)
const busy = ref(false)
const sending = ref(false)
const comment = ref('')
const savedComment = ref('')

const canEdit = computed(() => can('requests', 'edit'))
const open = computed(() => props.requestId != null)
const work = computed(() => (req.value ? workOf(req.value) : 'new'))
const failed = computed(() => !!req.value && deliveryFailed(req.value))
const tel = computed(() => (req.value ? req.value.phone.replace(/[^\d+]/g, '') : ''))
const commentDirty = computed(() => comment.value.trim() !== savedComment.value.trim())

async function load() {
  if (props.requestId == null) { req.value = null; return }
  loading.value = true
  try {
    req.value = await api.requests.get(props.requestId)
    comment.value = savedComment.value = commentOf(req.value)
  } catch (e) {
    toast.error(e.message || t('ui.rd_open_failed'))
    req.value = null
    emit('close')
  } finally {
    loading.value = false
  }
}
watch(() => props.requestId, load, { immediate: true })

async function pick(status) {
  if (!req.value || busy.value || status === work.value) return
  busy.value = true
  try {
    req.value = await api.requests.update(req.value.id, { work: status })
    emit('changed')
    toast.show(t('ui.rd_status_set', { id: req.value.id, status: workLabel(status) }))
  } catch (e) {
    toast.error(e.message || t('ui.rd_status_failed'))
  } finally {
    busy.value = false
  }
}

async function resend() {
  if (!req.value || sending.value) return
  sending.value = true
  try {
    req.value = await api.requests.resend(req.value.id) // delivery only: the work status stays as the staff left it
    emit('changed')
    if (req.value.delivery === 'pending') toast.error(t('ui.rd_telegram_off'))
    else toast.show(t('ui.rd_delivered', { id: req.value.id }))
  } catch (e) {
    toast.error(e.message || t('ui.rd_resend_failed'))
  } finally {
    sending.value = false
  }
}

async function saveComment() {
  if (!req.value || !commentDirty.value) return
  const id = req.value.id
  const text = comment.value.trim()
  try {
    await api.requests.update(id, { comment: text })
    if (req.value && req.value.id === id) { req.value = { ...req.value, comment: text }; savedComment.value = comment.value = text }
    toast.show(t('ui.rd_note_saved'))
  } catch (e) {
    toast.error(e.message || t('ui.rd_note_failed'))
  }
}
// a note typed and not saved must not be lost when the drawer closes
function onClose() {
  saveComment()
  emit('close')
}
</script>

<template>
  <Drawer :open="open" :title="req ? t('ui.rd_title', { id: req.id }) : t('ui.rd_title_plain')" :width="480" @update:open="(v) => !v && onClose()">
    <Skeleton v-if="loading && !req" :rows="7" />
    <template v-else-if="req">
      <div class="rd-head">
        <WorkChip :status="work" />
        <span class="muted num rd-when">{{ fmtDT(req.dt) }}</span>
        <LangTag v-if="isMulti() && req.locale" class="rd-lg" :code="req.locale" />
      </div>

      <a class="btn primary rd-call" :href="'tel:' + tel"><Icon name="phone" />{{ t('ui.rd_call', { phone: req.phone }) }}</a>

      <dl class="kv">
        <dt>{{ t('ui.rd_number') }}</dt><dd class="num">{{ t('ui.rq_number', { id: req.id }) }}</dd>
        <dt>{{ t('ui.rd_phone') }}</dt><dd class="num">{{ req.phone }}</dd>
        <dt>{{ t('ui.rd_city') }}</dt><dd>{{ req.city }}</dd>
        <dt>{{ t('ui.rd_form') }}</dt><dd>{{ req.place }}</dd>
        <template v-if="isMulti() && req.locale"><dt>{{ t('ui.rd_site_language') }}</dt><dd>{{ langName(req.locale) }}</dd></template>
        <dt>{{ t('ui.rd_message') }}</dt><dd><template v-if="req.note">{{ req.note }}</template><span v-else class="muted">{{ t('ui.rd_no_message') }}</span></dd>
      </dl>

      <section class="rd-sec">
        <div class="rd-h" id="rd-work">{{ t('ui.rd_status') }}</div>
        <div class="seg2 txt" role="group" aria-labelledby="rd-work">
          <button v-for="s in WORK_STATUSES" :key="s[0]" type="button" :aria-pressed="work === s[0]" :disabled="busy || !canEdit" @click="pick(s[0])">{{ s[1] }}</button>
        </div>
        <p class="muted rd-hint">{{ t(canEdit ? 'ui.rd_status_hint_edit' : 'ui.rd_status_hint_view') }}</p>
      </section>

      <section class="rd-sec">
        <div class="rd-h">
          {{ t('ui.rd_delivery') }}
          <StatusChip :status="failed ? 'failed' : req.delivery === 'pending' ? 'pending' : 'delivered'" />
        </div>
        <div v-if="failed" class="alert err-a"><Icon name="alert" size="s16" /><span><b>{{ t('ui.rd_failed_lead') }}</b> {{ t('ui.rd_failed_text') }}</span></div>
        <ul class="log">
          <li v-for="(l, i) in req.log" :key="i" :class="{ f: l[0] === 'fail' }"><span class="d" /><span>{{ l[1] }}<small class="num">{{ fmtTime(l[2]) }}</small></span></li>
        </ul>
        <button v-if="failed && canEdit" type="button" :class="['btn', 'primary', 'rd-resend', { loading: sending }]" @click="resend"><Icon name="refresh" size="s16" />{{ t('ui.rd_resend') }}</button>
      </section>

      <section class="rd-sec">
        <label class="rd-h" for="rd-note">{{ t('ui.rd_note') }}</label>
        <textarea id="rd-note" v-model="comment" rows="3" maxlength="500" :readonly="!canEdit" :placeholder="canEdit ? t('ui.rd_note_placeholder') : t('ui.rd_note_none')" />
        <div class="rd-note-f">
          <span class="muted rd-hint">{{ t('ui.rd_note_hint') }}</span>
          <button v-if="canEdit" type="button" class="btn sm" :disabled="!commentDirty" @click="saveComment()"><Icon name="save" size="s14" />{{ t('ui.save') }}</button>
        </div>
      </section>
    </template>
    <template #footer>
      <button type="button" class="btn" @click="onClose">{{ t('ui.close') }}</button>
    </template>
  </Drawer>
</template>

<style scoped>
.rd-head{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.rd-when{font-size:var(--t-sm)}
.rd-head .rd-lg{margin-left:0}
.rd-call{align-self:flex-start;height:40px}
@media (max-width:767px){.rd-call{height:44px;flex:none}}
.rd-sec{display:flex;flex-direction:column;gap:8px}
.rd-h{font-weight:620;display:flex;align-items:center;gap:10px}
.rd-hint{font-size:var(--t-sm);line-height:1.4}
.rd-resend{align-self:flex-start}
.rd-note-f{display:flex;align-items:center;gap:12px;justify-content:space-between}
</style>
