<script setup>
// field.opts = [[value, label], ...]; field.disabled locks it
import { computed, inject } from 'vue'
import FieldShell from '../FieldShell.vue'
import { domId, fieldEmits, fieldProps } from '../fieldProps.js'

const props = defineProps(fieldProps)
const emit = defineEmits(fieldEmits)
const formId = inject('formId', 'f')
const id = computed(() => domId(formId, props.path))
</script>

<template>
  <FieldShell :label="field.label" :path="path" :for-id="id" :help-id="id + '_h'" :help="field.help" :error="error" :changed="changed" :top="top" @revert="emit('revert')">
    <select :id="id" :value="modelValue" :disabled="field.disabled || undefined" :aria-describedby="field.help ? id + '_h' : undefined" @change="emit('update:modelValue', $event.target.value)">
      <option v-for="o in field.opts" :key="o[0]" :value="o[0]">{{ o[1] }}</option>
    </select>
  </FieldShell>
</template>
