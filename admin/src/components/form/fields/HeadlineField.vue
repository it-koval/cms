<script setup>
// Headline: THE editor of every heading on every page of every site (RichText mode 'headline'). Value: HTML (<br>, and <span class="grad"> where the site design has gradient text).
import { computed, inject, ref } from 'vue'
import FieldShell from '../FieldShell.vue'
import RichText from '../RichTextAsync.js'
import { domId, fieldAria, fieldEmits, fieldProps } from '../fieldProps.js'
import { budgetLength } from '@/utils/fields.js'
import { headlineMarks } from '@/utils/html.js'
import { t } from '@/i18n/index.js'

const props = defineProps(fieldProps)
const emit = defineEmits(fieldEmits)
const formId = inject('formId', 'f')
const id = computed(() => domId(formId, props.path))
const allowBreak = computed(() => props.field.brk !== false)
const budget = computed(() => (props.field.max ? { n: budgetLength(props.field, props.modelValue), max: props.field.max } : null))
const hint = computed(() => [props.field.help, headlineMarks().includes('gradient') ? t('forms.hint_gradient') : '', allowBreak.value ? t('forms.hint_break') : ''].filter(Boolean).join(' '))
const rt = ref(null)
defineExpose({ focus: () => rt.value && rt.value.focus() })
</script>

<template>
  <FieldShell :error-id="id + '_e'" :label="field.label" :path="path" :label-id="id + '_l'" :help-id="id + '_h'" :help="hint" :error="error" :changed="changed" :top="top" :budget="budget" @revert="emit('revert')">
    <RichText ref="rt" :model-value="modelValue ?? ''" mode="headline" :allow-break="allowBreak" :invalid="!!error" :aria-labelledby="id + '_l'" v-bind="fieldAria(id, hint, error)" @update:model-value="emit('update:modelValue', $event)" />
  </FieldShell>
</template>
