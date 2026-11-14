<script setup>
import { computed, inject } from 'vue'
import FieldShell from '../FieldShell.vue'
import { domId, fieldEmits, fieldProps } from '../fieldProps.js'

const props = defineProps(fieldProps)
const emit = defineEmits(fieldEmits)
const formId = inject('formId', 'f')
const id = computed(() => domId(formId, props.path))
// "" stays "" (empty number), otherwise a Number
const onInput = (e) => emit('update:modelValue', e.target.value === '' ? '' : Number(e.target.value))
</script>

<template>
  <FieldShell :label="field.label" :path="path" :for-id="id" :help-id="id + '_h'" :help="field.help" :error="error" :changed="changed" :top="top" @revert="emit('revert')">
    <input :id="id" type="number" inputmode="numeric" :value="modelValue ?? ''" style="max-width:180px" :aria-describedby="field.help ? id + '_h' : undefined" @input="onInput">
  </FieldShell>
</template>
