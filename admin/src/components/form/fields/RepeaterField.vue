<script setup>
// List of items, each with its own sub-fields (field.sub). Add, remove (with an «вернуть» toast), move up/down, collapse.
// With field.dup the row has a «Дублировать» button: a copy with new _id (nested ones too) goes right under the original and opens.
// One item is open at a time; the open item is kept in the SchemaForm context so the page editor can open an item by path.
import { computed, inject, nextTick, reactive } from 'vue'
import { thumbUrl } from '@/utils/media.js'
import FieldShell from '../FieldShell.vue'
import FieldRenderer from './FieldRenderer.vue'
import FieldLayout from '../FieldLayout.vue'
import Icon from '@/components/ui/Icon.vue'
import { useToast } from '@/components/ui/useToast.js'
import { fieldEmits, fieldProps, domId } from '../fieldProps.js'
import { defaultItem, duplicateItem, isMissing, reqMessage, repeaterSummary, repeaterThumb, repeaterTitle } from '@/utils/fields.js'
import { t } from '@/i18n/index.js'

const props = defineProps(fieldProps)
const emit = defineEmits(fieldEmits)
const toast = useToast()
const formId = inject('formId', 'f')
const ctx = inject('schemaForm', null)
const roRef = inject('formReadonly', null)
const ro = computed(() => !!(roRef && roRef.value)) // a read-only form shows every item open
const local = reactive({})
const openMap = ctx ? ctx.open : local
const id = computed(() => domId(formId, props.path))
const list = computed(() => props.modelValue || [])
const openId = computed(() => openMap[props.path] || null)
// itemTitle / itemSummary set: the new look of a row (long titles wrap, a photo as the thumbnail)
const templated = computed(() => !!((props.field.itemTitle && props.field.itemTitle.length) || (props.field.itemSummary && props.field.itemSummary.length)))
const itemKey = (it, i) => it._id || i

const commit = (arr) => emit('update:modelValue', arr)
const toggle = (it) => { openMap[props.path] = openId.value === it._id ? null : it._id }

async function add() {
  const it = defaultItem(props.field)
  commit([...list.value, it])
  openMap[props.path] = it._id
  await nextTick()
  const rows = document.querySelectorAll(`#${CSS.escape(id.value)} .rep-i`)
  const el = rows.length && rows[rows.length - 1].querySelector('select,input[type=text],textarea,[contenteditable=true]')
  if (el) el.focus()
}
function del(i) {
  const it = list.value[i]
  commit(list.value.filter((_, k) => k !== i))
  toast.show(t('forms.repeater_removed'), { undoLabel: t('forms.repeater_undo'), undo: () => { const cur = [...(props.modelValue || [])]; cur.splice(i, 0, it); commit(cur) } })
}
async function dup(i) {
  const copy = duplicateItem(list.value[i])
  const a = [...list.value]
  a.splice(i + 1, 0, copy)
  commit(a)
  openMap[props.path] = copy._id
  toast.show(t('forms.repeater_duplicated'), { undoLabel: t('forms.repeater_undo'), undo: () => commit((props.modelValue || []).filter((x) => x._id !== copy._id)) })
  await nextTick()
  const row = document.querySelectorAll(`#${CSS.escape(id.value)} .rep-i`)[i + 1]
  if (row) row.scrollIntoView({ block: 'nearest', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })
}
function move(i, dir) {
  const j = i + dir
  if (j < 0 || j >= list.value.length) return
  const a = [...list.value]
  ;[a[i], a[j]] = [a[j], a[i]]
  commit(a)
}
function setSub(i, key, v) {
  const a = [...list.value]
  a[i] = { ...a[i], [key]: v }
  commit(a)
}
const subError = (sub, it) => (isMissing(sub, it[sub.k]) ? reqMessage(sub) : '')
</script>

<template>
  <FieldShell :label="field.label" :path="path" :label-id="id + '_l'" :help="field.help" :error="error" :changed="changed" :top="top" :count="list.length" @revert="emit('revert')">
    <div :id="id" :class="['rep', { tpl: templated, dup: field.dup }]">
      <div v-for="(it, i) in list" :key="itemKey(it, i)" class="rep-i" :data-i="i">
        <div class="rep-h">
          <img v-if="repeaterThumb(field, it).has && repeaterThumb(field, it).src" class="t" :src="thumbUrl(repeaterThumb(field, it).src)" alt="">
          <span v-else-if="repeaterThumb(field, it).has" class="t" />
          <button type="button" class="tx" :aria-expanded="openId === it._id" @click="toggle(it)">
            <b>{{ repeaterTitle(field, it, i) }}</b>
            <span v-if="repeaterSummary(field, it)">{{ repeaterSummary(field, it) }}</span>
          </button>
          <span class="acts">
            <button type="button" class="btn ghost icon sm" :aria-label="t('forms.repeater_up')" :disabled="i === 0" @click="move(i, -1)"><Icon name="up" size="s14" /></button>
            <button type="button" class="btn ghost icon sm" :aria-label="t('forms.repeater_down')" :disabled="i === list.length - 1" @click="move(i, 1)"><Icon name="down" size="s14" /></button>
            <button v-if="field.dup" type="button" class="btn ghost icon sm" :aria-label="t('forms.repeater_duplicate')" :title="t('forms.repeater_duplicate')" @click="dup(i)"><Icon name="copy" size="s14" /></button>
            <button type="button" class="btn ghost icon sm" :aria-label="t('forms.delete')" @click="del(i)"><Icon name="trash" size="s14" /></button>
          </span>
        </div>
        <div v-if="ro || openId === it._id" class="rep-b">
          <FieldLayout :items="field.sub">
            <template #default="{ item: sub }">
              <FieldRenderer
                :field="sub" :path="`${path}.${i}.${sub.k}`" :model-value="it[sub.k]"
                :error="subError(sub, it)" @update:model-value="setSub(i, sub.k, $event)"
              />
            </template>
          </FieldLayout>
        </div>
      </div>
      <div v-if="!list.length" class="ro"><Icon name="info" size="s16" /><span>{{ t('forms.repeater_empty') }}</span></div>
      <button type="button" class="btn sm rep-add" data-focus-target @click="add"><Icon name="plus" size="s14" />{{ field.addLabel || t('forms.repeater_add') }}</button>
    </div>
  </FieldShell>
</template>
