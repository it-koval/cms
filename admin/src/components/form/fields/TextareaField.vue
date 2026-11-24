<script setup>
// textarea; with field.rich === true it is the DESCRIPTION editor (TipTap: paragraphs, bold, italic, link, lists); with field.article the blog-article editor
import { computed, inject, ref } from 'vue'
import FieldShell from '../FieldShell.vue'
import RichText from '../RichTextAsync.js'
import { domId, fieldAria, fieldEmits, fieldProps } from '../fieldProps.js'
import { budgetLength } from '@/utils/fields.js'

const props = defineProps(fieldProps)
const emit = defineEmits(fieldEmits)
const formId = inject('formId', 'f')
const id = computed(() => domId(formId, props.path))
const budget = computed(() => (props.field.max ? { n: budgetLength(props.field, props.modelValue), max: props.field.max } : null))
const rt = ref(null)
defineExpose({ focus: () => rt.value && rt.value.focus() })
</script>

<template>
  <FieldShell :error-id="id + '_e'" :label="field.label" :path="path" :for-id="field.rich ? '' : id" :label-id="id + '_l'" :help-id="id + '_h'" :help="field.help" :error="error" :changed="changed" :top="top" :budget="budget" @revert="emit('revert')">
    <RichText v-if="field.rich" ref="rt" :model-value="modelValue ?? ''" :mode="field.article ? 'article' : 'rich'" :invalid="!!error" :aria-labelledby="id + '_l'" v-bind="fieldAria(id, field.help, error)" :min-height="(field.rows || 6) * 22" @update:model-value="emit('update:modelValue', $event)" />
    <textarea v-else :id="id" :rows="field.rows || 3" :value="modelValue ?? ''" v-bind="fieldAria(id, field.help, error)" @input="emit('update:modelValue', $event.target.value)" />
  </FieldShell>
</template>
