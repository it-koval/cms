<script setup>
// The form engine: renders a declarative schema as a form and edits a plain object.
//
//   <SchemaForm ref="form" :schema="fields" :model-value="data" :published="publishedData" @update:model-value="data = $event" />
//
// props
//   schema      array of field definitions (schema/sections.js, schema/collections.js, schema/settings.js...). A field with form: false (hideOnForm on the server) is not drawn;
//               one with ro: true (readonly()) is shown but cannot be edited; a field the person may not see is simply not in the array. Types of plugins are drawn by their own components.
//   modelValue  the object being edited; field.k may be dotted ('seo.title')
//   published   the published version of the object, or null: fields that differ get the magenta «Изменено / Отменить изменение» mark
//   plain-required  the words of a missing required field do not talk about publishing («Поле «X» нужно заполнить»): for the modal of an action
//   Fields with `half` share a row two by two, a field with `section` gets a heading before it (components/form/FieldLayout.vue).
//   readonly    the role may look but not change: the form is inert (nothing to click or type), its buttons are not drawn, repeater items are all open
// emits update:modelValue(newObject)  (always a new object; the old one is never mutated)
// exposes focusField(path) — opens the repeater items on the way, scrolls to the field, washes it cyan for a second and focuses its control.
//   e.g. form.focusField('points.2.title')  — used by the page editor when the client clicks text on the canvas
import { computed, nextTick, provide, reactive, ref } from 'vue'
import '@/components/ui/readOnly.css'
import FieldRenderer from './fields/FieldRenderer.vue'
import FieldLayout from './FieldLayout.vue'
import { shownFields } from '@/utils/layout.js'
import { useToast } from '@/components/ui/useToast.js'
import { getPath, setIn, clone } from '@/utils/objects.js'
import { isMissing, reqMessage, valuesEqual } from '@/utils/fields.js'
import { t } from '@/i18n/index.js'

const props = defineProps({
  schema: { type: Array, required: true },
  modelValue: { type: Object, required: true },
  published: { type: Object, default: null },
  idPrefix: { type: String, default: '' },
  readonly: { type: Boolean, default: false },
  plainRequired: { type: Boolean, default: false },
  quiet: { type: Boolean, default: false }, // hide «required» errors (a brand-new record is not red until the first publish attempt)
})
const emit = defineEmits(['update:modelValue'])
const toast = useToast()

let seq = (SchemaForm_seq.n = (SchemaForm_seq.n || 0) + 1)
const formId = props.idPrefix || `sf${seq}`
const open = reactive({})
provide('formId', formId)
provide('schemaForm', { open })
provide('formReadonly', computed(() => props.readonly))

const root = ref(null)
const rows = computed(() => shownFields(props.schema).map((f) => {
  if (f.t === 'readonly') return { f, ro: true }
  const v = getPath(props.modelValue, f.k)
  return {
    f, v,
    changed: props.published ? !valuesEqual(f, v, getPath(props.published, f.k)) : false,
    error: !props.quiet && isMissing(f, v) ? (props.plainRequired ? t('forms.required_plain', { label: f.label }) : reqMessage(f)) : '',
  }
}))

const set = (k, v) => emit('update:modelValue', setIn(props.modelValue, k, v))
function revert(f) {
  emit('update:modelValue', setIn(props.modelValue, f.k, clone(getPath(props.published, f.k))))
  toast.show(t('forms.reverted'))
}

const FOCUSABLE = 'input:not([type=checkbox]):not([type=file]),textarea,select,[contenteditable="true"],[data-focus-target],input[type=checkbox],button'
async function focusField(path) {
  // open the repeater items on the way: "points.2.title" -> open item 2 of "points"
  let fields = props.schema
  let rest = String(path)
  let base = ''
  while (rest) {
    const f = fields.find((x) => rest === x.k || rest.startsWith(x.k + '.'))
    if (!f) break
    if (f.t === 'repeater' && rest.length > f.k.length) {
      const m = rest.slice(f.k.length + 1).match(/^(\d+)(?:\.(.*))?$/)
      if (m) {
        const list = getPath(props.modelValue, base + f.k) || []
        const it = list[Number(m[1])]
        if (it) open[base + f.k] = it._id
        base = `${base}${f.k}.${m[1]}.`
        fields = f.sub
        rest = m[2] || ''
        continue
      }
    }
    break
  }
  await nextTick()
  await nextTick()
  const el = root.value && root.value.querySelector(`[data-field-path="${CSS.escape(path)}"]`)
  if (!el) return false
  el.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })
  el.classList.add('hl')
  setTimeout(() => el.classList.remove('hl'), 1200)
  // a rich-text field: the editor itself (its toolbar buttons come first in the DOM); else the first control that is not in the label row (that row holds the «Отменить изменение» button)
  const target = el.querySelector('.rt [contenteditable="true"]') || [...el.querySelectorAll(FOCUSABLE)].find((x) => !x.closest('.lbl') || x.closest('label.sw'))
  if (target) target.focus({ preventScroll: true })
  return true
}

defineExpose({ focusField })
</script>

<script>
// per-module counter for unique DOM ids when several forms are on one screen
const SchemaForm_seq = { n: 0 }
</script>

<template>
  <div ref="root" :class="['schema-form', { 'ro-zone': readonly }]" :inert="readonly">
    <FieldLayout :items="rows" :field-of="(r) => r.f">
      <template #default="{ item: r }">
        <div :class="{ 'ro-zone': r.f.ro }" :inert="r.f.ro || undefined">
          <FieldRenderer
            :field="r.f" :path="r.f.k" :model-value="r.v" :top="!r.ro" :changed="r.changed" :error="r.error || ''"
            @update:model-value="set(r.f.k, $event)" @revert="revert(r.f)"
          />
        </div>
      </template>
    </FieldLayout>
  </div>
</template>
