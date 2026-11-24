<script setup>
// The address field of a record in a language that has a URL prefix: «/en» stays in front, the client edits the rest.
//   mode 'url'  — the stored value includes the prefix («/en/custom-ring», a product): the input holds «/custom-ring». An EMPTY input stores '' (never the bare «/en»,
//                 which is the language's home page); a stored bare prefix shows as an empty input
//   mode 'slug' — the stored value has none («custom-ring», an article; its page is «/en/custom-ring»): the fixed part is «/en/»
// It does what a TextField does (label, «n / max», magenta mark, error) around a prefixed input, so the SEO tab looks the same as for the main language.
import { computed } from 'vue'
import FieldShell from '@/components/form/FieldShell.vue'
import { fieldAria, fieldEmits, fieldProps } from '@/components/form/fieldProps.js'
import { pathPrefix, prefix, unprefix } from '@/utils/locales.js'
import { t } from '@/i18n/index.js'

const props = defineProps({ ...fieldProps, locale: { type: String, required: true }, mode: { type: String, default: 'url' } })
const emit = defineEmits(fieldEmits)
const id = computed(() => `adr-${props.path}`)
const fixed = computed(() => pathPrefix(props.locale) + (props.mode === 'slug' ? '/' : ''))
const shown = computed(() => {
  const v = String(props.modelValue ?? '')
  if (props.mode === 'slug' || !v) return v
  const rest = unprefix(v, props.locale)
  return rest === '/' ? '' : rest
})
const budget = computed(() => (props.field.max ? { n: shown.value.length, max: props.field.max } : null))

function onInput(e) {
  const raw = e.target.value
  if (props.mode === 'slug') { emit('update:modelValue', raw); return }
  if (!raw.trim() || raw.trim() === '/') { emit('update:modelValue', ''); return } // nothing typed yet: not «/en»
  emit('update:modelValue', prefix(raw.startsWith('/') ? raw : '/' + raw, props.locale)) // the api adds the prefix too; the field keeps what it shows
}
</script>

<template>
  <FieldShell :error-id="id + '_e'" :label="field.label" :path="path" :for-id="id" :help-id="id + '_h'" :help="field.help" :error="error" :changed="changed" :top="top" :budget="budget" @revert="emit('revert')">
    <div class="adr">
      <span class="adr-p" :title="t('collections.af_fixed', { prefix: fixed })">{{ fixed }}</span>
      <input :id="id" type="text" :value="shown" autocomplete="off" v-bind="fieldAria(id, field.help, error)" @input="onInput">
    </div>
  </FieldShell>
</template>

<style scoped>
.adr { display: flex; align-items: stretch; }
.adr-p { display: inline-flex; align-items: center; padding: 0 9px 0 10px; border: 1px solid var(--ctl); border-right: 0; border-radius: var(--r) 0 0 var(--r); background: var(--sheet-2); color: var(--ink-2); font-variant-numeric: tabular-nums; font-weight: 560; cursor: default; user-select: none; }
.adr input { flex: 1; min-width: 0; border-radius: 0 var(--r) var(--r) 0; }
</style>
