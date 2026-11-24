<script setup>
// text input; field.inputType may be 'date' | 'url' | 'tel'; field.max shows «n / max»; field.disabled locks it
import { computed, inject } from 'vue'
import FieldShell from '../FieldShell.vue'
import { domId, fieldAria, fieldEmits, fieldProps } from '../fieldProps.js'
import { budgetLength } from '@/utils/fields.js'

const props = defineProps(fieldProps)
const emit = defineEmits(fieldEmits)
const formId = inject('formId', 'f')
const id = computed(() => domId(formId, props.path))
const budget = computed(() => (props.field.max ? { n: budgetLength(props.field, props.modelValue), max: props.field.max } : null))
</script>

<template>
  <FieldShell :error-id="id + '_e'" :label="field.label" :path="path" :for-id="id" :help-id="id + '_h'" :help="field.help" :error="error" :changed="changed" :top="top" :budget="budget" @revert="emit('revert')">
    <input :id="id" :type="field.inputType || 'text'" :value="modelValue ?? ''" :disabled="field.disabled || undefined" autocomplete="off" v-bind="fieldAria(id, field.help, error)" @input="emit('update:modelValue', $event.target.value)">
  </FieldShell>
</template>
