<script setup>
import { computed, inject } from 'vue'
import FieldShell from '../FieldShell.vue'
import { domId, fieldEmits, fieldProps } from '../fieldProps.js'

const props = defineProps(fieldProps)
const emit = defineEmits(fieldEmits)
const formId = inject('formId', 'f')
const id = computed(() => domId(formId, props.path))
</script>

<template>
  <FieldShell :label="field.label" :path="path" :help-id="id + '_h'" :help="field.help" :error="error" :changed="changed" :top="top" @revert="emit('revert')">
    <template #label>
      <label class="sw"><input :id="id" type="checkbox" :checked="!!modelValue" @change="emit('update:modelValue', $event.target.checked)"><span class="tr" /><span>{{ field.label }}</span></label>
    </template>
  </FieldShell>
</template>
